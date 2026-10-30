<?php
// 1. Forzar a WooCommerce a reconocer el descuento dinámico de YayPricing
add_filter( 'woocommerce_product_is_on_sale', 'forzar_estado_oferta_yaypricing', 9999, 2 );
function forzar_estado_oferta_yaypricing( $is_on_sale, $product ) {
    $precio_regular = (float) $product->get_regular_price();
    $precio_actual  = (float) $product->get_price();
    
    // Si el precio actual es menor al regular, está en oferta
    if ( $precio_regular > 0 && $precio_actual < $precio_regular ) {
        return true;
    }
    return $is_on_sale;
}

// 2. Sobrescribir el bloqueo de YayPricing y mostrar la etiqueta nativa con el porcentaje
add_filter( 'woocommerce_sale_flash', 'forzar_etiqueta_constructor_yaypricing', 9999, 3 );
function forzar_etiqueta_constructor_yaypricing( $texto, $post, $product ) {
    $precio_regular = (float) $product->get_regular_price();
    $precio_actual  = (float) $product->get_price();

    if ( $precio_regular > 0 && $precio_actual < $precio_regular ) {
        // Calcula automáticamente el porcentaje del descuento
        $porcentaje = round( ( ( $precio_regular - $precio_actual ) / $precio_regular ) * 100 );
        
        // Devuelve la clase 'onsale' para que el constructor conserve el diseño visual intacto
        return '<span class="onsale">-' . $porcentaje . '%</span>';
    }
    
    return $texto;
}