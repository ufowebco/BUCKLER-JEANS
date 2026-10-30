<?php
add_action( 'woocommerce_before_shop_loop_item_title', 'agregar_etiqueta_nuevo_destacado_loop', 15 );

function agregar_etiqueta_nuevo_destacado_loop() {
    global $product;
    
    // Validamos que el producto exista y que esté marcado como destacado (la estrella)
    if ( $product && $product->is_featured() ) {
        echo '<span class="etiqueta-nuevo-woo">Nuevo</span>';
    }
}