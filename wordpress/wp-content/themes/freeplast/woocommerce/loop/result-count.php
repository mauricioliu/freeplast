<?php
/**
 * A · Directa result count (issue #43) — «17 productos» / «N resultados
 * para «q»» over Woo's own loop totals.
 *
 * @package Freeplast
 */

defined( 'ABSPATH' ) || exit;

echo fp_catalog_result_count( (int) wc_get_loop_prop( 'total' ), get_search_query( false ) );
