<?php
add_filter( 'woocommerce_checkout_fields', 'forzar_correo_woocommerce_obligatorio' );

function forzar_correo_woocommerce_obligatorio( $fields ) {
    // Asegura que el campo de correo electrónico de facturación sea requerido
    $fields['billing']['billing_email']['required'] = true;
    
    return $fields;
}
