<?php
// Reemplazar la imagen principal por la galería en el catálogo de Divi (Soporte para BodyCommerce)
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
add_action( 'woocommerce_before_shop_loop_item_title', 'ufoweb_galeria_loop_divi', 10 );

function ufoweb_galeria_loop_divi() {
    global $product;
    
    // Obtener la imagen principal
    $main_image_id = $product->get_image_id();
    // Obtener la galería
    $attachment_ids = $product->get_gallery_image_ids();
    
    $images = array();
    if ( $main_image_id ) $images[] = $main_image_id;
    if ( $attachment_ids ) $images = array_merge( $images, $attachment_ids );
    
    if ( empty( $images ) ) {
        echo wc_placeholder_img();
        return;
    }

    echo '<div class="woo-loop-galeria-contenedor">';
    echo '<div class="woo-loop-slider">';
    
    $imagenes_validas = 0; // Contador para saber si mostramos flechas
    
    foreach ( $images as $id ) {
        // EL FILTRO MÁGICO: Si no es una foto (ej. es un video de BodyCommerce), se salta y no dibuja el cuadro blanco
        if ( wp_attachment_is_image( $id ) ) {
            $image_url = wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' );
            if ( $image_url ) {
                echo '<img src="' . esc_url( $image_url ) . '" loading="lazy" />';
                $imagenes_validas++;
            }
        }
    }
    
    echo '</div>'; // Cierra .woo-loop-slider
    
    // Si hay más de una FOTO VÁLIDA, ponemos las flechas
    if ( $imagenes_validas > 1 ) {
        echo '<div class="woo-loop-flechas">';
        echo '<span class="woo-prev">&#10094;</span>';
        echo '<span class="woo-next">&#10095;</span>';
        echo '</div>';
    }
    
    echo '</div>'; // Cierra el contenedor
}