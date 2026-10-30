<?php
add_action('acf/save_post', 'forzar_actualizacion_pagina_slider_divi', 20);

function forzar_actualizacion_pagina_slider_divi( $post_id ) {
    
    // Si estamos guardando la página de opciones
    if ( $post_id === 'options' || $post_id === 'banners' ) {
        
        // El ID de la página donde está el slider (sacado de tu captura)
        $id_pagina_slider = 44; 
        
        // 1. Actualizamos la página silenciosamente. Esto destruye la caché 
        // de diseño estático de Divi para esa página en específico.
        wp_update_post( array(
            'ID' => $id_pagina_slider
        ) );

        // 2. Limpieza de las cachés globales de Divi y del Servidor
        if ( function_exists( 'et_builder_clear_cache' ) ) {
            et_builder_clear_cache();
        }
        if ( has_action('litespeed_purge_all') ) {
            do_action( 'litespeed_purge_all' );
        }
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
    }
}