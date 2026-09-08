<?php
/** Native Woo loop with a presentation scope for A's grid cascade. */
defined( 'ABSPATH' ) || exit;
?>
<ul class="products catalog-grid columns-<?php echo esc_attr( wc_get_loop_prop( 'columns' ) ); ?>">
