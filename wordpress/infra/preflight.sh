#!/usr/bin/env bash
# Freeplast staging — read-only preflight (issue #14).
#
# Verifies that every deployment resource is NEW before any mutation:
# approved hostname, loopback port, stack directory, Compose project,
# volumes, network, TLS coverage, disk capacity, DNS and the health of the
# existing services. A collision aborts planning — it is never solved by
# adopting or deleting the conflicting resource (OPENCLAW.md).
#
# Read-only by construction: this script creates, starts, stops and
# changes nothing. deploy.sh runs it as its first step.
#
# Re-running against an already-deployed stack: export
# ALLOW_EXISTING_STACK=1 to treat the stack's own resources as expected;
# hostname/port/Nginx/TLS/DNS/health collisions stay fatal either way.
#
# TLS convention: pass TLS_CERT_PATH/TLS_KEY_PATH in the environment
# (deploy.sh does); with a generated stack present they are also read
# from /opt/freeplast-wordpress/.env.
set -euo pipefail

HOSTNAME='freeplast.mliu.site'
STACK_DIR='/opt/freeplast-wordpress'
PROJECT='freeplast-wordpress'
LOOPBACK_PORT='8092'
MIN_DISK_GB='10'

TLS_CERT="${TLS_CERT_PATH:-}"
TLS_KEY="${TLS_KEY_PATH:-}"
if [[ -z "$TLS_CERT" && -f "$STACK_DIR/.env" ]]; then
  TLS_CERT="$(sed -n 's/^TLS_CERT_PATH=//p' "$STACK_DIR/.env" | tail -n1)"
  TLS_KEY="$(sed -n 's/^TLS_KEY_PATH=//p' "$STACK_DIR/.env" | tail -n1)"
fi

failures=0
ok()   { printf '  ok      %s\n' "$1"; }
miss() { printf '  FAIL    %s\n' "$1"; failures=$((failures + 1)); }
skip() { printf '  note    %s\n' "$1"; }

printf 'Freeplast staging preflight — %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'host: %s\n' "$(hostname)"
printf 'target: %s proxied to 127.0.0.1:%s from %s\n\n' "$HOSTNAME" "$LOOPBACK_PORT" "$STACK_DIR"

# 1. Tooling
if command -v docker >/dev/null 2>&1; then
  ok "$(docker --version 2>/dev/null)"
else
  miss 'docker is not installed'
fi
if docker compose version >/dev/null 2>&1; then
  ok "$(docker compose version 2>/dev/null | head -n1)"
else
  miss 'docker compose v2 is not available'
fi

# 2. Approved hostname: no existing enabled vhost claims it
if grep -rl -- "$HOSTNAME" /etc/nginx/sites-enabled/ >/dev/null 2>&1; then
  miss "an existing enabled vhost already references $HOSTNAME"
else
  ok "no enabled vhost references $HOSTNAME"
fi

# 3. Loopback port is unbound
if ss -ltn | grep -Eq "[:.]${LOOPBACK_PORT}[[:space:]]"; then
  miss "loopback port ${LOOPBACK_PORT} is already bound"
else
  ok "loopback port ${LOOPBACK_PORT} is unbound"
fi

# 4. Stack directory is new
if [[ ! -e "$STACK_DIR" ]]; then
  ok "stack directory $STACK_DIR does not exist yet"
elif [[ "${ALLOW_EXISTING_STACK:-0}" == '1' ]]; then
  skip "stack directory $STACK_DIR exists (ALLOW_EXISTING_STACK=1 — redeploy)"
else
  miss "stack directory $STACK_DIR already exists"
fi

# 5. Compose project and container names are new
if docker ps -a --format '{{.Names}}' | grep -q "^${PROJECT}-"; then
  if [[ "${ALLOW_EXISTING_STACK:-0}" == '1' ]]; then
    skip "containers of ${PROJECT} exist (redeploy)"
  else
    miss "containers named ${PROJECT}-* already exist"
  fi
else
  ok "no ${PROJECT}-* containers exist"
fi
if docker compose ls --all --format json 2>/dev/null | grep -q "\"${PROJECT}\""; then
  if [[ "${ALLOW_EXISTING_STACK:-0}" == '1' ]]; then
    skip "compose project ${PROJECT} registered (redeploy)"
  else
    miss "compose project ${PROJECT} is already registered"
  fi
else
  ok "compose project ${PROJECT} is not registered"
fi

# 6. Named volumes are new
if docker volume ls --format '{{.Name}}' | grep -Exq "${PROJECT}_(db_data|wp_data)"; then
  if [[ "${ALLOW_EXISTING_STACK:-0}" == '1' ]]; then
    skip "named volumes ${PROJECT}_db_data / ${PROJECT}_wp_data exist (redeploy)"
  else
    miss "named volumes ${PROJECT}_db_data / ${PROJECT}_wp_data already exist"
  fi
else
  ok "named volumes ${PROJECT}_db_data / ${PROJECT}_wp_data do not exist"
fi

# 7. Dedicated network is new
if docker network ls --format '{{.Name}}' | grep -qx "${PROJECT}_freeplast"; then
  if [[ "${ALLOW_EXISTING_STACK:-0}" == '1' ]]; then
    skip "network ${PROJECT}_freeplast exists (redeploy)"
  else
    miss "network ${PROJECT}_freeplast already exists"
  fi
else
  ok "network ${PROJECT}_freeplast does not exist"
fi

# 8. Disk capacity
AVAIL_GB="$(df -BG --output=avail / | awk 'NR==2 {gsub("G", "", $1); print $1}')"
if [[ "${AVAIL_GB:-0}" -ge "$MIN_DISK_GB" ]]; then
  ok "${AVAIL_GB} GiB free on / (minimum ${MIN_DISK_GB} GiB)"
else
  miss "only ${AVAIL_GB:-unknown} GiB free on / (minimum ${MIN_DISK_GB} GiB)"
fi

# 9. DNS resolves to this host before TLS can serve the hostname
if ADDRS="$(getent ahosts "$HOSTNAME" 2>/dev/null)" && [[ -n "$ADDRS" ]]; then
  ok "$HOSTNAME resolves here: $(printf '%s\n' "$ADDRS" | awk '{print $1}' | sort -u | paste -sd, -)"
else
  miss "$HOSTNAME does not resolve — DNS must reach this host before deployment"
fi

# 10. TLS convention: readable, covers the hostname, not near expiry
if [[ -n "$TLS_CERT" && -n "$TLS_KEY" ]]; then
  if [[ -r "$TLS_CERT" ]]; then
    ok "certificate readable: $TLS_CERT"
    if openssl x509 -in "$TLS_CERT" -noout -checkend 2592000 >/dev/null 2>&1; then
      ok 'certificate valid for more than 30 days'
    else
      miss 'certificate expires within 30 days — renew through the established ACME process'
    fi
    if openssl x509 -in "$TLS_CERT" -noout -ext subjectAltName 2>/dev/null \
      | grep -Eq "DNS:(${HOSTNAME//./\\.}|\\*\\.${HOSTNAME#*.})"; then
      ok "certificate SAN covers $HOSTNAME"
    else
      miss "certificate SAN does not cover $HOSTNAME"
    fi
  else
    miss "certificate not readable: $TLS_CERT"
  fi
  if [[ -r "$TLS_KEY" ]]; then
    ok "key readable: $TLS_KEY"
  else
    miss "key not readable: $TLS_KEY"
  fi
else
  miss 'TLS_CERT_PATH / TLS_KEY_PATH not supplied (the server convention must be passed)'
fi

# 11. Existing services are healthy before any mutation
UNHEALTHY="$(docker ps --format '{{.Names}}: {{.Status}}' | grep -i 'unhealthy' || true)"
if [[ -z "$UNHEALTHY" ]]; then
  ok "$(docker ps --format '{{.Names}}' | wc -l) existing container(s) running, none unhealthy"
else
  miss 'existing unhealthy containers:'
  while IFS= read -r line; do printf '         %s\n' "$line"; done <<<"$UNHEALTHY"
fi

# 12. Baseline of everything the rollback must leave untouched
printf '\nBaseline (unrelated services must stay exactly like this):\n'
docker compose ls --all 2>/dev/null || true
find /etc/nginx/sites-enabled -maxdepth 1 -type l -printf '%f -> %l\n' 2>/dev/null | sort
if [[ -d /var/www/html/freeplast ]]; then
  printf 'static proposals present under /var/www/html/freeplast\n'
fi

printf '\n'
if (( failures > 0 )); then
  printf '%d preflight check(s) failed — choose NEW names/ports/paths; never adopt or delete the conflicting resource\n' "$failures"
  exit 1
fi
printf 'preflight clean — every target resource is new\n'
