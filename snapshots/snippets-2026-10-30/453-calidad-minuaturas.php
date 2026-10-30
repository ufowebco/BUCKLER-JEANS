<?php
add_filter( 'woocommerce_gallery_thumbnail_size', 'mejorar_resolucion_miniaturas' );
function mejorar_resolucion_miniaturas( $size ) {
    // Le decimos que en lugar de 'woocommerce_gallery_thumbnail' (100px), 
    // use 'woocommerce_thumbnail' (la imagen del catálogo que tiene mejor calidad)
    return 'woocommerce_thumbnail';
}