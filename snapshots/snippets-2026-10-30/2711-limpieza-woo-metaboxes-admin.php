<?php
add_action( 'add_meta_boxes', 'limpiar_metaboxes_productos_woo', 99 );

function limpiar_metaboxes_productos_woo() {
    // 1. Matar el panel nativo de campos personalizados (El que más pone lento el admin)
    remove_meta_box( 'postcustom', 'product', 'normal' ); 
    
    // 2. Eliminar paneles obsoletos o redundantes
    remove_meta_box( 'trackbacksdiv', 'product', 'normal' ); // Trackbacks (tecnología obsoleta)
    remove_meta_box( 'commentstatusdiv', 'product', 'normal' ); // Activar/desactivar comentarios
    remove_meta_box( 'commentsdiv', 'product', 'normal' ); // Lista de comentarios
    remove_meta_box( 'slugdiv', 'product', 'normal' ); // El slug ya se edita debajo del título
    remove_meta_box( 'authordiv', 'product', 'normal' ); // Autor del producto
    
    // Opcional: Si NO usas etiquetas en los productos, quita las dos barras (//) de la siguiente línea:
    // remove_meta_box( 'tagsdiv-product_tag', 'product', 'side' ); 
}