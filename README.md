# WordPress with TiDB

A local HTTPS WordPress deployment backed by a **dedicated TiDB database and account**. Only `https://localhost:8443` is published, on `127.0.0.1`. WordPress itself has no published port. Finish installation locally before designing a public deployment.

## Requirements

- Docker Engine and Docker Compose v2 or later, installed from [Docker's official instructions](https://docs.docker.com/engine/install/).
- Python 3.9 or later for local configuration generation.
- A TiDB endpoint with a certificate trusted by the system CA bundle and an account allowed to create a dedicated database/user.

`sh install.sh` only checks prerequisites. It does not install packages, fetch signing keys, or run commands as root.

## Configure and start

1. Clone this repository and its pinned submodule:

   ```sh
   git submodule update --init --recursive
   python3 scripts/setup.py
   ```

   Setup generates unique project, database and table-prefix names, eight independent signing keys, and a random database password. It refuses to overwrite existing settings. It never prints secrets.

2. Set `TIDB_HOST` and `TIDB_USER` in `.env`. Keep the generated database name and table prefix. Database names must contain letters/numbers only, avoiding wildcard semantics in database grants. Each installation needs a separate database and account; a prefix alone is not an isolation boundary.

3. In TiDB's administrative console, create the generated database and a dedicated account using the password in `secrets/db_password`. Grant only `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES` on that database's tables. Require SSL for that account where supported. Do not grant global privileges, roles, access to other databases, or `GRANT OPTION`. Restrict allowed source addresses to the deployment's egress addresses. The database account is used for both initial schema creation and subsequent WordPress migrations.

   The runtime rejects `root`/`*.root` identities, shared `test` databases, default `wp_` prefixes, and accounts whose effective direct grants extend beyond the configured schema. Role-based grants deliberately fail closed. Never copy a cluster administrator password into the application.

4. Build and start:

   ```sh
   docker compose config --quiet
   docker compose up --build -d
   docker compose ps
   ```

5. Export the local HTTPS certificate authority's **public certificate**:

   ```sh
   docker compose cp proxy:/data/caddy/pki/authorities/local/root.crt ./localhost-ca.crt
   ```

   Trust this certificate through your operating system/browser's normal certificate-management process. This is a deployment-specific local CA; keep its private keys in the Docker volume. Do not bypass browser certificate errors. For a CLI verification without changing a trust store:

   ```sh
   curl --cacert localhost-ca.crt https://localhost:8443/wp-admin/install.php
   ```

6. Open `https://localhost:8443` and create the WordPress administrator account. Use a unique strong password. Only local processes can reach the installer; on a shared workstation, complete setup before allowing other users access. The initial administrator creation and subsequent authentication/role events are audited.

## Security properties

- HTTPS terminates at Caddy; no HTTP port is published. The internal HTTP hop uses a private Docker network. Caddy overwrites the forwarded protocol header and WordPress forces HTTPS for administration.
- The database drop-in configures the CA bundle and peer/hostname verification before connecting. It requires a negotiated TLS cipher and validates schema-scoped grants before permitting WordPress queries. Initial connections and reconnects use the same policy. Connection failures return a generic error, without credentials or host details.
- Both services run as non-root with dropped capabilities, no-new-privileges, a read-only root filesystem, bounded CPU/memory/PIDs and rotated logs. The WordPress health check checks HTTP process readiness; it is not a database availability check.
- WordPress core, configuration and plugins are built into the image. Only uploads are writable, and Apache refuses to execute uploaded PHP. Dashboard file editing, plugin/theme installation and automatic file modifications are disabled. Updates require rebuilding and replacing the image.
- The compatibility plugin is pinned at Git commit `3c714b9145cf5e05da821266404317e1b0f68eee` and its PHP file is verified against SHA-256 `aa234d6c8fe89d1c9fea7804d9099d03c5ed4dea106015cb24131d04291fe0dc` during the build. A missing or modified submodule fails the build. There is no host source-code bind mount.
- Database passwords and signing keys are read from read-only secret mounts, never environment variables or image layers. Local Compose secrets are files, **not an encrypted secret manager**. The parent `secrets/` directory is owner-only (`0700`); its files are readable inside the non-root containers. Protect the host with disk encryption and restricted Docker access. For production, use an encrypted secret manager and equivalent mounts.
- Image versions and digests are pinned. Review and update them regularly, rebuild, test, then recreate containers. Pinning prevents unexpected changes; it does not replace security updates.

## Logs and operations

`docker compose logs` includes structured `wordpress_security` events for administrator bootstrap, successful/failed login, logout, account creation/deletion, role changes, password resets, and changes to key security options. Events contain numeric actor/target IDs and timestamps, never passwords, tokens, supplied usernames or option values. Access logs omit request URLs, query strings, cookies and headers. PHP exception arguments are suppressed.

Each service uses Docker's `local` log driver, limited to five 10 MB files. Only authorized Docker administrators should read logs. For production retention, forward logs to an access-controlled collector before container deletion; local rotation is not tamper-proof archival storage. Follow your organization's retention policy.

XML-RPC and web-triggered cron are disabled. If scheduled WordPress tasks are required, arrange a host scheduler to run this command every five minutes:

```sh
docker compose exec -T wordpress php /opt/wordpress/wp-cron.php
```

Back up the TiDB database, uploads volume, `.env`, signing keys and Caddy state using encrypted backups. Test restoration. `docker compose down` preserves named volumes. Do not remove volumes unless you intend to erase deployment data.

### Rotation

To rotate WordPress signing keys and invalidate all existing login sessions:

```sh
python3 scripts/setup.py --rotate-salts
docker compose up -d --force-recreate wordpress
```

For database credentials, create a replacement schema-scoped account, securely replace `secrets/db_password` while keeping the parent directory private, update only `TIDB_USER`, recreate WordPress, verify database access, then revoke the old account. Recreate containers after replacing a file-backed secret so Docker remounts its new inode. Investigate and rotate credentials if an older public deployment or writable plugin was compromised; changing this repository cannot revoke previously exposed credentials.

### Existing deployments

This hardening changes the original `http://localhost` setup to `https://localhost:8443`, requires a dedicated schema/account and unique table prefix, and replaces writable code with an image build. Back up first. Migrate existing tables to the dedicated schema and preserve or deliberately migrate table prefixes; changing a prefix without migrating tables makes WordPress appear uninstalled. Migrate uploads into the new project-scoped uploads volume and update WordPress `home`/`siteurl` to the HTTPS URL. Include reviewed custom plugins/themes in the Dockerfile rather than enabling dashboard writes. Validate the migration in a separate project before cutover.

### Public deployments

The shipped configuration is local-only. Public hosting requires a reviewed deployment configuration: a real domain and publicly trusted TLS, an initialized administrator account, authentication/rate limiting at the edge, restricted database egress, external audit retention, backups and monitoring. Do not merely change the host bind to `0.0.0.0`, forward port 8443, or publish the WordPress backend.

## Verification

`python3 -m unittest discover -s tests` checks setup isolation, secret handling and overwrite/rotation behaviour. `python3 tests/integration.py` builds the pinned images in an isolated temporary project and checks HTTPS, database TLS/permissions, installation, non-root/read-only execution, blocked endpoints, upload execution and log redaction. Its database and credentials are disposable test fixtures; it never connects to TiDB Cloud. GitHub Actions runs these checks for changes.
