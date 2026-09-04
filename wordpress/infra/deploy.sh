#!/usr/bin/env bash
# Freeplast staging — one-shot deployment (issue #14).
#
# Provisions the isolated, password-protected staging site on OpenClaw:
#   preflight (read-only, every resource must be new)
#   → server-generated secrets (openssl rand, mode-0600 files only)
#   → Compose stack (validated before it starts, health-gated)
#   → WordPress bootstrap (es_CL, America/Santiago, approved HTTPS URLs,
#     noindex, permalinks, theme + plugin)
#   → catalog synchronization with a zero-change dry-run gate
#   → Nginx vhost (prior configuration backed up, htpasswd generated,
#     nginx -t before the reload)
#   → verification through HTTPS.
#
# Secrets never enter repository files or command output: they are
# generated on the server, written into /opt/freeplast-wordpress/.env
# (0600) and /opt/freeplast-wordpress/.secrets/credentials (0400), and
# transferred to the owner through the approved secret channel.
#
# Required environment inputs (not secrets — the TLS convention and the
# administrator address are owner decisions):
#   TLS_CERT_PATH / TLS_KEY_PATH  approved certificate for the hostname
#   WORDPRESS_ADMIN_EMAIL         owner-approved administrator address
#   FREEPLAST_GOOGLE_API_KEY      optional console-restricted key
#
# Usage, as root on the OpenClaw host:
#   TLS_CERT_PATH=/…/fullchain.pem TLS_KEY_PATH=/…/privkey.pem \
#   WORDPRESS_ADMIN_EMAIL=owner@example.org \
#   bash deploy.sh /path/to/repository/wordpress
#
# Re-running against a deployed stack updates it: existing secrets are
# kept, ALLOW_EXISTING_STACK=1 downgrades the stack-resource preflight
# checks to notes, and WordPress install steps become no-ops.
set -euo pipefail
umask 077

HOSTNAME='freeplast.mliu.site'
STACK_DIR='/opt/freeplast-wordpress'
LOOPBACK_PORT='8092'
BACKUP_ROOT='/root/freeplast-wordpress-backups'
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
INFRA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(cd "${1:-$INFRA_DIR/..}" && pwd)"

die() { printf 'deploy: %s\n' "$1" >&2; exit 1; }

[[ ${EUID} -eq 0 ]] || die 'run as root on the OpenClaw host'
[[ -f "$INFRA_DIR/preflight.sh" ]] || die 'preflight.sh must sit beside deploy.sh'
[[ -f "$SRC/data/products.json" ]] || die "repository wordpress/ not found at $SRC (pass it as \$1)"

# Owner-approved inputs (never secret values)
: "${TLS_CERT_PATH:?set TLS_CERT_PATH to the approved certificate for $HOSTNAME}"
: "${TLS_KEY_PATH:?set TLS_KEY_PATH to the approved key for $HOSTNAME}"
: "${WORDPRESS_ADMIN_EMAIL:?set WORDPRESS_ADMIN_EMAIL to the owner-approved address}"
export TLS_CERT_PATH TLS_KEY_PATH

# 1. Read-only preflight — every resource must be new before we create it
if [[ -f "$STACK_DIR/.env" ]]; then
  export ALLOW_EXISTING_STACK=1
fi
mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
bash "$INFRA_DIR/preflight.sh" | tee "$BACKUP_ROOT/preflight-${STAMP}.txt"

# 2. Secrets — generated on the server, stored only in mode-0600 files
mkdir -p "$STACK_DIR"
cd "$STACK_DIR"
if [[ ! -f .env ]]; then
  {
    printf '# Freeplast staging .env — generated %s — never commit or print this file\n' "$STAMP"
    printf 'MARIADB_ROOT_PASSWORD=%s\n' "$(openssl rand -base64 24 | tr -d '\n')"
    printf 'MARIADB_DATABASE=freeplast_wp\n'
    printf 'MARIADB_USER=freeplast_wp\n'
    printf 'MARIADB_PASSWORD=%s\n' "$(openssl rand -base64 24 | tr -d '\n')"
    printf 'WORDPRESS_ADMIN_USER=freeplast-admin\n'
    printf 'WORDPRESS_ADMIN_EMAIL=%s\n' "$WORDPRESS_ADMIN_EMAIL"
    printf 'WORDPRESS_ADMIN_PASSWORD=%s\n' "$(openssl rand -base64 24 | tr -d '\n')"
    printf 'FREEPLAST_LOOPBACK_PORT=%s\n' "$LOOPBACK_PORT"
    printf 'TLS_CERT_PATH=%s\n' "$TLS_CERT_PATH"
    printf 'TLS_KEY_PATH=%s\n' "$TLS_KEY_PATH"
    printf 'BASIC_AUTH_OWNER_USER=freeplast-owner\n'
    printf 'BASIC_AUTH_OWNER_PASSWORD=%s\n' "$(openssl rand -base64 24 | tr -d '\n')"
    printf 'BASIC_AUTH_CLIENT_USER=freeplast-client\n'
    printf 'BASIC_AUTH_CLIENT_PASSWORD=%s\n' "$(openssl rand -base64 24 | tr -d '\n')"
    printf 'FREEPLAST_CQ_MAIL_MODE=suppress\n'
    printf 'FREEPLAST_CQ_MAIL_TO=\n'
    printf 'FREEPLAST_GOOGLE_API_KEY=%s\n' "${FREEPLAST_GOOGLE_API_KEY:-}"
  } > .env
  chmod 600 .env
fi
set -a
. ./.env
set +a

# 3. Deployment bundle — repeatable, repository-owned theme/plugin/catalog
install -d -m 0755 "$STACK_DIR/nginx" "$STACK_DIR/bundle/themes" "$STACK_DIR/bundle/plugins" "$STACK_DIR/bundle/catalog"
cp -a "$SRC/wp-content/themes/freeplast" "$STACK_DIR/bundle/themes/"
cp -a "$SRC/wp-content/plugins/freeplast-catalog-quotes" "$STACK_DIR/bundle/plugins/"
cp -a "$SRC/data/products.json" "$SRC/data/media" "$STACK_DIR/bundle/catalog/"
cp "$INFRA_DIR/compose.yaml" "$STACK_DIR/compose.yaml"

# 4. Compose: configuration validated before anything starts
docker compose --env-file .env config --quiet
docker compose --env-file .env up -d
for _ in $(seq 1 60); do
  docker compose --env-file .env exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
  sleep 2
done
docker compose --env-file .env exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null \
  || die 'the database did not become healthy'
for _ in $(seq 1 60); do
  curl -s --max-time 5 -o /dev/null "http://127.0.0.1:${FREEPLAST_LOOPBACK_PORT}/" && break
  sleep 2
done
curl -s --max-time 5 -o /dev/null "http://127.0.0.1:${FREEPLAST_LOOPBACK_PORT}/" \
  || die 'the origin did not answer on the loopback port'
docker compose --env-file .env ps

# 5. Theme + plugin into the persistent WordPress volume
docker compose --env-file .env run --rm cli sh -c \
  'cp -a /bundle/themes/freeplast /var/www/html/wp-content/themes/ && cp -a /bundle/plugins/freeplast-catalog-quotes /var/www/html/wp-content/plugins/'

# 6. WordPress bootstrap — secrets travel through the environment, never argv
docker compose --env-file .env run --rm \
  -e WORDPRESS_ADMIN_USER -e WORDPRESS_ADMIN_PASSWORD -e WORDPRESS_ADMIN_EMAIL \
  -e SITE_URL="https://${HOSTNAME}" \
  cli sh -c '
    wp core is-installed || wp core install --url="$SITE_URL" --title="Freeplast" \
      --admin_user="$WORDPRESS_ADMIN_USER" --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
      --admin_email="$WORDPRESS_ADMIN_EMAIL" --locale=es_CL --skip-email
    wp language core install es_CL --activate
    wp option update timezone_string America/Santiago
    wp option update blog_public 0
    wp rewrite structure "/%postname%/" --hard
    wp rewrite flush --hard
    wp plugin activate freeplast-catalog-quotes
    wp theme activate freeplast
  '

# 7. Catalog synchronization + idempotence proof (repeated dry run: zero changes)
docker compose --env-file .env run --rm cli wp freeplast catalog sync --file=/bundle/catalog/products.json
DRY="$(docker compose --env-file .env run --rm cli wp freeplast catalog sync --file=/bundle/catalog/products.json --dry-run)"
printf '%s\n' "$DRY"
grep -q 'created=0 updated=0' <<<"$DRY" || die 'the repeated catalog dry run did not report zero changes'
grep -q 'errors=0' <<<"$DRY" || die 'the repeated catalog dry run reported errors'

# 8. Nginx — prior configuration backed up BEFORE any change; nginx -t
#    validates before the reload
cp -a /etc/nginx/sites-available "/root/nginx-sites-available.pre-freeplast-${STAMP}"
OWNER_HASH="$(openssl passwd -apr1 "$BASIC_AUTH_OWNER_PASSWORD")"
CLIENT_HASH="$(openssl passwd -apr1 "$BASIC_AUTH_CLIENT_PASSWORD")"
printf '%s:%s\n%s:%s\n' \
  "$BASIC_AUTH_OWNER_USER" "$OWNER_HASH" \
  "$BASIC_AUTH_CLIENT_USER" "$CLIENT_HASH" > /opt/freeplast-wordpress/nginx/.htpasswd
chmod 0640 /opt/freeplast-wordpress/nginx/.htpasswd
chown root:www-data /opt/freeplast-wordpress/nginx/.htpasswd 2>/dev/null || true
sed -e "s|__TLS_CERT__|${TLS_CERT_PATH}|" -e "s|__TLS_KEY__|${TLS_KEY_PATH}|" \
  "$INFRA_DIR/nginx/freeplast.mliu.site.conf" > /opt/freeplast-wordpress/nginx/freeplast.mliu.site.conf
install -m 0644 /opt/freeplast-wordpress/nginx/freeplast.mliu.site.conf /etc/nginx/sites-available/freeplast.mliu.site
ln -sfn /etc/nginx/sites-available/freeplast.mliu.site /etc/nginx/sites-enabled/freeplast.mliu.site
nginx -t
systemctl reload nginx

# 9. Credentials record — mode 0400, transferred through the approved
#    secret channel; only the path is printed
install -d -m 0700 "$STACK_DIR/.secrets"
cat > "$STACK_DIR/.secrets/credentials" <<CREDENTIALS
Freeplast staging credentials — generated ${STAMP}
Transfer through the owner-approved secret channel, then keep this file
mode 0400 on the server. It never enters the repository.

WordPress administrator
  URL:      https://${HOSTNAME}/wp-admin/
  user:     ${WORDPRESS_ADMIN_USER}
  password: ${WORDPRESS_ADMIN_PASSWORD}

Basic Auth (owner review)
  user:     ${BASIC_AUTH_OWNER_USER}
  password: ${BASIC_AUTH_OWNER_PASSWORD}

Basic Auth (client review)
  user:     ${BASIC_AUTH_CLIENT_USER}
  password: ${BASIC_AUTH_CLIENT_PASSWORD}

MariaDB
  database: ${MARIADB_DATABASE}
  user:     ${MARIADB_USER}
  password: ${MARIADB_PASSWORD}
  root password: ${STACK_DIR}/.env only

Staging mail mode: ${FREEPLAST_CQ_MAIL_MODE} (no delivery until the owner
approves test recipients; see DEPLOYMENT.md)
CREDENTIALS
chmod 400 "$STACK_DIR/.secrets/credentials"

# 10. Deployment record (digests, state — no secrets)
{
  printf 'Freeplast staging deployment record — %s\n' "$STAMP"
  printf 'images:\n'
  docker image inspect --format '  {{index .RepoDigests 0}}' mariadb:11.4 wordpress:7.1-php8.3-apache wordpress:cli-php8.3 2>/dev/null || true
  printf 'services:\n'
  docker compose --env-file .env ps
  printf 'migration version: '
  docker compose --env-file .env run --rm cli wp option get fp_db_version 2>/dev/null || true
} > "$STACK_DIR/deployment-record-${STAMP}.txt"
printf 'deployment record: %s/deployment-record-%s.txt\n' "$STACK_DIR" "$STAMP"
printf 'credentials: %s/.secrets/credentials (transfer through the approved secret channel)\n' "$STACK_DIR"

# 11. Verify the acceptance matrix through HTTPS
bash "$INFRA_DIR/verify.sh"
