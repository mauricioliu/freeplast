<?php
/** A quote request is unpriced. Used by the extension's existing email delivery classes. */
if ( ! defined('ABSPATH') ) { exit; }
do_action('woocommerce_email_header', 'Solicitud de cotización recibida', $email);
?>
<p>Recibimos la solicitud <?php echo esc_html($order->get_order_number()); ?>. Ventas confirmará disponibilidad, precios y condiciones. Esto no constituye una compra.</p>
<table cellspacing="0" cellpadding="8" border="1" style="width:100%;border-collapse:collapse"><caption>Productos solicitados</caption><thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th></tr></thead><tbody>
<?php foreach ($order->get_items() as $item) : ?>
<tr><td><?php echo esc_html($item->get_name()); wc_display_item_meta($item); ?></td><td><?php echo esc_html($item->get_quantity()); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php if ( $sent_to_admin && function_exists( 'fpw_draft_screen_url' ) ) : ?>
<div style="margin:24px 0;padding:16px;border:1px solid #dedfe6;border-radius:6px;background:#f6f6f8">
	<h2 style="margin:0 0 8px;font-size:15px"><?php echo esc_html('Borrador privado de cotización'); ?></h2>
	<p style="margin:0 0 12px"><?php echo esc_html('Esta solicitud ya tiene su borrador inicial, con sus productos, opciones, cantidades y datos de contacto. Los precios, el historial y el despacho aparecen como pendientes hasta que los completes.'); ?></p>
	<p style="margin:0 0 4px"><a href="<?php echo esc_url( fpw_draft_screen_url( (int) $order->get_id() ) ); ?>" style="display:inline-block;padding:10px 16px;background:#1f2a44;color:#ffffff;text-decoration:none;border-radius:6px"><?php echo esc_html('Abrir borrador privado'); ?></a></p>
	<p style="margin:8px 0 0;color:#60626d"><?php echo esc_html('El enlace exige tu sesión autorizada: abrirlo sin ella no muestra nada.'); ?></p>
</div>
<?php endif; ?>
<?php
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text);
if ($sent_to_admin) { do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email); }
do_action('woocommerce_email_footer', $email);
