#!/bin/sh
set -eu
php -r 'require "/opt/security/settings.php"; try { deployment_settings(); } catch (Throwable $e) { fwrite(STDERR, "Invalid deployment settings or missing secrets. See README.md.\n"); exit(1); }'
test -s /opt/wordpress/wp-content/mu-plugins/tidb-compatibility.php
test -s /opt/wordpress/wp-content/db.php
test -s /opt/wordpress/wp-content/mu-plugins/security-audit.php
test -s /opt/security/audit.php
test -s /opt/security/login-limits.php
php -r 'require "/opt/security/security-storage.php"; try { deployment_check_security_storage(); } catch (Throwable $e) { fwrite(STDERR, "Private security storage is required.\n"); exit(1); }'
exec "$@"
