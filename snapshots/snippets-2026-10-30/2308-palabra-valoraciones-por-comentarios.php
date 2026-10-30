<?php
// ========================================================================
// UFOWEB: RENOMBRAR PESTAÑA DE VALORACIONES A COMENTARIOS
// ========================================================================
add_filter( 'woocommerce_product_tabs', 'ufoweb_renombrar_pestana_valoraciones', 98 );

function ufoweb_renombrar_pestana_valoraciones( $tabs ) {
    global $product;
    
    // Verificamos que la pestaña de reseñas realmente exista en este producto
    if ( isset( $tabs['reviews'] ) ) {
        
        // Obtenemos el número actual para no perder el contador (ej. "0" o "5")
        $conteo_comentarios = $product->get_review_count();
        
        // Sobrescribimos el título nativo
        $tabs['reviews']['title'] = 'Comentarios (' . $conteo_comentarios . ')';
    }
    
    return $tabs;
}