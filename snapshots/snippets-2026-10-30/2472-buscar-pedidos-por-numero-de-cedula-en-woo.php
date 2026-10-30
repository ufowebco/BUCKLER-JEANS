<?php
// 1. Dar permiso al buscador en la NUEVA arquitectura (HPOS)
add_filter( 'woocommerce_order_table_search_query_meta_keys', 'habilitar_busqueda_cedula_hpos' );
function habilitar_busqueda_cedula_hpos( $meta_keys ) {
    $meta_keys[] = '_billing_id';
    $meta_keys[] = 'billing_id';
    return $meta_keys;
}

// 2. Dar permiso al buscador en la arquitectura CLÁSICA (Como respaldo de seguridad)
add_filter( 'woocommerce_shop_order_search_fields', 'habilitar_busqueda_cedula_clasica' );
function habilitar_busqueda_cedula_clasica( $meta_keys ) {
    $meta_keys[] = '_billing_id';
    $meta_keys[] = 'billing_id';
    return $meta_keys;
}