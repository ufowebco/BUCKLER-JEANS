<?php
// Inyectar la primera imagen de la galería para el hover en Divi
add_action( 'woocommerce_before_shop_loop_item_title', 'divi_imagen_hover_galeria', 11 );
function divi_imagen_hover_galeria() {
    global $product;

    // Asegurarnos de que el producto sea válido
    if ( ! is_a( $product, 'WC_Product' ) ) {
        return;
    }

    // Obtener los IDs de las imágenes de la galería
    $imagenes_galeria = $product->get_gallery_image_ids();

    // Si el producto tiene imágenes en su galería, tomamos la primera
    if ( $imagenes_galeria && isset( $imagenes_galeria[0] ) ) {
        echo wp_get_attachment_image( 
            $imagenes_galeria[0], 
            'woocommerce_thumbnail', 
            false, 
            array( 'class' => 'imagen-hover-divi' ) 
        );
    }
}