#!/usr/bin/env bash
# Run on OpenClaw after uploading the prepared release into the stack bundle.
# No provisioning, nginx changes, production host, or deletion of existing data.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/staging.sh"
cd "$STACK_DIR"
wp() { docker compose run --rm -T cli wp "$@"; }
[[ "$(wp option get home 2>/dev/null)" == 'https://freeplast.mliu.site' ]] || { echo 'Wrong target'; exit 1; }
[[ -n "${FREEPLAST_BACKUP:-}" && "$FREEPLAST_BACKUP" == /root/freeplast-wordpress-backups/* ]] || { echo 'Set FREEPLAST_BACKUP to the paired pre-migration backup'; exit 1; }
(cd "$FREEPLAST_BACKUP"; sha256sum -c SHA256SUMS)
(cd bundle; sha256sum -c WOO-SHA256SUMS)
# Contain ALL mail before Woo activation can schedule or send anything.
docker compose exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
docker compose cp bundle/freeplast-staging-mail.php wordpress:/var/www/html/wp-content/mu-plugins/freeplast-staging-mail.php
wp maintenance-mode activate
trap 'wp maintenance-mode deactivate || true' EXIT
wp plugin install /bundle/woocommerce.zip --force
wp plugin install /bundle/quotes-for-woocommerce.zip --force
wp plugin install /bundle/freeplast-woo.zip --force
wp theme install /bundle/freeplast-woo-theme.zip --force
wp plugin deactivate freeplast-catalog-quotes
wp plugin activate woocommerce quotes-for-woocommerce freeplast-woo
wp language plugin install woocommerce es_CL
wp eval-file /bundle/migrate-to-woo.php
# Permalink structures are registered on init, so flush in a NEW process after options changed.
wp rewrite flush
wp cache flush
wp plugin list --fields=name,status,version
wp theme status freeplast
printf '\nWoo migration installed. Run verification and record any pending human checks.\n'
