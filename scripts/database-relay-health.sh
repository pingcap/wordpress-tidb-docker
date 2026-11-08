#!/bin/sh
set -eu
# A private socket avoids reserving a TCP port or opening health-check DB sessions.
printf 'GET / HTTP/1.0\r\n\r\n' |
  socat -T 2 - UNIX-CONNECT:/tmp/relay-health.sock |
  grep -Eq '^HTTP/1\.[01] 204'
