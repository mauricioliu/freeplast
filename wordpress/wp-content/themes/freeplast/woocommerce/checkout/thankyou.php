<?php
/** Quote-only confirmation; Woo checkout has already validated the order key/access. */
if (!defined('ABSPATH')) { exit; }
if (!$order) { echo '<p>Solicitud recibida.</p>'; return; }
?>
<section aria-labelledby="fpw-received">
<h2 id="fpw-received">Solicitud recibida</h2>
<p>Referencia: <strong><?php echo esc_html($order->get_order_number()); ?></strong></p>
<p>Ventas te contactará para confirmar disponibilidad, precios y condiciones. Esto no constituye una compra.</p>
<table class="shop_table"><caption>Productos solicitados</caption><thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th></tr></thead><tbody>
<?php foreach ($order->get_items() as $item) : ?>
<tr><td><?php echo esc_html($item->get_name()); wc_display_item_meta($item); ?></td><td><?php echo esc_html($item->get_quantity()); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p><a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">Volver al catálogo</a></p>
</section>
