#!/usr/bin/env python3
"""Verify this checkout's prepared signing keys are mounted by its running service."""
import hashlib
import json
from pathlib import Path
import stat
import subprocess
import sys


def verify(root):
    secret_dir = root / 'secrets'
    salt_file = secret_dir / 'wp_salts.json'
    if (secret_dir.is_symlink() or salt_file.is_symlink()
            or not salt_file.is_file() or secret_dir.stat().st_mode & 0o077):
        raise RuntimeError('Signing keys require a private directory and a regular file')
    if not stat.S_ISREG(salt_file.stat().st_mode):
        raise RuntimeError('Signing keys must be a regular file')
    expected = hashlib.sha256(salt_file.read_bytes()).hexdigest()

    def run(args, input=None):
        result = subprocess.run(args, cwd=root, input=input, text=True,
                                capture_output=True, timeout=30)
        if result.returncode:
            # Docker errors can contain configuration; never echo them here.
            raise RuntimeError('Cannot verify the running service; signing-key revocation is unconfirmed')
        return result.stdout

    compose = ['docker', 'compose', '-f', str(root / 'docker-compose.yml')]
    config = json.loads(run(compose + ['config', '--format', 'json']))
    containers = run(compose + ['ps', '--all', '--quiet', 'wordpress']).split()
    if len(containers) != 1:
        raise RuntimeError('Expected exactly one WordPress container for this checkout')
    details = json.loads(run(['docker', 'inspect', containers[0]]))[0]
    labels = details['Config'].get('Labels') or {}
    if (labels.get('com.docker.compose.project') != config['name']
            or labels.get('com.docker.compose.service') != 'wordpress'
            or Path(labels.get('com.docker.compose.project.working_dir', '/')).resolve() != root.resolve()
            or not details['State']['Running']
            or details['State'].get('Health', {}).get('Status') != 'healthy'):
        raise RuntimeError('The expected WordPress service must be running and healthy')
    run(['docker', 'exec', '-i', containers[0], 'php'], input=(
        '<?php $actual = @hash_file("sha256", "/run/secrets/wp_salts.json"); '
        f'if (!is_string($actual) || !hash_equals("{expected}", $actual)) exit(1);'
    ))


def main():
    try:
        verify(Path(__file__).resolve().parent.parent)
    except (OSError, ValueError, KeyError, RuntimeError, subprocess.TimeoutExpired) as error:
        message = str(error) if isinstance(error, RuntimeError) else 'Unable to verify the running signing-key mount'
        print(message + '. No keys or services were changed.', file=sys.stderr)
        return 1
    print('Verified: running WordPress mounts the prepared signing keys. '
          'Confirm that an old browser session now requires login.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
