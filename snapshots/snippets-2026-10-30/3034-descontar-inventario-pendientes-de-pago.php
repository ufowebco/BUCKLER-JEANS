<?php
add_action('woocommerce_order_status_pending', 'ufo_reducir_stock_en_pendiente');

function ufo_reducir_stock_en_pendiente($order_id) {

    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    // Evitar doble descuento
    if ($order->get_data_store()->get_stock_reduced($order_id)) {
        return;
    }

    wc_reduce_stock_levels($order_id);
}