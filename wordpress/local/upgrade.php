<?php
/** Local-only guarded upgrade (DEV local first): plan/apply/rollback for the catalog request minimums and the /contacto/ placeholder. Containment: WP-CLI + local environment + working mail capture + canonical allowlisted local URL + provenance under the read-only /opt/freeplast repository bind. Receipts are private (capture side, outside the webroot) and carry a token over the FULL validated transformation; apply revalidates identity (seeded product, native type, discovered ids, page id) and only ever allows the catalog-derived after-values and the placeholder↔approved contacto states. Writes are per-row compare-and-swap with exact row counts inside one verified InnoDB transaction (page content: a single guarded UPDATE); START/LOCK failures and uncertain commits are reported and never replayed; native caches are cleaned after commit and rollback. Staging guards elsewhere are NOT relaxed: this file never runs outside the local containment above. */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('WP-CLI required'); }
$args = $args ?? array();
$help = 'wp eval-file /opt/freeplast/upgrade.php status | minima plan <new-receipt> | minima apply|rollback <receipt> <token> | contacto plan <new-receipt> | contacto apply|rollback <receipt> <token> | help';
if (count($args) === 1 && in_array($args[0], array('version', '--version', '-v'), true)) { echo "1.0.0\n"; return; }
if (!$args || (count($args) === 1 && in_array($args[0], array('help', '--help'), true))) { echo 'usage: ' . json_encode($help) . "\ndefault: no writes\n"; return; }
$verbs = array('plan' => 3, 'apply' => 4, 'rollback' => 4);
if ('status' !== $args[0] && (!in_array((string) $args[0], array('minima', 'contacto'), true) || !isset($verbs[(string) ( $args[1] ?? '' )]) || count($args) !== $verbs[(string) $args[1]])) {
	echo 'error: incorrect arguments' . "\nhelp: " . json_encode($help) . "\n"; WP_CLI::halt(2); return;
}

if (!defined('FPW_LOCAL_CATALOG')) { define('FPW_LOCAL_CATALOG', '/opt/catalog/products.json'); }
if (!defined('FPW_LOCAL_CATALOG_SHA256')) { define('FPW_LOCAL_CATALOG_SHA256', '1b3ef16a027b3f68b4704efb6c9d9f2d5e164a93c19f938fdf85cb0ecb38d088'); }
if (!defined('FPW_LOCAL_RECEIPTS')) { define('FPW_LOCAL_RECEIPTS', '/var/freeplast/receipts'); }
/* The exact local placeholder seeded for /contacto/ (local/seed.php), quoted here so the seed stays its only authority. */
if (!defined('FPW_LOCAL_CONTACTO_PLACEHOLDER')) { define('FPW_LOCAL_CONTACTO_PLACEHOLDER', '<p>Demostración local. No enviar datos reales.</p>'); }
if (!defined('FPW_LOCAL_CONTACTO_APPROVED')) { define('FPW_LOCAL_CONTACTO_APPROVED', "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Contacto</h1>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Demostración local con datos ficticios; los correos se capturan y nunca se envían.</p>\n<!-- /wp:paragraph -->\n<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Visítanos</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>P Picarquín sitio 52, San Fco de Mostazal, VI Región</p>\n<!-- /wp:paragraph -->\n<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Llámanos</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p><a href=\"tel:+56968444265\">+56 9 6844 4265</a> · <a href=\"https://api.whatsapp.com/send?phone=56968444265\">WhatsApp</a></p>\n<!-- /wp:paragraph -->\n<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Escríbenos</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p><a href=\"mailto:ventas@freeplast.cl\">ventas@freeplast.cl</a></p>\n<!-- /wp:paragraph -->\n<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Horario</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Lun a Vie 09:00 a 13:00 hrs y 14:00 a 18:00 hrs</p>\n<!-- /wp:paragraph -->"); }

if (!function_exists('fpw_local_upgrade_guard')) {
function fpw_local_upgrade_guard(): string {
	if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local environment required; this helper never touches staging'); }
	if (!function_exists('fpw_local_capture_active') || !fpw_local_capture_active()) { throw new RuntimeException('Working local capture containment required (no real mail may ever leave)'); }
	$url = getenv('FREEPLAST_LOCAL_URL');
	$home = defined('WP_HOME') ? (string) WP_HOME : (string) get_option('home');
	if (!is_string($url) || $home !== $url || !preg_match('~\Ahttp://(?:mliu|localhost|127\.0\.0\.1):[1-9][0-9]{3,4}\z~D', $url)) {
		throw new RuntimeException('Canonical local URL mismatch (env FREEPLAST_LOCAL_URL must equal the site home and be allowlisted)');
	}
	if (!defined('FPW_LOCAL_UPGRADE_TEST') && !str_starts_with(str_replace('\\', '/', __FILE__), '/opt/freeplast/')) {
		throw new RuntimeException('Provenance: this helper must run from the read-only /opt/freeplast repository bind, not from a copy');
	}
	return $url;
}
}

if (!function_exists('fpw_local_upgrade_catalog')) {
function fpw_local_upgrade_catalog(): array {
	$source = (string) FPW_LOCAL_CATALOG;
	if (!is_file($source) || hash_file('sha256', $source) !== FPW_LOCAL_CATALOG_SHA256) { throw new RuntimeException('Catalog source missing or hash differs from the pinned repository bytes; update the pin only with review'); }
	$raw = json_decode((string) file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
	$products = $raw['products'] ?? $raw;
	if (!is_array($products) || count($products) < 1) { throw new RuntimeException('Catalog source unreadable'); }
	return $products;
}
}

if (!function_exists('fpw_local_upgrade_receipt_root')) {
/** The private receipts root on the capture side: never a symlink, outside the webroot, no group/other access. Created 0700 when absent. */
function fpw_local_upgrade_receipt_root(): string {
	$raw = (string) FPW_LOCAL_RECEIPTS;
	if (is_link($raw)) { throw new RuntimeException('Receipt root must not be a symlink'); }
	if (!is_dir($raw)) {
		$mask = umask(0077); $created = mkdir($raw, 0700, true); umask($mask);
		if (!$created) { throw new RuntimeException('Could not create the private receipts root'); }
	}
	$root = realpath($raw);
	$webroot = realpath(ABSPATH);
	if (!$root || !$webroot || str_starts_with($root . '/', $webroot . '/') || str_starts_with($root . '/', '/var/www/') || (fileperms($root) & 0077)) {
		throw new RuntimeException('Receipt root must be a private directory outside the webroot (mode 0700)');
	}
	return $root;
}
}

if (!function_exists('fpw_local_upgrade_meta')) {
/** Current raw meta of one post as native wpdb reads it: null when the row is absent AND null for a stored empty string (native get_var coercion). The upgrade therefore never writes empty strings: an explicit unknown is the ABSENT row, which runtime get_post_meta also reads as unknown. */
function fpw_local_upgrade_meta(int $post_id, string $key): ?string {
	global $wpdb;
	$value = $wpdb->get_var($wpdb->prepare('SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE post_id = %d AND meta_key = %s LIMIT 1', $post_id, $key));
	return null === $value ? null : (string) $value;
}
}

if (!function_exists('fpw_local_upgrade_discover_product')) {
/** The seeded local identity of one catalog product: exactly one published post of native type product, the seed marker, and the native simple/variable type the catalog implies. */
function fpw_local_upgrade_discover_product(array $item): int {
	$slug = (string) $item['slug'];
	$posts = get_posts(array('post_type' => 'product', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids'));
	if (count($posts) !== 1) { throw new RuntimeException('Missing/ambiguous local product identity: ' . $slug); }
	$id = (int) $posts[0];
	$post = get_post($id);
	if (!$post || 'product' !== $post->post_type || $post->post_name !== $slug) { throw new RuntimeException('Local product identity drift: ' . $slug); }
	if (fpw_local_upgrade_meta($id, '_fp_local_seed_complete') !== '1') { throw new RuntimeException('Not the seeded local product (marker missing): ' . $slug); }
	$product = function_exists('wc_get_product') ? wc_get_product($id) : false;
	$expected_type = empty($item['options']) ? 'simple' : 'variable';
	if (!$product || !method_exists($product, 'get_type') || $product->get_type() !== $expected_type) { throw new RuntimeException('Native product type differs from the catalog (expected ' . $expected_type . '): ' . $slug); }
	return $id;
}
}

if (!function_exists('fpw_local_upgrade_token')) {
function fpw_local_upgrade_token(array $receipt): string {
	return hash('sha256', implode('|', array($receipt['mode'], $receipt['url'], $receipt['source'], wp_json_encode($receipt['rows']))));
}
}

if (!function_exists('fpw_local_upgrade_write_receipt')) {
/** One complete, tokened receipt write: mode, url, source, rows and token land together or not at all. */
function fpw_local_upgrade_write_receipt(string $path, string $mode, string $url, array $rows): array {
	$receipt = array('mode' => $mode, 'url' => $url, 'source' => FPW_LOCAL_CATALOG_SHA256, 'rows' => $rows);
	$receipt['token'] = fpw_local_upgrade_token($receipt);
	$mask = umask(0077); $file = fopen($path, 'x'); umask($mask);
	if (!$file) { throw new RuntimeException('Could not create the receipt'); }
	$bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
	$written = fwrite($file, $bytes); $flushed = fflush($file); fclose($file);
	if ($written !== strlen($bytes) || !$flushed) { throw new RuntimeException('Incomplete receipt; do not apply'); }
	return $receipt;
}
}

if (!function_exists('fpw_local_upgrade_receipt_read')) {
/** The receipt on its READ side: private non-symlink root and file, current canonical URL, pinned source, and a FULL transformation validation — the rows must be exactly the currently-discoverable catalog plan (or the placeholder↔approved contacto states); a rebuilt token over tampered rows never passes. */
function fpw_local_upgrade_receipt_read(string $argument, string $mode, string $url): array {
	$root = fpw_local_upgrade_receipt_root();
	$path = $root . '/' . basename($argument);
	if (!is_file($path) || is_link($path) || (fileperms($path) & 0077)) { throw new RuntimeException('Receipt must be a private regular file'); }
	$receipt = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($receipt) || ($receipt['mode'] ?? '') !== $mode || ($receipt['url'] ?? '') !== $url || ($receipt['source'] ?? '') !== FPW_LOCAL_CATALOG_SHA256 || !is_array($receipt['rows'] ?? null)) {
		throw new RuntimeException('Receipt mode/url/source mismatch; plan again on this site, no writes');
	}
	if ('minima' === $mode) {
		$catalog = fpw_local_upgrade_catalog();
		if (count($receipt['rows']) !== count($catalog)) { throw new RuntimeException('Receipt row count differs from the pinned catalog; plan again'); }
		$expected = array();
		foreach ($catalog as $item) {
			$slug = (string) $item['slug'];
			if (isset($expected[$slug])) { throw new RuntimeException('Duplicate slug in the pinned catalog'); }
			$minimum = $item['specs']['minimum_quantity'] ?? null;
			$expected[$slug] = array('id' => fpw_local_upgrade_discover_product($item), 'after_min' => null === $minimum ? null : (string) (int) $minimum, 'after_step' => '1');
		}
		$seen = array();
		foreach ($receipt['rows'] as $row) {
			if (!is_array($row) || !is_string($row['slug'] ?? null) || !isset($expected[$row['slug']])) { throw new RuntimeException('Receipt row outside the pinned catalog: ' . (is_array($row) ? (string) ($row['slug'] ?? '?') : 'malformed')); }
			if (isset($seen[$row['slug']])) { throw new RuntimeException('Duplicate product row in receipt: ' . $row['slug']); }
			$seen[$row['slug']] = true;
			$want = $expected[$row['slug']];
			if ((int) ($row['id'] ?? 0) !== $want['id']) { throw new RuntimeException('Receipt row id is not the currently discovered product: ' . $row['slug']); }
			$after_min = array_key_exists('after_min', $row) ? $row['after_min'] : '\x00absent';
			$after_step = array_key_exists('after_step', $row) ? $row['after_step'] : '\x00absent';
			if ($after_min !== $want['after_min'] || $after_step !== $want['after_step']) { throw new RuntimeException('Receipt after-values are not the catalog-derived minimums: ' . $row['slug']); }
			if (null !== ($row['before_min'] ?? null) && !is_string($row['before_min'])) { throw new RuntimeException('Receipt before-values must be strings or null'); }
			if (null !== ($row['before_step'] ?? null) && !is_string($row['before_step'])) { throw new RuntimeException('Receipt before-values must be strings or null'); }
		}
		if (count($seen) !== count($expected)) { throw new RuntimeException('Receipt is missing product rows; plan again'); }
	} else {
		$page = get_page_by_path('contacto');
		if (!$page || (int) $page->ID < 1) { throw new RuntimeException('Published /contacto/ page not found'); }
		$row = $receipt['rows'][0] ?? null;
		if (count($receipt['rows']) !== 1 || !is_array($row) || ($row['slug'] ?? '') !== 'contacto' || (int) ($row['id'] ?? 0) !== (int) $page->ID) { throw new RuntimeException('Receipt page id is not the discovered /contacto/ page'); }
		$before = $row['before'] ?? null; $after = $row['after'] ?? null;
		if (!in_array($before, array(FPW_LOCAL_CONTACTO_PLACEHOLDER, FPW_LOCAL_CONTACTO_APPROVED), true) || FPW_LOCAL_CONTACTO_APPROVED !== $after) {
			throw new RuntimeException('Receipt contacto states must be exactly placeholder→approved (or the approved no-op)');
		}
	}
	if (!hash_equals(fpw_local_upgrade_token($receipt), (string) ($receipt['token'] ?? ''))) { throw new RuntimeException('Receipt token mismatch; no writes'); }
	return $receipt;
}
}

if (!function_exists('fpw_local_upgrade_engines')) {
/** The native tables this upgrade writes must be InnoDB: row-level locking backs the verified transaction. */
function fpw_local_upgrade_engines(): void {
	global $wpdb;
	foreach (array($wpdb->postmeta, $wpdb->posts) as $table) {
		$engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
		if ('InnoDB' !== (string) $engine) { throw new RuntimeException('InnoDB required for ' . $table . ' (found: ' . (string) $engine . ')'); }
	}
}
}

try {
	$url = fpw_local_upgrade_guard();
	global $wpdb;
	$command = (string) $args[0];
	$verb = (string) ( $args[1] ?? '' );
	if ('status' === $command) {
		$catalog = fpw_local_upgrade_catalog();
		$page = get_page_by_path('contacto');
		$state = $page ? (FPW_LOCAL_CONTACTO_PLACEHOLDER === $page->post_content ? 'placeholder' : (str_contains((string) $page->post_content, 'P Picarquín sitio 52') ? 'approved' : 'unknown')) : 'missing';
		$missing = 0; $known = 0;
		foreach ($catalog as $item) {
			$id = fpw_local_upgrade_discover_product($item);
			$minimum = $item['specs']['minimum_quantity'] ?? null;
			/* An explicit unknown is the absent row; native wpdb also coerces any legacy empty string to null, which reads the same. */
			$accepted = null === $minimum ? array( null ) : array( (string) $minimum );
			if (in_array(fpw_local_upgrade_meta($id, '_fp_quote_min_qty'), $accepted, true)) { $known++; } else { $missing++; }
		}
		echo json_encode(array('status' => 'ok', 'url' => $url, 'products' => count($catalog), 'minima_already_exact' => $known, 'minima_pending_or_drifted' => $missing, 'contacto' => $state), JSON_UNESCAPED_UNICODE) . "\n";
		return;
	}
	if ('plan' !== $verb && getenv('FREEPLAST_LOCAL_BACKUP_VERIFIED') !== '1') { throw new RuntimeException('A verified local backup is required first (FREEPLAST_LOCAL_BACKUP_VERIFIED=1 acknowledges evidence, not proof)'); }

	if ('minima' === $command) {
		if ('plan' === $verb) {
			$root = fpw_local_upgrade_receipt_root();
			$path = $root . '/' . basename((string) $args[2]);
			if (!preg_match('/\A[a-z0-9._-]+\z/iD', basename((string) $args[2])) || is_link($path) || file_exists($path)) { throw new RuntimeException('Choose a new plain receipt file name'); }
			$rows = array();
			foreach (fpw_local_upgrade_catalog() as $item) {
				$id = fpw_local_upgrade_discover_product($item);
				$minimum = $item['specs']['minimum_quantity'] ?? null;
				$rows[] = array(
					'slug' => (string) $item['slug'], 'id' => $id,
					'before_min' => fpw_local_upgrade_meta($id, '_fp_quote_min_qty'), 'before_step' => fpw_local_upgrade_meta($id, '_fp_quote_step'),
					/* An explicit unknown is the ABSENT row: native wpdb reads a stored empty string as null, so '' would be an unverifiable representation here; runtime get_post_meta reads absence as unknown too. */
					'after_min' => null === $minimum ? null : (string) (int) $minimum, 'after_step' => '1',
				);
			}
			$receipt = fpw_local_upgrade_write_receipt($path, 'minima', $url, $rows);
			$changed = count(array_filter($rows, static fn($row) => $row['before_min'] !== $row['after_min'] || $row['before_step'] !== $row['after_step']));
			echo "mode: minima\nproducts: " . count($rows) . "\nchanged: $changed\ntoken: {$receipt['token']}\nreceipt: " . json_encode($path) . "\n";
			return;
		}
		$receipt = fpw_local_upgrade_receipt_read((string) $args[2], 'minima', $url);
		if (!hash_equals($receipt['token'], (string) $args[3])) { throw new RuntimeException('Token mismatch; no writes'); }
		$reverse = 'rollback' === $verb;
		fpw_local_upgrade_engines();
		if (false === $wpdb->query('START TRANSACTION')) { throw new RuntimeException('Cannot begin the verified transaction; no writes'); }
		$commit_attempted = false;
		$ids = array_map(static fn($row) => (int) $row['id'], $receipt['rows']);
		try {
			$lock = $wpdb->query('SELECT post_id FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . implode(',', array_map('intval', $ids)) . ') AND meta_key IN (\'_fp_quote_min_qty\',\'_fp_quote_step\') FOR UPDATE');
			if (false === $lock) { throw new RuntimeException('Cannot lock the planned quantity rows; no writes'); }
			$duplicate = $wpdb->get_var('SELECT post_id FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . implode(',', array_map('intval', $ids)) . ') AND meta_key IN (\'_fp_quote_min_qty\',\'_fp_quote_step\') GROUP BY post_id, meta_key HAVING COUNT(*) > 1 LIMIT 1');
			if (null !== $duplicate && false !== $duplicate && '' !== $duplicate) { throw new RuntimeException('Duplicate quantity metadata rows exist; resolve them before upgrading'); }
			$writes = 0;
			foreach ($receipt['rows'] as $row) {
				foreach (array('min' => '_fp_quote_min_qty', 'step' => '_fp_quote_step') as $field => $key) {
					$before = $reverse ? $row['after_' . $field] : $row['before_' . $field];
					$after = $reverse ? $row['before_' . $field] : $row['after_' . $field];
					$current = fpw_local_upgrade_meta((int) $row['id'], $key);
					if ($current === $after) { continue; }
					if ($current !== $before) { throw new RuntimeException('Quantity metadata drifted since review (' . $row['slug'] . ', ' . $key . '); plan again, merchant edits are never overwritten'); }
					if (null === $after) {
						/* Rolling back to the absent state removes exactly the reviewed row, compare-and-swap on the bytes it had (before is non-null here). */
						$result = $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->postmeta . ' WHERE post_id = %d AND meta_key = %s AND meta_value = %s', (int) $row['id'], $key, (string) $before));
					} elseif (null === $current) {
						$result = $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->postmeta . ' ( post_id, meta_key, meta_value ) VALUES ( %d, %s, %s )', (int) $row['id'], $key, $after));
					} else {
						$result = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->postmeta . ' SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_value = %s', $after, (int) $row['id'], $key, (string) $before));
					}
					if (1 !== $result) { throw new RuntimeException('Compare-and-swap did not write exactly one row (' . $row['slug'] . ', ' . $key . '); inspect, never replay automatically'); }
					if (fpw_local_upgrade_meta((int) $row['id'], $key) !== $after) { throw new RuntimeException('Verification failed after write; inspect the local backup'); }
					$writes++;
				}
			}
			$commit_attempted = true;
			if (false === $wpdb->query('COMMIT')) { throw new RuntimeException('Commit outcome uncertain; inspect the site and the local backup before any retry, never replay blindly'); }
			foreach ($ids as $id) { clean_post_cache($id); }
			echo 'status: ' . ($reverse ? 'rolled back' : 'applied') . "; writes: $writes; verify with status\n";
		} catch (Throwable $error) {
			/* An uncertain COMMIT is never claimed back: the transaction state is unknown, so no ROLLBACK is issued either. */
			if (!$commit_attempted) { $wpdb->query('ROLLBACK'); }
			foreach ($ids as $id) { clean_post_cache($id); }
			throw $error;
		}
		return;
	}

	/* contacto: exact placeholder bytes → the approved local contact block; single compare-and-swap UPDATE, byte-exact under any collation. */
	if ('plan' === $verb) {
		$root = fpw_local_upgrade_receipt_root();
		$path = $root . '/' . basename((string) $args[2]);
		if (!preg_match('/\A[a-z0-9._-]+\z/iD', basename((string) $args[2])) || is_link($path) || file_exists($path)) { throw new RuntimeException('Choose a new plain receipt file name'); }
		$page = get_page_by_path('contacto');
		if (!$page || (int) $page->ID < 1) { throw new RuntimeException('Published /contacto/ page not found'); }
		$content = (string) $page->post_content;
		if (!in_array($content, array(FPW_LOCAL_CONTACTO_PLACEHOLDER, FPW_LOCAL_CONTACTO_APPROVED), true)) { throw new RuntimeException('Unrecognized /contacto/ content (merchant edits preserved); review manually, no plan written'); }
		$receipt = fpw_local_upgrade_write_receipt($path, 'contacto', $url, array(array('id' => (int) $page->ID, 'slug' => 'contacto', 'before' => $content, 'after' => FPW_LOCAL_CONTACTO_APPROVED)));
		echo "mode: contacto\npage: {$page->ID}\ntoken: {$receipt['token']}\nreceipt: " . json_encode($path) . "\n";
		return;
	}
	$receipt = fpw_local_upgrade_receipt_read((string) $args[2], 'contacto', $url);
	if (!hash_equals($receipt['token'], (string) $args[3])) { throw new RuntimeException('Token mismatch; no writes'); }
	$row = $receipt['rows'][0];
	$page_id = (int) $row['id'];
	$want = 'rollback' === $verb ? (string) $row['before'] : (string) $row['after'];
	$expected_old = 'rollback' === $verb ? (string) $row['after'] : (string) $row['before'];
	$post = get_post($page_id);
	if (!$post || 'page' !== $post->post_type || 'contacto' !== $post->post_name) { throw new RuntimeException('Page identity drift; no writes'); }
	fpw_local_upgrade_engines();
	$current = (string) $post->post_content;
	if (hash_equals(hash('sha256', $current), hash('sha256', $want))) { echo 'status: already ' . ('rollback' === $verb ? 'original' : 'approved') . "; no write\n"; return; }
	$result = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->posts . ' SET post_content = %s WHERE ID = %d AND post_content = CAST(%s AS BINARY)', $want, $page_id, $expected_old));
	if (false === $result) { throw new RuntimeException('Update outcome uncertain; inspect the local backup, never replay automatically'); }
	clean_post_cache($page_id);
	$saved = get_post($page_id);
	if (1 === $result) {
		if (!$saved || hash('sha256', (string) $saved->post_content) !== hash('sha256', $want)) { throw new RuntimeException('Verification failed after write; inspect the local backup'); }
		echo 'status: ' . ('rollback' === $verb ? 'rolled back to the local placeholder' : 'applied') . "; verify with curl /contacto/\n";
		return;
	}
	if ($saved && hash('sha256', (string) $saved->post_content) === hash('sha256', $want)) { echo 'status: already ' . ('rollback' === $verb ? 'original' : 'approved') . "; no write\n"; return; }
	throw new RuntimeException('Content drifted since review (compare-and-swap found other bytes); plan again, no writes');
} catch (Throwable $error) {
	echo 'error: ' . json_encode($error->getMessage(), JSON_UNESCAPED_UNICODE) . "\nhelp: inspect the local site and the verified backup before any retry; never reset broadly\n";
	WP_CLI::halt(1);
}
