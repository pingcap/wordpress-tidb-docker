#!/bin/sh
set -eu
fail() { printf '%s\n' 'Invalid database relay configuration.' >&2; exit 1; }
host=${TIDB_HOST:-}
port=${TIDB_PORT:-}
[ "${#host}" -le 253 ] || fail
printf '%s\n' "$host" | grep -Eq '^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$' || fail
# A literal address bypasses the isolated network's original-hostname alias.
printf '%s\n' "$host" | grep -Eq '[a-zA-Z]' || fail
case "$(printf '%s' "$host" | tr '[:upper:]' '[:lower:]')" in
  wordpress|proxy|db-relay|database-gateway) fail ;;
esac
case "$port" in ''|*[!0-9]*) fail ;; esac
[ "${#port}" -le 5 ] && [ "$port" -ge 1 ] && [ "$port" -le 65535 ] || fail
case "${1:-}" in db-relay|db-gateway) ;; *) fail ;; esac
config=/usr/local/etc/haproxy/$1.cfg
haproxy -c -q -f "$config"
exec haproxy -W -db -f "$config"
