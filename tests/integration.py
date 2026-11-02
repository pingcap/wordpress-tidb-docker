#!/usr/bin/env python3
"""Exercise the real images with disposable TLS MySQL and TiDB fixtures."""
import json
import http.cookiejar
import os
from pathlib import Path
import secrets
import shutil
import socket
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import urllib.parse

SOURCE = Path(__file__).resolve().parents[1]
MYSQL = 'mysql:8.4@sha256:a0225d1ea6631c8bc2385dc4c2faba17bbf1834ecf369e1659a9a382bd8d5dd7'
TIDB = 'pingcap/tidb:v8.5.4@sha256:e986dadd59937fbfcbdfe7fd084421cc65988da8b795f75194b05a0292635ead'


def run(args, cwd=None, check=True, input=None):
    result = subprocess.run(args, cwd=cwd, input=input, text=True, capture_output=True)
    if check and result.returncode:
        raise RuntimeError(f'{args[0]} failed:\n{result.stdout[-4000:]}\n{result.stderr[-4000:]}')
    return result


def main():
    # Refuse to displace any existing service on the example's loopback port.
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 8443))
    with tempfile.TemporaryDirectory(prefix='wordpress-security-') as directory:
        root = Path(directory)
        shutil.copytree(SOURCE, root, dirs_exist_ok=True,
                        ignore=shutil.ignore_patterns('.git', '.env', 'secrets', '__pycache__'))
        run(['python3', 'scripts/setup.py'], cwd=root)
        project = 'wpsecurity' + secrets.token_hex(5)
        password = (root / 'secrets/db_password').read_text().strip()
        root_password = secrets.token_urlsafe(48)
        (root / '.env').write_text(f'COMPOSE_PROJECT_NAME={project}\nTIDB_HOST=db\nTIDB_PORT=3306\n'
                                  'TIDB_USER=wpfixture\nTIDB_DB_NAME=wordpresstest\nWORDPRESS_TABLE_PREFIX=wptest_\n')
        fixture = root / 'test-fixtures'
        fixture.mkdir()
        (fixture / 'root-password').write_text(root_password)
        (fixture / 'init.sql').write_text(
            'CREATE DATABASE wordpresstest;\n'
            f"CREATE USER 'wpfixture'@'%' IDENTIFIED BY '{password}' REQUIRE SSL;\n"
            "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES "
            "ON wordpresstest.* TO 'wpfixture'@'%';\n")
        for ca in ['ca', 'untrusted']:
            run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '1',
                 '-keyout', f'{ca}.key', '-out', f'{ca}.crt', '-subj', f'/CN=Disposable {ca}'], cwd=fixture)
        run(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'server.key',
             '-out', 'server.csr', '-subj', '/CN=db'], cwd=fixture)
        (fixture / 'extensions').write_text('subjectAltName=DNS:db,DNS:tidb\nextendedKeyUsage=serverAuth\n')
        (fixture / 'tidb.toml').write_text('[security]\nssl-ca="/tls/ca.crt"\n'
                                         'ssl-cert="/tls/server.crt"\nssl-key="/tls/server.key"\n')
        run(['openssl', 'x509', '-req', '-in', 'server.csr', '-CA', 'ca.crt', '-CAkey', 'ca.key',
             '-CAcreateserial', '-out', 'server.crt', '-days', '1', '-sha256', '-extfile', 'extensions'], cwd=fixture)
        for name in ['server.key', 'server.crt', 'ca.crt', 'untrusted.crt', 'root-password']:
            (fixture / name).chmod(0o444)
        with (root / 'Dockerfile').open('a') as output:
            output.write('\nFROM wordpress AS wordpress-test\nUSER 0\n'
                         'COPY test-fixtures/ca.crt /usr/local/share/ca-certificates/fixture.crt\n'
                         'RUN update-ca-certificates\nUSER 33:33\n')
        with (root / '.dockerignore').open('a') as output:
            output.write('\n!test-fixtures/\n!test-fixtures/ca.crt\n')
        override = {
            'services': {
                'wordpress': {'build': {'target': 'wordpress-test'}},
                'db': {
                    'image': MYSQL,
                    'environment': {'MYSQL_ROOT_PASSWORD_FILE': '/run/secrets/root-password'},
                    'command': ['--ssl-ca=/tls/ca.crt', '--ssl-cert=/tls/server.crt',
                                '--ssl-key=/tls/server.key', '--require-secure-transport=ON'],
                    'volumes': ['./test-fixtures/server.key:/tls/server.key:ro',
                                './test-fixtures/server.crt:/tls/server.crt:ro',
                                './test-fixtures/ca.crt:/tls/ca.crt:ro',
                                './test-fixtures/root-password:/run/secrets/root-password:ro',
                                './test-fixtures/init.sql:/docker-entrypoint-initdb.d/init.sql:ro'],
                    'networks': {'backend': {'aliases': ['wrongdb']}},
                },
                'tidb': {
                    'image': TIDB,
                    'command': ['--store=unistore', '--path=/tmp/tidb', '--host=0.0.0.0',
                                '--config=/tls/tidb.toml', '--log-level=error'],
                    'volumes': ['./test-fixtures/server.key:/tls/server.key:ro',
                                './test-fixtures/server.crt:/tls/server.crt:ro',
                                './test-fixtures/ca.crt:/tls/ca.crt:ro',
                                './test-fixtures/tidb.toml:/tls/tidb.toml:ro'],
                    'networks': ['backend'],
                },
            }
        }
        (root / 'test-compose.json').write_text(json.dumps(override))
        compose = ['docker', 'compose', '-f', 'docker-compose.yml', '-f', 'test-compose.json']

        def dc(*args, **kwargs):
            return run(compose + list(args), cwd=root, **kwargs)

        def dbsql(sql):
            return dc('exec', '-T', 'db', 'sh', '-c',
                      'MYSQL_PWD="$(cat /run/secrets/root-password)" exec mysql -uroot', input=sql)

        connection = ('require "/opt/security/settings.php"; require "/opt/security/db-connection.php"; '
                      'try { deployment_database(deployment_settings()); echo "verified\n"; } '
                      'catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }')

        def connect(*options, expect=True):
            result = dc('exec', '-T', *options, 'wordpress', 'php', '-r', connection, check=False)
            if (result.returncode == 0) != expect:
                raise AssertionError('Unexpected database connection outcome: ' + result.stdout + result.stderr)

        try:
            print('Building pinned application and proxy images...', flush=True)
            dc('build')
            dc('up', '-d')
            for attempt in range(90):
                result = dc('exec', '-T', 'wordpress', 'php', '-r', connection, check=False)
                if result.returncode == 0:
                    break
                time.sleep(2)
            else:
                raise AssertionError('Fixture did not become available: ' + result.stderr)
            print('Verified TLS database connection succeeds.', flush=True)
            connect('-e', 'TIDB_HOST=wrongdb', expect=False)
            result = dc('run', '--rm', '--no-deps', '--volume',
                        str(fixture / 'untrusted.crt') + ':/etc/ssl/certs/ca-certificates.crt:ro',
                        '--entrypoint', 'php', 'wordpress', '-r', connection, check=False)
            assert result.returncode != 0, 'Untrusted database certificate accepted'
            connect('-e', 'TIDB_USER=fixture.root', expect=False)
            connect('-e', 'TIDB_DB_NAME=test', expect=False)
            connect('-e', 'WORDPRESS_TABLE_PREFIX=wp_', expect=False)
            dbsql("GRANT SELECT ON *.* TO 'wpfixture'@'%';\n")
            connect(expect=False)
            dbsql("REVOKE SELECT ON *.* FROM 'wpfixture'@'%';\n")
            connect()
            print('Wrong hostname, untrusted CA, root/shared settings and global grants rejected.', flush=True)

            tidb_client = ('exec', '-T', 'db', 'mysql', '--host=tidb', '--port=4000',
                           '--ssl-mode=VERIFY_IDENTITY', '--ssl-ca=/tls/ca.crt', '-uroot')
            for attempt in range(60):
                result = dc(*tidb_client, input='SELECT 1;\n', check=False)
                if result.returncode == 0:
                    break
                time.sleep(2)
            else:
                raise AssertionError('TiDB fixture did not become available')
            dc(*tidb_client, input=(fixture / 'init.sql').read_text())
            connect('-e', 'TIDB_HOST=tidb', '-e', 'TIDB_PORT=4000')
            dc(*tidb_client, input="GRANT SELECT ON *.* TO 'wpfixture'@'%';\n")
            connect('-e', 'TIDB_HOST=tidb', '-e', 'TIDB_PORT=4000', expect=False)
            dc(*tidb_client, input="REVOKE SELECT ON *.* FROM 'wpfixture'@'%';\n")
            connect('-e', 'TIDB_HOST=tidb', '-e', 'TIDB_PORT=4000')

            admin_password = secrets.token_urlsafe(32)
            install = ('define("WP_INSTALLING", true); require "/opt/wordpress/wp-load.php"; '
                       'require "/opt/wordpress/wp-admin/includes/upgrade.php"; '
                       '$_SERVER["HTTP_HOST"] = "localhost:8443"; $_SERVER["HTTPS"] = "on"; '
                       f'wp_install("Test site", "fixtureadmin", "admin@example.invalid", false, "", "{admin_password}"); '
                       'update_option("home", "https://localhost:8443"); '
                       'update_option("siteurl", "https://localhost:8443");')
            installation = dc('exec', '-T', 'wordpress', 'php', '-r', install)
            dc('exec', '-T', '-e', 'TIDB_HOST=tidb', '-e', 'TIDB_PORT=4000', 'wordpress', 'php', '-r', install)
            print('TiDB verified TLS, schema-scoped grants and WordPress installation passed.', flush=True)
            dc('exec', '-T', 'wordpress', 'php', '-r',
               'require "/opt/wordpress/wp-load.php"; '
               'if (!$wpdb->db_connect(false) || !is_blog_installed() || !class_exists("FIX_WP_SLOW_QUERY")) exit(1);')
            dc('exec', '-T', 'wordpress', 'php', '-r',
               'require "/opt/wordpress/wp-load.php"; wp_set_current_user(1); '
               'if (!current_user_can("manage_options") || current_user_can("install_plugins") '
               '|| current_user_can("edit_plugins") || current_user_can("edit_themes")) exit(1);')
            for attempt in range(30):
                result = dc('cp', 'proxy:/data/caddy/pki/authorities/local/root.crt', str(fixture / 'local-ca.crt'), check=False)
                if result.returncode == 0:
                    break
                time.sleep(1)
            else:
                raise AssertionError('Proxy certificate unavailable: ' + result.stderr)
            context = ssl.create_default_context(cafile=str(fixture / 'local-ca.crt'))
            cookies = http.cookiejar.CookieJar()
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                       urllib.request.HTTPSHandler(context=context), urllib.request.HTTPCookieProcessor(cookies))

            def request(path, headers=None):
                try:
                    response = opener.open(urllib.request.Request('https://localhost:8443' + path, headers=headers or {}), timeout=10)
                except urllib.error.HTTPError as error:
                    response = error
                return response.status, response.read()

            status, body = request('/wp-login.php')
            assert status == 200 and b'user_login' in body, 'WordPress login unavailable'
            login_data = urllib.parse.urlencode({'log': 'fixtureadmin', 'pwd': admin_password,
                         'wp-submit': 'Log In', 'testcookie': '1'}).encode()
            with opener.open('https://localhost:8443/wp-login.php', data=login_data, timeout=10) as response:
                assert response.status == 200
            auth_cookies = [cookie for cookie in cookies if cookie.name.startswith('wordpress_logged_in_')]
            assert auth_cookies and all(cookie.secure and cookie.has_nonstandard_attr('HttpOnly')
                                        for cookie in auth_cookies), 'Missing protected login cookie'
            for path in ['/xmlrpc.php', '/wp-cron.php', '/wp-config.php', '/healthz.txt', '/wp-content/debug.log']:
                assert request(path)[0] == 403, 'Protected endpoint accessible: ' + path
            for service, expected_user in [('wordpress', '33'), ('proxy', '1000')]:
                assert dc('exec', '-T', service, 'id', '-u').stdout.strip() == expected_user
            assert dc('exec', '-T', 'wordpress', 'touch', '/opt/wordpress/wp-content/mu-plugins/evil.php', check=False).returncode != 0
            dc('exec', '-T', 'wordpress', 'sh', '-c',
               'printf "%s" "<?php echo 123456;" > /opt/wordpress/wp-content/uploads/probe.php')
            assert request('/wp-content/uploads/probe.php')[0] == 403, 'Uploaded PHP executable'
            token = 'SENSITIVELOGMARKER' + secrets.token_hex(12)
            request('/wp-login.php?key=' + token, {'Cookie': 'secret=' + token, 'User-Agent': token})
            failed_login = dc('exec', '-T', 'wordpress', 'php', '-r',
               'require "/opt/wordpress/wp-load.php"; do_action("wp_login_failed", "' + token + '");')
            failed_query = dc('exec', '-T', 'wordpress', 'php', '-r',
               'require "/opt/wordpress/wp-load.php"; $wpdb->query("SELECT * FROM nonexistent_' + token + '");')
            # Docker exec has its own stdout/stderr; it is not the container's
            # main process log stream. Inspect both paths for CLI and web events.
            logs = dc('logs', '--no-color').stdout + installation.stderr + failed_login.stderr + failed_query.stderr
            assert token not in logs and password not in logs and admin_password not in logs, 'Sensitive data appeared in logs'
            assert all(event in logs for event in ['admin_bootstrap', 'login_failure', 'login_success']), 'Missing audit events'
            config = json.loads(dc('config', '--format', 'json').stdout)
            assert not config['services']['wordpress'].get('ports')
            ports = config['services']['proxy']['ports']
            assert len(ports) == 1 and ports[0]['host_ip'] == '127.0.0.1'
            assert password not in json.dumps(config), 'Password included in rendered Compose config'
            print('Installation, reconnect, HTTPS, immutable code, uploads, audit redaction and loopback binding passed.', flush=True)
        except BaseException:
            print(dc('ps', '--all', check=False).stdout, flush=True)
            print(dc('logs', '--no-color', '--tail', '80', 'proxy', check=False).stdout, flush=True)
            raise
        finally:
            # This project and its volumes were created exclusively for this test.
            dc('down', '--volumes', '--remove-orphans', check=False)


if __name__ == '__main__':
    main()
