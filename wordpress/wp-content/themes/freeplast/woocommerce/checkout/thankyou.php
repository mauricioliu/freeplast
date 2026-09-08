<?php
/**
 * A · Directa confirmation (issue #47).
 *
 * Quote-only confirmation; Woo checkout has already validated the order
 * key/access, and the adapter's durable request/reference/recovery
 * machinery stays untouched. Every value is READ from the stored order:
 * the Request Reference (FP-YYYY-NNNNNN via the adapter's order number),
 * the persisted lines/options/quantities and the submitted dispatch
 * choice. No demo reference, no success timer, no delivery claim — the
 * copy states that sales will review and contact, nothing more.
 *
 * @package Freeplast
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order ) {
	echo '<section class="fp-shell fp-catalog fp-confirmation"><div class="fp-page-heading"><h1>No pudimos verificar esta solicitud.</h1><p class="fp-page-lead">Abre el enlace de confirmación autorizado para consultar su referencia y productos.</p><a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Volver al catálogo</a></div></section>';
	return;
}

$fp_items = array();
$fp_lines = 0;
$fp_units = 0;
foreach ( $order->get_items() as $fp_item ) {
	$fp_lines++;
	$fp_units += (int) $fp_item->get_quantity();
	$fp_items[] = $fp_item;
}
$fp_details = $order->get_meta( '_fp_submitted_details' );
$fp_details = is_array( $fp_details ) ? $fp_details : array();
$fp_dispatch = (string) ( $fp_details['billing_fp_dispatch'] ?? $order->get_meta( '_billing_fp_dispatch' ) );
$fp_address = 'si' === $fp_dispatch ? (string) ( $fp_details['billing_fp_address'] ?? $order->get_meta( '_billing_fp_address' ) ) : '';
$fp_name = (string) ( $fp_details['billing_first_name'] ?? '' );
$fp_email = (string) ( $fp_details['billing_email'] ?? '' );
?>
<section class="fp-shell fp-catalog fp-confirmation" aria-labelledby="fpw-received">
<ol class="fp-steps" aria-label="Pasos de la solicitud">
<li><span class="step-number" aria-hidden="true">1</span><span>Productos</span></li>
<li><span class="step-number" aria-hidden="true">2</span><span>Tus datos</span></li>
<li class="active" aria-current="step"><span class="step-number" aria-hidden="true">3</span><span>Solicitud</span></li>
</ol>
<div class="fp-confirmation-hero">
<span class="fp-success-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="30" height="30"><path d="m5 12 4 4L19 6"/></svg></span>
<p class="fp-eyebrow">Solicitud de cotización</p>
<h1 id="fpw-received">Solicitud recibida.</h1>
<p>Gracias<?php echo $fp_name ? ', ' . esc_html( $fp_name ) : ' por tu interés'; ?>. Tu selección está lista para que ventas prepare una cotización.</p>
<p class="fp-reference" aria-label="Referencia de solicitud"><strong><?php echo esc_html( $order->get_order_number() ); ?></strong></p>
</div>
<div class="fp-confirmation-content">
<h2>¿Qué sigue ahora?</h2>
<ol class="fp-next-steps">
<li><span class="step-number" aria-hidden="true">1</span><div><strong>Ventas revisa tu solicitud.</strong><p>Confirmará disponibilidad, precios y condiciones de los productos seleccionados.</p></div></li>
<li><span class="step-number" aria-hidden="true">2</span><div><strong>Te contactaremos con los detalles.</strong><p>Usaremos los datos que ingresaste en el paso anterior. No estás realizando una compra.</p></div></li>
</ol>
<div class="summary-card">
<h2>Resumen de tu selección</h2>
<div class="summary-numbers">
<div><strong><?php echo esc_html( (string) $fp_lines ); ?></strong><span><?php echo 1 === $fp_lines ? 'producto distinto' : 'productos distintos'; ?></span></div>
<div><strong><?php echo esc_html( number_format_i18n( $fp_units ) ); ?></strong><span><?php echo 1 === $fp_units ? 'unidad en total' : 'unidades en total'; ?></span></div>
</div>
<ul class="fp-mini-lines">
<?php foreach ( $fp_items as $fp_item ) : ?>
<li><span><?php echo esc_html( $fp_item->get_name() ); ?><?php echo wp_kses_post( wc_display_item_meta( $fp_item, array( 'before' => ' · ', 'after' => '', 'separator' => ' · ', 'label_before' => '', 'label_after' => ': ', 'echo' => false ) ) ); ?></span><b>× <?php echo esc_html( (string) $fp_item->get_quantity() ); ?></b></li>
<?php endforeach; ?>
</ul>
<p class="fp-fine"><?php echo 'si' === $fp_dispatch ? ( '' !== $fp_address ? 'Despacho solicitado: ' . esc_html( $fp_address ) . '.' : 'Despacho solicitado; dirección por confirmar.' ) : ( 'no' === $fp_dispatch ? 'Solicitud sin despacho.' : 'Despacho por confirmar con ventas.' ); ?><?php if ( '' !== $fp_email ) { echo ' Email de contacto: ' . esc_html( $fp_email ) . '.'; } ?></p>
<p class="fp-fine">Ventas confirmará precios, disponibilidad y condiciones. No estás realizando una compra. No hay reserva de stock.</p>
</div>
</div>
<div class="fp-action-row">
<a class="fp-btn fp-btn-secondary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">Volver al catálogo</a>
</div>
</section>
