#!/bin/sh
set -eu
# Host package management is deliberately left to the administrator.
if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  printf '%s\n' 'Install Docker Engine and Compose using the official instructions:' \
    'https://docs.docker.com/engine/install/' >&2
  exit 1
fi
printf '%s\n' 'Docker and Compose are available. Follow README.md to configure the application.'
