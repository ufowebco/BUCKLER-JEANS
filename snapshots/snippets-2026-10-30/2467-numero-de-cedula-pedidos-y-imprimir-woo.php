<?php
// 1. Mostrar la Cédula en el panel de administrador (Debajo de la dirección de facturación)
add_action( 'woocommerce_admin_order_data_after_billing_address', 'mostrar_cedula_admin_woo', 10, 1 );
function mostrar_cedula_admin_woo( $order ) {
    // Verificamos que sea un pedido válido (Compatible con HPOS)
    if ( ! is_a( $order, 'WC_Order' ) ) return;

    // Buscamos el dato. WooCommerce suele guardar los campos de checkout con un guión bajo.
    $cedula = $order->get_meta( '_billing_id' );
    
    // Si no lo encuentra con guión bajo, lo busca con el nombre exacto de tu captura
    if ( empty( $cedula ) ) {
        $cedula = $order->get_meta( 'billing_id' ); 
    }

    if ( ! empty( $cedula ) ) {
        echo '<p><strong>Cédula:</strong> ' . esc_html( $cedula ) . '</p>';
    }
}

// 2. Mostrar la Cédula en el plugin Print Invoices & Delivery Notes (En la tabla de datos principales)
add_filter( 'wcdn_order_info_fields', 'agregar_cedula_ticket_impreso', 10, 2 );
function agregar_cedula_ticket_impreso( $fields, $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) return $fields;

    $cedula = $order->get_meta( '_billing_id' );
    if ( empty( $cedula ) ) {
        $cedula = $order->get_meta( 'billing_id' );
    }

    if ( ! empty( $cedula ) ) {
        // Añadimos la Cédula a la lista de datos del ticket (junto a la fecha, método de pago, etc.)
        $fields['cedula'] = array(
            'label' => 'Cédula',
            'value' => esc_html( $cedula )
        );
    }

    return $fields;
}

// 3. REFuerzo: Forzar que también aparezca debajo del nombre del cliente en el ticket impreso
add_action( 'wcdn_after_billing_address', 'forzar_cedula_debajo_direccion_impreso', 10, 1 );
function forzar_cedula_debajo_direccion_impreso( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) return;

    $cedula = $order->get_meta( '_billing_id' );
    if ( empty( $cedula ) ) {
        $cedula = $order->get_meta( 'billing_id' );
    }

    if ( ! empty( $cedula ) ) {
        echo '<div style="margin-top: 4px; font-size: 14px; font-weight: bold;">Cédula: ' . esc_html( $cedula ) . '</div>';
    }
}