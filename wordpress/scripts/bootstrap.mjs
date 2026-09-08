#!/usr/bin/env node
/**
 * Provision the disposable Freeplast WordPress + WooCommerce installation
 * (issue #2; Woo-era replacement per ADR-0001, issue #1 follow-ups #24/#27).
 *
 * Creates a clean WordPress + SQLite site under wordpress/.build/wp:
 *
 *   1. extracts pinned WordPress core (tools fetched by fetch-tools.sh),
 *   2. copies the repository's theme and the freeplast-woo adapter into wp-content,
 *   3. installs the sqlite-database-integration drop-in,
 *   4. installs and activates the pinned WooCommerce + Quotes for WooCommerce
 *      zips (woo-dependencies.json, hash-verified), creates the Woo pages
 *      and two featured test products,
 *   5. writes wp-config.php, installs WordPress, activates the theme and the
 *      adapter, and disables search-engine visibility.
 *
 * Idempotent when the build already exists (wordpress/.build/.provisioned.json):
 * wp-content is re-synced and the active components re-checked. Pass --fresh
 * to rebuild from scratch.
 */
import { execFileSync } from 'node:child_process';
import { copyFileSync, cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const WORDPRESS_DIR = dirname(HERE);
const BUILD_DIR = join(WORDPRESS_DIR, '.build');
const WP_DIR = join(BUILD_DIR, 'wp');
const TOOLS_DIR = join(WORDPRESS_DIR, '.tools');
const CACHE = join(TOOLS_DIR, 'cache');
const PHP = join(TOOLS_DIR, 'php', 'php');
const WPCLI = join(CACHE, 'wp-cli.phar');
const SITE_URL = process.env.FREEPLAST_TEST_URL || 'http://127.0.0.1:8091';

function sh(cmd, args, opts = {}) {
  return execFileSync(cmd, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...opts });
}

function wp(args) {
  return sh(PHP, [WPCLI, ...args, `--url=${SITE_URL}`, '--quiet'], { cwd: WP_DIR }).trim();
}

console.log('Bootstrapping disposable Freeplast WordPress + WooCommerce…');

/* 1. Tools */
if (!existsSync(PHP) || !existsSync(WPCLI)) {
  console.log('  tools missing — fetching pinned toolchain');
  sh('bash', [join(HERE, 'fetch-tools.sh')], { stdio: 'inherit' });
}

/* 2. Clean WordPress core */
if (existsSync(join(BUILD_DIR, '.provisioned.json')) && !process.argv.includes('--fresh')) {
  console.log('  existing disposable installation found — re-syncing wp-content only');
  syncContent();
  reactivate();
  pinComingSoonOff();
  ensureStagingControlTexts();
  seedVariantProduct();
  seedReferenceCatalog();
  ensureChromePages();
  console.log('  disposable installation ready (existing build)');
  process.exit(0);
}

rmSync(BUILD_DIR, { recursive: true, force: true });
mkdirSync(WP_DIR, { recursive: true });
console.log('  extracting WordPress core…');
execFileSync('tar', ['-xzf', join(CACHE, 'wordpress.tar.gz'), '-C', WP_DIR, '--strip-components=1']);

/* 3. Theme + adapter from the repository */
syncContent();

/* 4. SQLite drop-in */
const sqliteSrc = join(CACHE, 'sqlite-database-integration');
if (!existsSync(sqliteSrc)) {
  execFileSync('unzip', ['-q', '-o', join(CACHE, 'sqlite-plugin.zip'), '-d', CACHE]);
}
cpSync(sqliteSrc, join(WP_DIR, 'wp-content', 'plugins', 'sqlite-database-integration'), { recursive: true });
copyFileSync(join(sqliteSrc, 'db.copy'), join(WP_DIR, 'wp-content', 'db.php'));

/* 5. wp-config.php */
const adminPassword = 'freeplast-' + createHash('sha256').update(String(Date.now())).digest('hex').slice(0, 12);
writeFileSync(
  join(WP_DIR, 'wp-config.php'),
  `<?php
define( 'DB_NAME', 'freeplast_disposable' );
define( 'DB_USER', 'db' );
define( 'DB_PASSWORD', 'db' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'fp_';

define( 'WP_HOME', '${SITE_URL}' );
define( 'WP_SITEURL', '${SITE_URL}' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISALLOW_FILE_EDIT', true );
/* Honor the Nginx-forwarded HTTPS scheme — mirrors the staging Compose
   WORDPRESS_CONFIG_EXTRA — so the checks exercise the same TLS seam the
   deployment uses (the basket cookie Secure flag follows the request
   scheme, issue #19). */
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
  $_SERVER['HTTPS'] = 'on';
}
/* Deterministic scheduling for the disposable check: WP-Cron's loopback
   would fire scheduled events (notification delivery) at unpredictable
   moments mid-test. The check drives scheduled work explicitly; staging
   runs system cron (see wordpress/BUILD-DECISIONS.md). */
define( 'DISABLE_WP_CRON', true );
define( 'WP_CACHE', false );
define( 'AUTOSAVE_INTERVAL', 3600 );

/* That's all, stop editing! Happy publishing. */
if ( ! defined( 'ABSPATH' ) ) {
  define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
`
);

/* 6. Install, theme, plugin, noindex */
console.log('  installing WordPress (locale en_US — see BUILD-DECISIONS.md)…');
wp(['core', 'install', '--title=Freeplast', '--admin_user=freeplast', `--admin_password=${adminPassword}`, '--admin_email=admin@freeplast.local', '--skip-email']);
wp(['rewrite', 'structure', '/%postname%/']);
wp(['theme', 'activate', 'freeplast']);
wp(['option', 'update', 'blog_public', '0']);
installWoo();
wp(['plugin', 'activate', 'freeplast-woo']);  // after Woo: the adapter declares Requires Plugins
seedVariantProduct();
seedReferenceCatalog();
ensureChromePages();

/* 7. Marker */
const wpVersion = wp(['core', 'version']);
const phpVersion = sh(PHP, ['-r', 'echo PHP_VERSION;']).trim();
writeFileSync(
  join(BUILD_DIR, '.provisioned.json'),
  JSON.stringify({ wpVersion, phpVersion, siteUrl: SITE_URL, provisionedAt: new Date().toISOString(), adminUser: 'freeplast', adminPassword }, null, 2)
);
mkdirSync(join(WP_DIR, 'wp-content', 'database'), { recursive: true });
console.log(`  disposable WordPress ${wpVersion} + WooCommerce ready at ${SITE_URL} (admin credentials in .build/.provisioned.json)`);
console.log('  theme+adapter: freeplast / freeplast-woo (active, noindex on)');

function syncContent() {
  const content = join(WP_DIR, 'wp-content');
  mkdirSync(join(content, 'themes'), { recursive: true });
  mkdirSync(join(content, 'plugins'), { recursive: true });
  rmSync(join(content, 'themes', 'freeplast'), { recursive: true, force: true });
  rmSync(join(content, 'plugins', 'freeplast-woo'), { recursive: true, force: true });
  cpSync(join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast'), join(content, 'themes', 'freeplast'), { recursive: true });
  cpSync(join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-woo'), join(content, 'plugins', 'freeplast-woo'), { recursive: true });
}

/** The pinned Woo zips, hash-verified against woo-dependencies.json (downloaded once into .tools/cache). */
function pinnedWooZip(slug) {
  const deps = JSON.parse(readFileSync(join(WORDPRESS_DIR, 'woo-dependencies.json'), 'utf8'));
  const dep = deps[slug];
  if (!dep) throw Error(`woo-dependencies.json has no entry for ${slug}`);
  const dest = join(CACHE, `${slug}-${dep.version}.zip`);
  if (!existsSync(dest) || createHash('sha256').update(readFileSync(dest)).digest('hex') !== dep.sha256) {
    console.log(`  downloading ${slug} ${dep.version} (pinned)…`);
    sh('curl', ['-fsSL', '--retry', '3', '-o', `${dest}.part`, dep.url]);
    const digest = createHash('sha256').update(readFileSync(`${dest}.part`)).digest('hex');
    if (digest !== dep.sha256) throw Error(`${slug} zip hash mismatch: ${digest}`);
    execFileSync('mv', ['-f', `${dest}.part`, dest]);
  }
  return dest;
}

function installWoo() {
  for (const slug of ['woocommerce', 'quotes-for-woocommerce']) {
    const zip = pinnedWooZip(slug);
    if (wp(['plugin', 'list', '--format=csv', '--fields=name']).split('\n').includes(slug)) {
      wp(['plugin', 'activate', slug]);
    } else {
      wp(['plugin', 'install', zip, '--activate']);
    }
  }
  // Cart + checkout pages. install_pages creates the block checkout; staging deliberately
  // runs the CLASSIC checkout (ADR-0001: the quotes extension's address options are not
  // equivalent in Checkout Blocks), so the pages get the classic shortcodes.
  wp(['wc', 'tool', 'run', 'install_pages', '--user=1']);
  pinComingSoonOff();
  ensureStagingControlTexts();
  const classicShortcodes = {
    woocommerce_cart_page_id: '[woocommerce_cart]',
    woocommerce_checkout_page_id: '[woocommerce_checkout]',
  };
  for (const [option, shortcode] of Object.entries(classicShortcodes)) {
    const id = wp(['option', 'get', option]);
    if (id) wp(['post', 'update', id, `--post_content=<!-- wp:shortcode -->${shortcode}<!-- /wp:shortcode -->`]);
  }
  // Two featured products (price 0 is the documented technical value enabling native purchasability),
  // each with the quotes extension's per-product flag (qwc_enable_quotes=on) so the checkout takes
  // the quotes gateway and the request stays pending — as on staging.
  for (const name of ['Caja Cosechera 3/4 (prueba)', 'Caja Universal (prueba)']) {
    const out = sh(PHP, [WPCLI, 'wc', 'product', 'create', `--name=${name}`, '--type=simple', '--regular_price=0', '--featured=1', '--user=1', `--url=${SITE_URL}`, '--quiet', '--porcelain'], { cwd: WP_DIR });
    wp(['post', 'meta', 'update', out.trim(), 'qwc_enable_quotes', 'on']);
  }
  wp(['cache', 'flush']);
}

function reactivate() {
  wp(['theme', 'activate', 'freeplast']);
  const active = wp(['plugin', 'list', '--status=active', '--format=csv', '--fields=name']).split('\n');
  if (active.includes('woocommerce') && active.includes('quotes-for-woocommerce')) {
    wp(['plugin', 'activate', 'freeplast-woo']);
  }
}

/** #35: a VARIABLE synthetic fixture with one variant (Color: Rojo) so the
 *  native regression's preservation snapshots cover a distinct variant line,
 *  not only simple products. Price 0 is the documented technical value
 *  enabling native purchasability; the parent carries the quotes extension's
 *  per-product flag exactly like the two simple fixtures above (the extension
 *  maps a variation line to its parent before reading it). Idempotent by
 *  title and seeded on EVERY bootstrap — fresh installs and wp-content
 *  re-syncs alike — so an existing disposable build gains it too.
 *  The variation's attribute KEY is the normalized slug ('color'), and the
 *  seeded variant is verified through the SAME seam add-to-cart uses —
 *  WC_Product_Data_Store_CPT::find_matching_product_variation with
 *  attribute_color=Rojo (pinned Woo 11.1.0): WC_Product_Variation::set_attributes
 *  strips only the attribute_ prefix and preserves key case, while the matcher
 *  requires attribute_ + sanitize_title(parent attribute name), so a
 *  'Color'-keyed variant can never match attribute_color. A wrong or
 *  unmatchable preexisting fixture is REPAIRED (attribute + variant), never
 *  silently accepted — and a still-unmatchable fixture fails the bootstrap
 *  loudly instead of seeding a scenario that cannot run. */
function seedVariantProduct() {
  const code = `
    $found = get_posts( array( 'post_type' => 'product', 'title' => 'Caja Variable Color (prueba)', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
    $created = 'reused';
    if ( empty( $found ) ) {
      $product = new WC_Product_Variable();
      $product->set_name( 'Caja Variable Color (prueba)' );
      $created = 'created';
    } else {
      // A preexisting fixture of the WRONG type is repaired to variable (the
      // catalog type term drives wc_get_product/add-to-cart), never accepted.
      $existing = wc_get_product( $found[0] );
      if ( ! $existing || 'variable' !== $existing->get_type() ) {
        wp_set_object_terms( (int) $found[0], 'variable', 'product_type' );
        $created = 'repaired-type';
      }
      $product = new WC_Product_Variable( (int) $found[0] );
    }
    $has_color = false;
    foreach ( $product->get_attributes() as $attribute ) {
      if ( $attribute->get_variation() && 'color' === sanitize_title( $attribute->get_name() ) ) { $has_color = true; }
    }
    if ( ! $has_color ) {
      $attribute = new WC_Product_Attribute();
      $attribute->set_id( 0 );
      $attribute->set_name( 'Color' );
      $attribute->set_options( array( 'Rojo', 'Azul' ) );
      $attribute->set_position( 0 );
      $attribute->set_visible( true );
      $attribute->set_variation( true );
      $attributes = $product->get_attributes();
      $attributes[] = $attribute;
      $product->set_attributes( $attributes );
      $created = ( 'created' === $created ) ? $created : 'repaired-attribute';
    }
    $product->set_status( 'publish' );
    $product->save();
    update_post_meta( $product->get_id(), 'qwc_enable_quotes', 'on' );
    $store = new WC_Product_Data_Store_CPT();
    $match = $store->find_matching_product_variation( $product, array( 'attribute_color' => 'Rojo' ) );
    if ( ! $match ) {
      $variation = new WC_Product_Variation();
      $variation->set_parent_id( $product->get_id() );
      // The key must be the NORMALIZED slug: set_attributes strips only the
      // attribute_ prefix (case preserved) while find_matching matches
      // attribute_ . sanitize_title( parent attribute name ) — 'Color' would
      // persist as attribute_Color and never match attribute_color.
      $variation->set_attributes( array( 'color' => 'Rojo' ) );
      $variation->set_status( 'publish' );
      $variation->set_regular_price( '0' );
      $variation->save();
      $match = $store->find_matching_product_variation( $product, array( 'attribute_color' => 'Rojo' ) );
      $created = ( 'created' === $created ) ? $created : 'repaired-variation';
    }
    if ( ! $match ) {
      fwrite( STDERR, 'seedVariantProduct: fixture unmatchable through the pinned variation matcher — aborting loudly' );
      exit( 1 );
    }
    echo $created . ':' . $product->get_id() . ':variation-' . $match;
  `;
  wp(['eval', code, '--user=1']);
}

/** Woo 11.x "coming soon" mode replaces store-page content for logged-out visitors
    and its onboarding flows can enable it on a living stack (observed mid-run): pinned
    off on EVERY bootstrap — fresh installs and wp-content re-syncs alike — so the public
    pages under regression are always the real cart/checkout surfaces. */
function pinComingSoonOff() {
  wp(['option', 'update', 'woocommerce_coming_soon', 'no']);
  wp(['option', 'update', 'woocommerce_store_pages_only', 'no']);
  wp(['option', 'update', 'woocommerce_private_link', 'no']);
}

/** Staging-parity control copy (issue #42): the same extension texts the
 *  production migration sets, so the fixture's native buttons read exactly
 *  what the real service shows. Idempotent option updates only — no business
 *  rule (prices/stock/coupons/registration) is introduced. */
function ensureStagingControlTexts() {
  const texts = {
    qwc_add_to_cart_button_text: 'Agregar a Productos a Cotizar',
    qwc_cart_page_name: 'Productos a Cotizar',
    qwc_checkout_page_name: 'Datos y envío',
    qwc_place_order_text: 'Solicitar cotización',
    qwc_proceed_checkout_btn_label: 'Continuar con mis datos',
  };
  const payload = Buffer.from(JSON.stringify(texts), 'utf8').toString('base64');
  wp(['eval', `
    $texts = json_decode(base64_decode('${payload}'), true);
    foreach ($texts as $key => $value) { update_option($key, $value); }
    echo 'control-texts:' . count($texts);
  `, '--user=1']);
}

/** Issue #41: seed the 17 reference catalog products — the controlled synthetic
 *  dataset that reproduces the frozen A · Directa fixture (titles, slugs, Agrícola/
 *  Otros categories, featured flags, the two Color variable products with their
 *  five named colors, matching reference media and explicit missing photos).
 *  Source: wordpress/data/products.json, the same bootstrap snapshot the approved
 *  prototype froze (prototype catalog.js). This is a TEST FIXTURE, never a Catalog
 *  Source: production keeps Woo's editable catalog untouched. Idempotent by slug:
 *  existing fixtures are never duplicated or overwritten. */
function seedReferenceCatalog() {
  const raw = JSON.parse(readFileSync(join(WORDPRESS_DIR, 'data', 'products.json'), 'utf8'));
  const items = (Array.isArray(raw) ? raw : raw.products).map((item) => ({
    slug: item.slug,
    title: item.title,
    excerpt: item.excerpt,
    description: item.description,
    category: item.category,
    featured: Boolean(item.featured),
    options: (item.options || []).map((option) => option.label),
    specs: item.specs || {},
    // These two source files are pending-photo placeholders in frozen A.
    image: item.image && !['caja-paltera', 'traversa-para-bins-tipo-romano'].includes(item.slug)
      ? { ...item.image, file: join(WORDPRESS_DIR, 'data', item.image.file) } : null,
  }));
  // Refuse altered/missing source media before any database mutation.
  for (const item of items) {
    if (item.image && 'sha256:' + createHash('sha256').update(readFileSync(item.image.file)).digest('hex') !== item.image.checksum) {
      throw new Error('Reference fixture media checksum mismatch: ' + item.slug);
    }
  }
  const payload = Buffer.from(JSON.stringify(items), 'utf8').toString('base64');
  const code = `
    $items = json_decode(base64_decode('${payload}'), true);
    if (!is_array($items)) { fwrite(STDERR, 'seedReferenceCatalog: bad fixture payload'); exit(1); }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $cats = array();
    foreach (array('agricola' => 'Agrícola', 'otros' => 'Otros') as $slug => $label) {
      $term = term_exists($slug, 'product_cat');
      if (!$term) { $term = wp_insert_term($label, 'product_cat', array('slug' => $slug)); }
      if (is_wp_error($term)) { fwrite(STDERR, $term->get_error_message()); exit(1); }
      $cats[$slug] = (int) (is_array($term) ? $term['term_id'] : $term);
    }
    $specFields = array('material' => 'Material', 'dimensions' => 'Medidas', 'weight' => 'Peso propio', 'use' => 'Uso', 'units_per_pallet' => 'Unidades por pallet');
    $created = 0;
    foreach ($items as $item) {
      $existing = get_posts(array('post_type' => 'product', 'name' => $item['slug'], 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids'));
      if ($existing) { continue; }
      $isVariable = !empty($item['options']);
      $product = $isVariable ? new WC_Product_Variable() : new WC_Product_Simple();
      $product->set_name($item['title']);
      $product->set_slug($item['slug']);
      $product->set_description((string) $item['description']);
      $product->set_short_description((string) $item['excerpt']);
      $product->set_status('publish');
      $product->set_catalog_visibility('visible');
      $product->set_category_ids(array($cats[$item['category']] ?? $cats['otros']));
      $product->set_featured(!empty($item['featured']));
      $product->set_reviews_allowed(false);
      $product->set_manage_stock(false);
      $product->set_stock_status('instock'); // technical eligibility, never a stock promise
      $product->update_meta_data('_fp_unpriced', 'yes');
      $product->update_meta_data('qwc_enable_quotes', 'on');
      $attributes = array();
      foreach ($specFields as $key => $label) {
        if (!empty($item['specs'][$key])) {
          $attribute = new WC_Product_Attribute();
          $attribute->set_name($label);
          $attribute->set_options(array((string) $item['specs'][$key]));
          $attribute->set_visible(true);
          $attributes[] = $attribute;
        }
      }
      if ($isVariable) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Color');
        $attribute->set_options($item['options']);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $attributes[] = $attribute;
        $product->set_attributes($attributes);
        $product->save();
        foreach ($item['options'] as $label) {
          $variation = new WC_Product_Variation();
          $variation->set_parent_id($product->get_id());
          // Normalized attribute key (see seedVariantProduct): the matcher
          // requires attribute_ . sanitize_title('Color') = attribute_color.
          $variation->set_attributes(array('color' => $label));
          $variation->set_regular_price('0'); // unpriced sentinel, never a commercial offer
          $variation->set_manage_stock(false);
          $variation->set_stock_status('instock');
          $variation->set_status('publish');
          $variation->update_meta_data('_fp_unpriced', 'yes');
          $variation->save();
        }
        WC_Product_Variable::sync($product->get_id());
      } else {
        $product->set_regular_price('0');
        $product->set_attributes($attributes);
        $product->save();
      }
      if (!empty($item['image'])) {
        $image = $item['image'];
        $temp = wp_tempnam(basename($image['file']));
        if (!$temp || !copy($image['file'], $temp)) { throw new RuntimeException('Cannot copy reference fixture media'); }
        $attachment = media_handle_sideload(array('name' => basename($image['file']), 'tmp_name' => $temp), $product->get_id());
        if (file_exists($temp)) { unlink($temp); }
        if (is_wp_error($attachment)) { throw new RuntimeException('Cannot import reference fixture media'); }
        update_post_meta($attachment, '_wp_attachment_image_alt', (string) $image['alt']);
        $product->set_image_id($attachment);
        $product->save();
      }
      wc_delete_product_transients($product->get_id());
      $created++;
    }
    wp_cache_flush();
    echo 'reference-catalog:' . $created;
  `;
  wp(['eval', code, '--user=1']);
}

/** Issue #41: the disposable stack renders the surfaces the real site uses —
 *  /cotizacion/ carries the ACTUAL Cart block (staging parity; the classic-
 *  shortcode cart fixture alone cannot prove the block journey) while the
 *  checkout stays CLASSIC (ADR-0001). Synthetic placeholder pages back the
 *  shared chrome's real destinations (Nosotros, Contacto, privacy) so every
 *  header/footer/help link resolves. */
function ensureChromePages() {
  const cartBlockMarkup = readFileSync(join(HERE, 'woo-cart.html'), 'utf8');
  const pages = {
    tienda: { title: 'Catálogo', content: '' },
    cotizacion: { title: 'Productos a Cotizar', content: cartBlockMarkup },
    // Staging parity: the classic checkout page carries its native slug/name.
    'datos-y-envio': { title: 'Datos y envío', content: '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' },
    nosotros: { title: 'Nosotros', content: '<!-- wp:paragraph --><p>Página sintética de prueba.</p><!-- /wp:paragraph -->' },
    contacto: { title: 'Contacto', content: '<!-- wp:paragraph --><p>Página sintética de prueba.</p><!-- /wp:paragraph -->' },
    'politica-de-privacidad': { title: 'Política de privacidad', content: '<!-- wp:paragraph --><p>Política sintética de prueba.</p><!-- /wp:paragraph -->' },
  };
  const payload = Buffer.from(JSON.stringify(pages), 'utf8').toString('base64');
  const code = `
    $pages = json_decode(base64_decode('${payload}'), true);
    $ids = array();
    foreach ($pages as $slug => $page) {
      $existing = get_page_by_path($slug, OBJECT, 'page');
      if ($existing) {
        if (trim((string) $existing->post_content) !== trim((string) $page['content'])) {
          wp_update_post(array('ID' => $existing->ID, 'post_content' => $page['content'], 'post_status' => 'publish'));
        }
        $ids[$slug] = (int) $existing->ID;
      } else {
        $ids[$slug] = wp_insert_post(array(
          'post_type' => 'page',
          'post_title' => $page['title'],
          'post_name' => $slug,
          'post_content' => $page['content'],
          'post_status' => 'publish',
        ), true);
      }
      if (is_wp_error($ids[$slug]) || !$ids[$slug]) { fwrite(STDERR, 'ensureChromePages failed for ' . $slug); exit(1); }
    }
    // /cotizacion/ becomes THE cart page: the header's Productos a Cotizar and
    // Woo's own cart redirects point at the Cart block surface, as on staging.
    update_option('woocommerce_cart_page_id', $ids['cotizacion']);
    update_option('woocommerce_shop_page_id', $ids['tienda']);
    update_option('woocommerce_checkout_page_id', $ids['datos-y-envio']);
    flush_rewrite_rules(false);
    echo 'chrome-pages:' . implode(',', array_keys($ids));
  `;
  wp(['eval', code, '--user=1']);
}
