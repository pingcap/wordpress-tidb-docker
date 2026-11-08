#!/usr/bin/env python3
"""Create local configuration and secrets without printing secret material."""
import argparse
import json
import os
from pathlib import Path
import secrets

KEYS = ('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT')


def create(path, text, mode):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as output:
        output.write(text)
    path.chmod(mode)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--rotate-salts', action='store_true', help='prepare new signing keys; recreate and verify the running service separately')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    secret_dir = root / 'secrets'
    if secret_dir.is_symlink():
        parser.error('secrets must not be a symbolic link')
    secret_dir.mkdir(mode=0o700, exist_ok=True)
    secret_dir.chmod(0o700)
    salts = json.dumps({key: secrets.token_hex(64) for key in KEYS}, indent=2) + '\n'
    salt_file = secret_dir / 'wp_salts.json'
    if args.rotate_salts:
        if not salt_file.is_file() or salt_file.is_symlink():
            parser.error('initialize this deployment before rotating its signing keys')
        temp = secret_dir / ('salts-' + secrets.token_hex(8))
        try:
            create(temp, salts, 0o444)
            os.replace(temp, salt_file)
        finally:
            temp.unlink(missing_ok=True)
        print('New signing key file prepared; running sessions are NOT yet verified revoked. '
              'Recreate WordPress, then run python3 scripts/verify-salts.py.')
        return
    if any(path.exists() for path in (root / '.env', salt_file, secret_dir / 'db_password')):
        parser.error('configuration already exists; refusing to overwrite deployment data')
    suffix = secrets.token_hex(6)
    create(root / '.env', f'COMPOSE_PROJECT_NAME=wordpress-{suffix}\n'
           'TIDB_HOST=\nTIDB_PORT=4000\nTIDB_USER=\n'
           f'TIDB_DB_NAME=wordpress{suffix}\nWORDPRESS_TABLE_PREFIX=wp_{suffix}_\n', 0o600)
    # Parent directory is owner-only. Files are readable inside non-root containers;
    # Compose file-backed secrets cannot remap uid/gid permissions on Linux.
    create(secret_dir / 'db_password', secrets.token_urlsafe(48) + '\n', 0o444)
    create(salt_file, salts, 0o444)
    print('Created .env and secrets. Configure the dedicated TiDB account as described in README.md.')


if __name__ == '__main__':
    main()
