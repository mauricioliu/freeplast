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
<?php
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text);
if ($sent_to_admin) { do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email); }
do_action('woocommerce_email_footer', $email);
