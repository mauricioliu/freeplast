#!/usr/bin/env bash
# Scoped paired restore; never removes containers/volumes, never touches production/nginx.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/staging.sh"
if [[ $# -ne 2 || "$2" != --execute || "$1" != /root/freeplast-wordpress-backups/* ]]; then
  printf 'error: paired backup path and --execute are required\nhelp: bash restore-woo-backup.sh /root/freeplast-wordpress-backups/<stamp> --execute\n'
  exit 2
fi
BACKUP="$1"
(cd "$BACKUP"; sha256sum -c SHA256SUMS)
cd "$STACK_DIR"
wp() { docker compose run --rm -T cli wp "$@"; }
[[ "$(wp option get home 2>/dev/null)" == 'https://freeplast.mliu.site' ]] || exit 1
set -a; . ./.env; set +a
wp maintenance-mode activate
# Leave maintenance in place on failure: do not expose a half-restored database/files pair.
docker compose exec -T -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" db sh -c 'exec mariadb -uroot' < "$BACKUP/db.sql"
docker compose exec -T wordpress tar -xzf - -C /var/www/html < "$BACKUP/files.tgz"
wp cache flush
wp rewrite flush
wp maintenance-mode deactivate
printf 'status: paired backup restored; verify original catalog and requests before review\n'
