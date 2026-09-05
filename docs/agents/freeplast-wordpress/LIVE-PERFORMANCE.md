# Freeplast — live `freeplast.cl` performance diagnosis & fixes

Interim remediation for the current production WooCommerce site. This doc is about the live
site only — it is **bootstrap provenance** per `CONTEXT.md`, not the staged rebuild. The
staged rebuild ([RUNBOOK.md](RUNBOOK.md)) replaces it at cutover and is the long-term fix;
these fixes keep the live site healthy in the meantime and do not touch the new build.

Everything here was measured over public HTTP on **2026-09-01**. No credentials were used and
nothing was changed on the server.

## Root cause — one line

The site runs WooCommerce + Elementor + Essential Addons on shared Apache hosting with
**WP Super Cache effectively caching only the front page**, so every page a customer actually
visits (products, categories, contact) is rendered from scratch in **4–5 s of PHP time**, and
the HTTP→HTTPS redirect adds another **3–4 s** because WordPress (not Apache) serves it.

## Fixes — in this order

### 1. Serve the HTTPS redirect from Apache, not WordPress

> **Status: ✅ done 2026-09-01.** Applied to `public_html/.htaccess` (backup in
> `/tmp/freeplast-htaccess-backup-2026-09-01.txt` + server `.htaccess.bk`). Verified:`http://` now redirects in **0.03 s** (was 3.4–6.7 s), `www.` in **0.07 s** (was 3.3 s),
> full http→https round trip **0.10 s** (was ~4 s before the page even began loading).

Every `http://` (and `www.`) request currently boots WordPress just to emit a 301, costing
3–4 s. Move it into `.htaccess` so it never reaches PHP.

Add at the top of `.htaccess` (cPanel → File Manager, or "Domains → Redirects"), above any
WordPress rules:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off [OR]
RewriteCond %{HTTP_HOST} ^www\. [NC]
RewriteRule ^ https://freeplast.cl%{REQUEST_URI} [L,R=301]
```

**Done when:** `curl -sS -o /dev/null -w "%{time_starttransfer}\n" http://freeplast.cl/`
returns **< 0.1 s** (was 3.4–6.7 s) and the `Location` header still targets
`https://freeplast.cl/`.

### 2. Make WP Super Cache cache the catalog, not just the front page

> **Status: ✅ done 2026-09-01.** Applied in WP Admin → Settings → WP Super Cache.
> Changed: **Cache expiry `3600` → `86400` s** (1 h → 24 h), and **Preload = on** +
> **taxonomies = on** (interval left at default 600). Confirmed already-correct: delivery
> mode **Simple (PHP)** (`wp_cache_mod_rewrite=0`), cache **on**, *don't cache for logged-in*
> users, and the rejected-URI list already containing only cart/checkout/my-account/wc-api
> (`/cart/ /carrito/ /checkout/ /finalizar-compra/ /my-account/ /mi-cuenta/ /wc-api/` plus
> `wp-.*\.php index\.php`). Verified: product page **0.05 s** (was 4.5 s), category page
> **0.05–0.08 s** (was 4.3 s).
> **✅ External cron now in place (2026-09-03).** WP-Cron is disabled in `wp-config.php`, so
> the *scheduled* preload + garbage collection need a real system cron. Added via
> **cPanel → Cron Jobs**: `*/10 * * * *` →
> `/usr/bin/curl -s -o /dev/null "https://freeplast.cl/wp-cron.php?doing_wp_cron" > /dev/null 2>&1`
> (HTTP, so it uses the web's CloudLinux **PHP 7.4**, not the CLI default).
> A pre-existing hourly cron (`cd /home/freeplast/public_html; /bin/nice -n15 /usr/bin/php -q wp-cron.php`)
> is likely **broken**: `/usr/bin/php` resolves to the ea-php81 (8.1) CLI, which the 2020
> stack can't run — it has been failing silently each hour (output → `/dev/null`). Left in
> place (WP-Toolkit-managed, harmless once the new one fires); candidate for removal.

WP Super Cache is installed but product/category pages are not being served from cache.

In **WP Admin → Settings → WP Super Cache**:

- **Caching → Cache hits**: use **PHP** on this host (Expert/`.htaccess` only if the host
  allows mod_rewrite caching).
- **Cache Timeout**: a real value (24 h+), not the near-zero implied by the `max-age=3`
  header currently emitted.
- **Do not cache**: keep only `cart`, `checkout`, `my-account` (and `wc-api`). Remove the
  catalog exclusions, so product/category pages cache for anonymous visitors.
- Enable **Preload** so product/category pages are cached ahead of demand.

**Done when:** the probe in [Verify](#verify) returns **< 0.3 s** TTFB for a product and a
category page (was 4.3–4.6 s), tested with a cache-busting query string on a *second* hit.

### 3. Add object caching / move the PHP cost off the request path

> **Status: ⚠️ partial 2026-09-01.** Biggest in-plan lever applied and verified: **opcache was
> OFF** (cPanel → Seleccionar Versión PHP → the `opcache` extension was unchecked) — that was
> recompiling all of WordPress/WooCommerce/Elementor on every request. Enabled it (+ **APCu**,
> the PHP-level object cache, which needs no separate server) and uncached TTFB dropped
> **4.4 → ~1.6 s** (homepage/product/category, cache-busted). Cached pages hold at 0.07 s,
> redirect at 0.03 s.
>
> Not available on this plan: **Redis / Memcached** (the PHP extensions are listed but there is
> no Redis/Memcached server feature in cPanel), **LiteSpeed** (web server is **Apache** +
> CloudLinux LSAPI — `Server: Apache`, SAPI `litespeed`; LSCache can't do server caching on
> Apache), and no CDN. Actual runtime confirmed **PHP 7.4.33** (EOL), which blocks most
> modern object-cache plugins (e.g. APCu Manager requires PHP 8+).
>
> Still open for < 0.5 s: (a) a PHP-7.4-compatible object-cache drop-in — "Object Cache for
> APCu – ZapCu", "atec Cache APCu", or "SQLite Object Cache" (doesn't even need APCu) —
> or (b) **Cloudflare (free) + APO** (external: needs a Cloudflare account + nameserver move).
>
> **✅ Object-cache drop-in installed 2026-09-03 — but TTFB unchanged (diminishing returns,
> as expected).** Installed **SQLite Object Cache 1.6.5** (Ollie Jones; `requires_php: 5.6`
> so PHP-7.4-safe; `sqlite3` is native/always-on and `pdo_sqlite` enabled on this host).
> Backed up the DB first (UpdraftPlus, "Base de datos" set 2026-09-03 17:12). Installed via
> manual ZIP upload — the in-dashboard "Instalar ahora" fails with `Descarga fallida
> Unauthorized` (server's outbound download to `downloads.wordpress.org` is blocked; the es_ES
> translation pack download fails the same way). Left in **pure-SQLite mode** (APCu off) —
> persistent and immune to LSAPI process recycling, the reason SQLite was chosen over an
> APCu-only drop-in. Verified caching: **384 entries across ~40 WooCommerce-aware groups**
> (options, posts, post_meta, term-queries, products, orders, `wc_session_id`, etc.).
> **Result: uncached TTFB unchanged (~1.4–1.7 s vs ~1.4–1.6 s baseline) — the remaining
> cost is PHP execution in the 2020 plugin stack, not DB queries.** The drop-in's real value
> here is reduced MySQL load under concurrency, invisible in single-request TTFB. No regression:
> cache-busted pages 200, cached pages still ~0.05–0.07 s, redirect 0.04 s. The substantive
> path to a faster *uncached* TTFB is fix #4 (stack upgrade), not more caching.

The 4–5 s is PHP execution time, not bandwidth. Ranked levers:

1. **Persistent object cache** (Redis or Memcached) if the cPanel plan offers it — highest
   impact on shared hosting.
2. If the plan stays on plain Apache, migrate to a **LiteSpeed + LSCache** host, or put
   **Cloudflare (free) + APO** in front (no CDN is currently in front of the site).

**Done when:** the [Verify](#verify) probe on an uncached page drops toward **< 0.5 s**, or a
chosen CDN is confirmed live via a `via`/`server`/`cf-cache-status` response header.

### 4. Update the outdated stack (staging first)

> **⚠️ Hard constraint confirmed 2026-09-01 (production incident).** PHP was manually bumped
> 7.4 → **8.5.9** via cPanel **Administrador MultiPHP** (`ea-php85`). The store **broke
> immediately**: every product page returned **HTTP 500** (fatal) and uncached TTFB degraded to
> ~7.5 s (opcache lost + PHP-8 deprecation churn) while category pages hit ~24 s. The 2020 stack
> (WooCommerce 4.2.5, Elementor 3.1.4/Pro 2.10.3) is **not compatible with any PHP 8.x**.
> **Rolled back** by setting the domain back to `inherit` (UAPI `LangPHP::php_set_vhost_versions`
> `version=inherit`), which restored CloudLinux's **PHP 7.4 (actual)** + the opcache win — product
> pages back to 200, cached 0.06 s, uncached ~1.6 s. **Do not raise PHP above 7.4 on live until
> this whole section is done on staging.**

**WooCommerce 4.2.5 (April 2020)** and **Astra 2.4.5 (2020)** are six years old — slower and
a security liability. WordPress 7.1, Elementor 4.2.4 and Site Kit 1.186.0 are current.

Update WooCommerce then Astra on a **staging/staging copy first**, checking the storefront,
cart and checkout after each; never on production directly.

**Done when:** both report current versions in Plugins → Installed Plugins, and a staging
smoke test of home, product, category, cart and checkout passes.

### 5. Trim the front-end bloat

Measured on the home page (mobile): **80 requests**, **23 render-blocking scripts**, **20 CSS
files** including **4 Google Fonts families** + two icon fonts, and **3 Google tags** (two GTM
containers `GTM-TM82V82`/`GTM-KPS3CFM` + a Google Ads `gtag`), plus Site Kit. Static assets
are already gzipped and on HTTP/2; this is a *render* win, not a TTFB win.

- Defer/combine JS (a minify layer; LiteSpeed/Cloudflare/Autoptimize).
- Stop loading WooCommerce `cart-fragments`, `zoom`, `photoswipe`, `flexslider` on non-shop
  pages.
- Drop 3 of the 4 Google Fonts families (or self-host one); drop `v4-shims` and one icon font.
- Merge the 2 GTM containers + Ads tag into **one** container; remove the duplicate GTM
  noscript iframes.
- Serve images as WebP/responsive + lazy-load (currently JPEG/PNG only).

**Done when:** a reload shows < ~15 external CSS/JS requests and a single Google tag, with no
missing fonts or icons.

## Evidence

Cold (uncached) server response times, measured with `curl`:

| Request | TTFB |
|---|---|
| `/` (cached) | 0.05–0.11 s |
| `/` + cache-busting query string | 4.3–4.6 s |
| `/producto/caja-tomatera/` | 4.5 s |
| `/producto/ladrillo-plastico/` | 4.4 s |
| `/categoria-producto/agricola/` | 4.3 s |
| `/categoria-producto/productos-del-mar/` | 4.6 s |
| `/contacto/` | 4.6 s |
| `/quienes-somos/` | 5.0 s |
| `/wp-json/` (REST index) | 8.3 s |

Redirects (both served by WordPress — `X-Redirect-By: WordPress`):

| Request | TTFB |
|---|---|
| `http://freeplast.cl/` → 301 | 3.4–6.7 s |
| `https://www.freeplast.cl/` → 301 | 3.3 s |

Headless-Chrome load (mobile, DPR 2, cold): TTFB 0.43 s, DOMContentLoaded 0.59 s, load 0.74 s
on the *cached* home page — the fast number is the cache, not the server.

### Verify

The single loop used to confirm any fix — run against a page that matters:

```bash
# uncached: product page
curl -sS -o /dev/null -w "product  TTFB %{time_starttransfer}s code %{http_code}\n" \
  "https://freeplast.cl/producto/caja-tomatera/"
# uncached: category page
curl -sS -o /dev/null -w "category TTFB %{time_starttransfer}s code %{http_code}\n" \
  "https://freeplast.cl/categoria-producto/agricola/"
# redirect
curl -sS -o /dev/null -w "redirect TTFB %{time_starttransfer}s code %{http_code}\n" \
  "http://freeplast.cl/"
```

Healthy targets: **< 0.3 s** on product/category, **< 0.1 s** on the redirect.