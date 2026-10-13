#!/bin/sh
set -eu
php /opt/freeplast/verify-files.php
if ! wp core is-installed; then
  wp core install --url="$FREEPLAST_LOCAL_URL" --title='Freeplast LOCAL' --admin_user=local-admin --admin_password="$(cat /run/secrets/admin_password)" --admin_email=local-admin@example.invalid --skip-email
fi
for slug in woocommerce quotes-for-woocommerce; do
  expected=$(php -r '$d=json_decode(file_get_contents("/opt/vendor/dependencies.json"),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[1]]["version"];' "$slug")
  if wp plugin is-installed "$slug"; then
    test "$(wp plugin get "$slug" --field=version)" = "$expected" || { echo 'Installed plugin version differs from pin; reset explicitly or investigate.' >&2; exit 1; }
  else
    wp plugin install "/opt/vendor/$slug.zip"
  fi
  wp plugin activate "$slug"
done
wp plugin activate freeplast-woo
wp theme activate freeplast
wp eval-file /opt/freeplast/seed.php
wp rewrite structure '/%postname%/' --hard
