<?php
add_filter( 'woocommerce_checkout_fields', 'forzar_telefono_obligatorio_checkout', 9999 );

function forzar_telefono_obligatorio_checkout( $fields ) {
    // 1. Apuntamos al campo dentro del grupo 'billing' y lo hacemos obligatorio
    $fields['billing']['billing_phone']['required'] = true;
    
    // 2. Sobrescribimos la etiqueta para limpiarla
    $fields['billing']['billing_phone']['label'] = 'Teléfono';
    
    return $fields;
}

// Mantenemos esto por si otros campos opcionales (como Código Postal) también te molestan
add_filter( 'woocommerce_form_field_optional_label', '__return_empty_string', 9999 );