#!/usr/bin/env bash
# Freeplast staging — backup and restore rehearsal (issue #14).
#
#   1. dump the database through the db service (password via environment),
#   2. archive the whole WordPress volume through the running container,
#   3. hash both artifacts into /root/freeplast-wordpress-backups
#      (outside the live Compose volumes),
#   4. rehearse the restore into temporary project names only
#      (freeplast-wordpress-restore), verify the restored catalog count
#      with WP-CLI, then tear the rehearsal down. The live stack is
#      never touched.
#
# A backup that has not completed its restore rehearsal is not release
# evidence (OPENCLAW.md). Run before every catalog import, plugin
# migration or release, and before server-level changes.
set -euo pipefail

INFRA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Single source of the staging constants (issue #16): hostname, install
# root and loopback port are declared in staging.sh, not here.
. "$INFRA_DIR/staging.sh"

PROJECT='freeplast-wordpress'
BACKUP_ROOT='/root/freeplast-wordpress-backups'
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="$BACKUP_ROOT/$STAMP"
REHEARSAL="${PROJECT}-restore"
REHEARSAL_PORT='8093'

# Every restore-rehearsal command runs under the temporary project name —
# never the live one.
rehearsal() { docker compose --env-file .env -p "$REHEARSAL" "$@"; }

cd "$STACK_DIR"
set -a
. ./.env
set +a

mkdir -p "$DEST"
chmod 700 "$DEST"
printf 'Freeplast staging backup — %s\n' "$STAMP"

# 1. Database (secret travels through the container environment, never argv)
docker compose --env-file .env exec -T \
  -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" db \
  sh -c 'exec mariadb-dump -uroot --databases "$MARIADB_DATABASE"' > "$DEST/db.sql"

# 2. Files (the whole persistent WordPress volume)
docker compose --env-file .env exec -T wordpress tar -czf - -C /var/www/html . > "$DEST/files.tgz"

# 3. Hashes, stored outside the live volumes
sha256sum "$DEST/db.sql" "$DEST/files.tgz" > "$DEST/SHA256SUMS"
printf 'backup stored in %s\n' "$DEST"

# 4. Restore rehearsal — temporary project names only
printf 'restore rehearsal into temporary project %s (loopback %s)…\n' "$REHEARSAL" "$REHEARSAL_PORT"
FREEPLAST_LOOPBACK_PORT="$REHEARSAL_PORT" rehearsal up -d db
for _ in $(seq 1 60); do
  rehearsal exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
  sleep 2
done
rehearsal exec -T \
  -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" db \
  sh -c 'exec mariadb -uroot' < "$DEST/db.sql"
FREEPLAST_LOOPBACK_PORT="$REHEARSAL_PORT" rehearsal up -d wordpress
for _ in $(seq 1 60); do
  curl -s --max-time 5 -o /dev/null "http://127.0.0.1:${REHEARSAL_PORT}/" && break
  sleep 2
done
rehearsal exec -T wordpress sh -c 'tar -xzf - -C /var/www/html' < "$DEST/files.tgz"

LIVE_COUNT="$(docker compose --env-file .env run --rm cli wp post list --post_type=fp_product --post_status=any --format=count 2>/dev/null || true)"
RESTORED_COUNT="$(rehearsal run --rm cli wp post list --post_type=fp_product --post_status=any --format=count 2>/dev/null || true)"
if [[ -n "$LIVE_COUNT" && "$LIVE_COUNT" != "0" && "$RESTORED_COUNT" == "$LIVE_COUNT" ]]; then
  printf 'restore rehearsal verified: %s fp_product records in both stacks\n' "$LIVE_COUNT"
else
  printf 'restore rehearsal FAILED: live=%s restored=%s\n' "${LIVE_COUNT:-?}" "${RESTORED_COUNT:-?}" >&2
  rehearsal down -v --remove-orphans
  exit 1
fi

# Teardown of the rehearsal only (project-scoped volumes and containers)
rehearsal down -v --remove-orphans
printf 'rehearsal torn down — the live stack was never touched\n'
printf 'backup complete: %s (db.sql + files.tgz + SHA256SUMS)\n' "$DEST"
