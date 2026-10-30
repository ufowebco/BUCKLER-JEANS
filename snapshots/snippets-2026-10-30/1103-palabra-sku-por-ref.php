<?php
// Cambiar globalmente la palabra SKU por REF en WooCommerce
add_filter( 'gettext', 'cambiar_sku_por_ref_woocommerce', 10, 3 );
function cambiar_sku_por_ref_woocommerce( $texto_traducido, $texto_original, $dominio ) {
    
    // Solo interceptamos los textos que provienen exclusivamente de WooCommerce
    if ( 'woocommerce' === $dominio ) {
        
        // Cambiamos "SKU" normal
        if ( 'SKU' === $texto_original ) {
            $texto_traducido = 'REF';
        } 
        // Cambiamos "SKU:" (con dos puntos, que es el que se usa en la ficha del producto)
        elseif ( 'SKU:' === $texto_original ) {
            $texto_traducido = 'REF:';
        }
        
    }
    
    return $texto_traducido;
}