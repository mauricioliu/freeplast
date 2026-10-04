# Fresh staging reconstruction — 2026-10-02 UTC (2026-10-01 Chile)

## Authorization and scope

Owner requested re-enabling `https://freeplast.mliu.site`, explained the server was reinstalled, identified SSH as Hetzner, and explicitly chose **«desde cero»**. Read-only discovery found no Freeplast installation/database/backup. No old customer records or users were restored. Production `freeplast.cl` was not contacted or changed.

Source: the last recorded release, commit `6e14a7cc111b36b9cfd63a237927f1b707f6b7ac`, sealed artifacts in `.build/wp-release/20260909T180647Z-0b3402/bundle/`. The working tree and new owner workspace were NOT deployed. This was a fresh bootstrap, not execution of the historical Docker release ceremony.

## Current topology (supersedes old OpenClaw/Compose target)

- SSH alias **`hetzner-vps`**, root at `178.105.30.70`.
- WordPress root `/var/www/freeplast`, dedicated system user `freeplast`.
- Host MariaDB database/user `freeplast`, localhost only.
- Dedicated PHP 8.5 FPM pool `/etc/php/8.5/fpm/pool.d/freeplast.conf`, socket `/run/php/freeplast.sock`; four on-demand workers, 256 MB limit.
- Nginx vhost `/etc/nginx/sites-available/freeplast.mliu.site` (symlink enabled).
- Let's Encrypt certificate for this hostname, automatic renewal, initially expires 2026-12-31.
- `/etc/cron.d/freeplast`: due WP-Cron events every five minutes as `freeplast`.
- No Docker installed or required. **Do not run the old `openclaw`/Compose release or restore commands against this installation. Future deployment automation must first be adapted to this topology.**

## Installed content

- WordPress **7.1.2**, locale **es_CL**, America/Santiago.
- WooCommerce **11.1.0** with es_CL translations; Quotes for WooCommerce **2.13**.
- Freeplast theme **1.0.18**, adapter **1.6.8**.
- 17 products (8 featured), Color variations, approved repository bootstrap descriptions/specifications and 17 checksum-verified reference photographs from `wordpress/data/catalog-photos`.
- Original Nosotros/Contacto/privacy copy seeded from the historical shell helper without activating the retired plugin; current Woo migration supplies catalog/cart/classic-checkout options and privacy session wording. WooCommerce is now the editable catalog authority.
- No price list, sales workbook, fiscal policy or external Google credential configured.
- Independent staging MU mail shim preserved (SHA-256 `71164446f3f7432311ed917e412c161f8076acda57a4b3eb02b095a63009d5f1`). Two attempted test emails suppressed; no real delivery.
- Public preview with `blog_public=0`, HTTP `X-Robots-Tag: noindex, nofollow`, robots disallow. Admin still requires login. No HTTP Basic Auth.
- Config/dotfiles/backup extensions and upload PHP denied; login rate limited. Other vhosts/databases preserved.

## Verification and recovery

- WordPress checksums pass; all **48** sealed theme/adapter installed-file hashes pass; pinned vendor ZIP hashes pass.
- Initial WP-CLI extraction truncated long core paths. Replaced only newly installed core directories using GNU tar from the official 7.1.2 archive; checksum verification then passed.
- WP-CLI `--prompt` unexpectedly printed the initial administrator password. It was invalidated before enabling the vhost; replacement generated into pass and applied via stdin without echo. All sessions invalidated. Replacement authenticated admin login passed. Pass entry: `freeplast/staging-hetzner-20261001` (JSON `username`, `admin_password`, `url`). Database secret is in protected `wp-config.php`, not that entry.
- Home/catalog/product/cart/business pages/login return 200. Empty checkout redirects to cart as expected. `wp-config.php` returns 404.
- Anonymous end-to-end test passed: missing Color rejection, simple quantity 140 plus Rojo variant quantity 5, dispatch address and RUT validation, request persistence, confirmation and emptied cart, no displayed prices. Synthetic request **63**, marked `PRUEBA NO COMERCIAL`, retained as test evidence, not a real lead.
- The older test expected an obsolete Home link wrapper; adjusted only its temporary copy to the shipped title-link markup, then reran successfully. No production/theme code changed for this test.
- Chrome Home inspection at 412×915: no horizontal overflow, no broken images; screenshot `/root/freeplast-redeploy-20261001/home-mobile.png`. The browser reported missing `/favicon.ico` (minor outstanding item), not a JavaScript exception. Human acceptance and full native regression suite were not performed.
- PHP log empty at verification; Nginx/PHP configuration tests pass, all services active.

Protected initial paired backup: `/root/freeplast-backups/20261002-initial/` (`database.sql.gz`, `files.tar.gz`, configs, SHA256SUMS). Checksums, gzip and tar readability passed. **No restore rehearsal performed.** Backup contains sensitive config and must remain private. No off-server copy or scheduled backup configured in this task.

Bootstrap sources and pre-change shared Nginx/FPM config copies: `/root/freeplast-redeploy-20261001/`. To withdraw this new staging without touching another site, disable only its Nginx symlink, test/reload Nginx, and preserve files/database pending a recovery decision. Do not use historical Docker rollback scripts.
