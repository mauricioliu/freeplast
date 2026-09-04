#!/usr/bin/env bash
# Freeplast staging — bounded rollback (issue #14).
#
# Removes ONLY the resources this deployment created:
#   - the approved-hostname Nginx vhost (after nginx -t validates),
#   - the freeplast-wordpress Compose project (containers + network).
#
# Named volumes are RETAINED until the owner explicitly approves their
# deletion: pass --purge-volumes and type the confirmation phrase. The
# stack directory (.env, .secrets, bundle, deployment records) and every
# backup also stay on disk — destroying data is a separate explicit
# decision. Unrelated websites, the static proposals, other Compose
# projects, certificates and every unrelated Nginx configuration file
# are never touched.
set -euo pipefail

HOSTNAME='freeplast.mliu.site'
STACK_DIR='/opt/freeplast-wordpress'
VHOST='/etc/nginx/sites-available/freeplast.mliu.site'
ENABLED='/etc/nginx/sites-enabled/freeplast.mliu.site'

if [[ "${1:-}" == '--purge-volumes' ]]; then
  read -r -p 'Type "delete freeplast volumes" to also remove the named volumes: ' reply
  if [[ "$reply" != 'delete freeplast volumes' ]]; then
    printf 'aborted — named volumes retained\n' >&2
    exit 1
  fi
  PURGE='--volumes'
else
  PURGE=''
fi

# 1. Stop serving the hostname — only this vhost, validated before reload
if [[ -e "$ENABLED" || -e "$VHOST" ]]; then
  rm -f "$ENABLED" "$VHOST"
  nginx -t
  systemctl reload nginx
  printf 'vhost removed — %s no longer served\n' "$HOSTNAME"
else
  printf 'no vhost present for %s\n' "$HOSTNAME"
fi

# 2. Bring down only the freeplast-wordpress Compose project
if [[ -f "$STACK_DIR/compose.yaml" ]]; then
  cd "$STACK_DIR"
  if [[ -f "$STACK_DIR/.env" ]]; then
    docker compose --env-file .env down $PURGE
  else
    docker compose -f "$STACK_DIR/compose.yaml" down $PURGE
  fi
  if [[ -n "$PURGE" ]]; then
    printf 'compose project down with named volume removal\n'
  else
    printf 'compose project down — named volumes retained (re-run with --purge-volumes after owner approval)\n'
  fi
else
  printf 'no compose stack at %s\n' "$STACK_DIR"
fi

# 3. Verify the blast radius: unrelated services keep running untouched
printf '\nRemaining Compose projects:\n'
docker compose ls --all 2>/dev/null || true
if [[ -d /var/www/html/freeplast ]]; then
  printf 'static proposals present under /var/www/html/freeplast (untouched)\n'
fi
printf '\nrollback complete — only freeplast-wordpress resources were removed\n'
