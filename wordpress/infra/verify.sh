#!/usr/bin/env bash
# Freeplast staging — post-deployment verification (issue #14).
#
# Walks the acceptance matrix through the public HTTPS surface (owner and
# client Basic Auth credentials from the stack .env) and through the
# Compose WP-CLI sidecar: redirect, authentication, every required route,
# noindex at both layers, the WordPress identity (es_CL,
# America/Santiago, approved HTTPS URLs), catalog idempotence and the
# staging notification restriction. One bounded result per check; exits
# non-zero on any mismatch. Prints no secret values.
set -euo pipefail

HOSTNAME='freeplast.mliu.site'
STACK_DIR='/opt/freeplast-wordpress'

cd "$STACK_DIR"
set -a
. ./.env
set +a

BASE="https://${HOSTNAME}"
ORIGIN="http://127.0.0.1:${FREEPLAST_LOOPBACK_PORT}"
CLI='docker compose --env-file .env run --rm cli'

failures=0
ok()   { printf '  ok      %s\n' "$1"; }
miss() { printf '  FAIL    %s\n' "$1"; failures=$((failures + 1)); }

status() { curl -s --max-time 20 -o /dev/null -w '%{http_code}' "$@"; }
wpcli()  { docker compose --env-file .env run --rm cli "$@" 2>/dev/null || true; }

printf 'Freeplast staging verification — %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'surface: %s (origin loopback %s)\n\n' "$BASE" "$ORIGIN"

# Origin is loopback-only and answering
code="$(curl -s --max-time 10 -o /dev/null -w '%{http_code}' "$ORIGIN/")"
if [[ "$code" != "000" ]]; then ok "origin answers on $ORIGIN (status $code)"; else miss "origin does not answer on $ORIGIN"; fi

# Plain HTTP redirects to HTTPS
code="$(status "http://${HOSTNAME}/")"
if [[ "$code" == "301" ]]; then ok 'plain HTTP redirects to HTTPS (301)'; else miss "plain HTTP returned $code (expected 301)"; fi

# Basic Auth: anonymous is challenged; owner and client credentials pass
code="$(status "$BASE/")"
if [[ "$code" == "401" ]]; then ok 'anonymous request is challenged (401)'; else miss "anonymous returned $code (expected 401)"; fi
code="$(status -u "$BASIC_AUTH_OWNER_USER:$BASIC_AUTH_OWNER_PASSWORD" "$BASE/")"
if [[ "$code" == "200" ]]; then ok 'owner credentials accepted (200)'; else miss "owner credentials returned $code (expected 200)"; fi
code="$(status -u "$BASIC_AUTH_CLIENT_USER:$BASIC_AUTH_CLIENT_PASSWORD" "$BASE/")"
if [[ "$code" == "200" ]]; then ok 'client credentials accepted (200)'; else miss "client credentials returned $code (expected 200)"; fi

# Every required route through HTTPS
for path in / /nosotros/ /tienda/ /contacto/ /cotizacion/ /politica-de-privacidad/ /producto/caja-cosechera-3-4/; do
  code="$(status -u "$BASIC_AUTH_OWNER_USER:$BASIC_AUTH_OWNER_PASSWORD" "$BASE$path")"
  if [[ "$code" == "200" ]]; then ok "$path → 200"; else miss "$path returned $code (expected 200)"; fi
done
code="$(status -u "$BASIC_AUTH_OWNER_USER:$BASIC_AUTH_OWNER_PASSWORD" "$BASE/wp-admin/")"
if [[ "$code" == "302" ]]; then ok '/wp-admin/ → 302 (login redirect)'; else miss "/wp-admin/ returned $code (expected 302)"; fi
code="$(status -u "$BASIC_AUTH_OWNER_USER:$BASIC_AUTH_OWNER_PASSWORD" "$BASE/wp-login.php")"
if [[ "$code" == "200" ]]; then ok '/wp-login.php → 200'; else miss "/wp-login.php returned $code (expected 200)"; fi
code="$(status -u "$BASIC_AUTH_OWNER_USER:$BASIC_AUTH_OWNER_PASSWORD" "$BASE/esta-pagina-no-existe/")"
if [[ "$code" == "404" ]]; then ok 'unknown route → 404'; else miss "unknown route returned $code (expected 404)"; fi

# Noindex at both layers
if curl -s --max-time 20 -I -u "$BASIC_AUTH_OWNER_USER:$BASIC_AUTH_OWNER_PASSWORD" "$BASE/" | grep -qi 'x-robots-tag:.*noindex'; then
  ok 'Nginx sends X-Robots-Tag noindex'
else
  miss 'X-Robots-Tag noindex header missing'
fi
blog_public="$(wpcli wp option get blog_public)"
if [[ "$blog_public" == "0" ]]; then ok 'WordPress discourages indexing (blog_public 0)'; else miss "blog_public is ${blog_public:-unset} (expected 0)"; fi

# WordPress reports the approved identity
locale="$(wpcli sh -c "wp eval 'echo get_locale();'")"
if [[ "$locale" == "es_CL" ]]; then ok 'locale es_CL'; else miss "locale is ${locale:-unset} (expected es_CL)"; fi
tz="$(wpcli wp option get timezone_string)"
if [[ "$tz" == "America/Santiago" ]]; then ok 'timezone America/Santiago'; else miss "timezone is ${tz:-unset} (expected America/Santiago)"; fi
home="$(wpcli wp option get home)"
siteurl="$(wpcli wp option get siteurl)"
if [[ "$home" == "$BASE" && "$siteurl" == "$BASE" ]]; then ok "home/siteurl report $BASE"; else miss "home=${home:-unset} siteurl=${siteurl:-unset} (expected $BASE)"; fi
plugins="$(wpcli wp plugin list --status=active --format=name)"
if grep -qx 'freeplast-catalog-quotes' <<<"$plugins"; then ok 'freeplast-catalog-quotes is active'; else miss 'freeplast-catalog-quotes is not active'; fi
theme="$(wpcli sh -c "wp eval 'echo get_stylesheet();'")"
if [[ "$theme" == "freeplast" ]]; then ok 'theme freeplast is active'; else miss "active theme is ${theme:-unset} (expected freeplast)"; fi

# Catalog idempotence on the staging host
DRY="$(wpcli sh -c 'wp freeplast catalog sync --file=/bundle/catalog/products.json --dry-run')"
if grep -q 'created=0 updated=0' <<<"$DRY" && grep -q 'errors=0' <<<"$DRY"; then
  ok 'catalog dry run reports zero changes'
else
  miss 'catalog dry run did not report zero changes'
  printf '%s\n' "$DRY"
fi

# Staging notification restriction: never the live mail mode
MODE="$(wpcli sh -c "wp eval 'echo Freeplast_CQ_Notifications::mode();'")"
if [[ -n "$MODE" && "$MODE" != "live" ]]; then
  ok "mail mode restricted: $MODE"
else
  miss "mail mode is ${MODE:-unknown} — staging must never run live delivery"
fi

# Unrelated services (compare against the preflight baseline)
printf '\nRunning Compose projects (compare against the preflight baseline):\n'
docker compose ls --all 2>/dev/null || true

printf '\n'
if (( failures > 0 )); then
  printf '%d verification check(s) failed\n' "$failures"
  exit 1
fi
printf 'verification clean — staging answers the acceptance matrix through HTTPS\n'
