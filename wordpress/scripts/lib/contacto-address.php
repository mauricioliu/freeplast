<?php
/** Approved /contacto/ address correction: exact seeded bytes and state detection. Included by the one-shot migration and its offline test. */
if ( ! defined( 'FPW_CONTACTO_OLD_ADDRESS' ) ) {
	/* Exact bytes the retired shell seeded (migration 5); quoted here so the retired plugin never becomes runtime authority. */
	define( 'FPW_CONTACTO_OLD_ADDRESS', "<!-- wp:paragraph -->\n<p>Estamos en la caletera Camino El Arrayán 52, San Francisco de Mostazal, VI Región.</p>\n<!-- /wp:paragraph -->\n" );
	define( 'FPW_CONTACTO_OLD_MAP', "<!-- wp:paragraph -->\n<p><a href=\"https://maps.app.goo.gl/QtGSdagB55W7rnRj7\" target=\"_blank\" rel=\"noopener\">Ver en el mapa</a></p>\n<!-- /wp:paragraph -->\n" );
	define( 'FPW_CONTACTO_NEW_ADDRESS', "<!-- wp:paragraph -->\n<p>Estamos en P Picarquín sitio 52, San Fco de Mostazal, VI Región.</p>\n<!-- /wp:paragraph -->\n" );
}
function fpw_contacto_state(string $content): string {
	$has_address = str_contains($content, FPW_CONTACTO_OLD_ADDRESS);
	$has_map = str_contains($content, FPW_CONTACTO_OLD_MAP);
	if ($has_address && $has_map) { return 'seeded'; }
	if (!$has_address && !$has_map) { return str_contains($content, FPW_CONTACTO_NEW_ADDRESS) ? 'approved' : 'unknown'; }
	return 'partial';
}

/** The only approved transformation: replace the seeded address block and drop the seeded map block, byte-exact, all other content untouched. */
function fpw_contacto_transform(string $before): ?string {
	if (fpw_contacto_state($before) !== 'seeded') { return null; }
	$after = str_replace(array(FPW_CONTACTO_OLD_ADDRESS, FPW_CONTACTO_OLD_MAP), array(FPW_CONTACTO_NEW_ADDRESS, ''), $before);
	return ($after !== $before && fpw_contacto_state($after) === 'approved') ? $after : null;
}
