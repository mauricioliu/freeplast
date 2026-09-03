#!/usr/bin/env bash
# Fetch pinned disposable-toolchain artifacts into wordpress/.tools (gitignored).
# Idempotent: existing, hash-verified artifacts are never re-downloaded.
#
#   PHP_VERSION   default 8.3.32   (static CLI build from static-php.dev)
#   WPCLI_SHA256  pinned hash of wp-cli.phar
#   WP_SHA256     pinned hash of the WordPress core tarball
#
# Requires: curl, tar, unzip, sha256sum.
set -euo pipefail

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.tools"
CACHE="$TOOLS_DIR/cache"
mkdir -p "$CACHE"

PHP_VERSION="${PHP_VERSION:-8.3.32}"
PHP_URL="https://dl.static-php.dev/static-php-cli/common/php-${PHP_VERSION}-cli-linux-x86_64.tar.gz"
PHP_SHA256="${PHP_SHA256:-d7ff2bc40846f4efed2757e5929f7469c96f672fbf33eb4bb80cd695534750fe}"

WPCLI_URL="https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
WPCLI_SHA256="${WPCLI_SHA256:-ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c}"

WP_VERSION="${WP_VERSION:-7.1}"
WP_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
WP_SHA256="${WP_SHA256:-05a5f89138f632b7329f1202f2a0553c5f7fe4daf8e4b9ca7ebae9b9466b9e86}"

SQLITE_PLUGIN_URL="https://downloads.wordpress.org/plugin/sqlite-database-integration.zip"
SQLITE_PLUGIN_SHA256="${SQLITE_PLUGIN_SHA256:-8703c196d3c666be9e60ced60cafabc726b23193179bfa99147e2d9feb38aecb}"

fetch() {
  local url="$1" dest="$2" sha="$3"
  if [[ -f "$dest" ]] && echo "$sha  $dest" | sha256sum -c --quiet >/dev/null 2>&1; then
    echo "  cached $(basename "$dest")"
    return
  fi
  echo "  downloading $(basename "$dest") …"
  curl -fsSL --retry 3 -o "$dest.part" "$url"
  echo "$sha  $dest.part" | sha256sum -c --quiet
  mv "$dest.part" "$dest"
}

echo "Fetching pinned tools into $CACHE:"
fetch "$PHP_URL"          "$CACHE/php.tar.gz"          "$PHP_SHA256"
fetch "$WPCLI_URL"        "$CACHE/wp-cli.phar"         "$WPCLI_SHA256"
fetch "$WP_URL"           "$CACHE/wordpress.tar.gz"    "$WP_SHA256"
fetch "$SQLITE_PLUGIN_URL" "$CACHE/sqlite-plugin.zip"  "$SQLITE_PLUGIN_SHA256"

if [[ ! -x "$TOOLS_DIR/php/php" ]]; then
  rm -rf "$TOOLS_DIR/php"
  mkdir -p "$TOOLS_DIR/php"
  tar -xzf "$CACHE/php.tar.gz" -C "$TOOLS_DIR/php"
fi

"$TOOLS_DIR/php/php" -v | head -1
echo "Tools ready."
