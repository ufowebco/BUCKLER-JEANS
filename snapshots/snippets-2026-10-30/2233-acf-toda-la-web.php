<?php
// Habilitar el constructor Divi 5 en Custom Post Types de forma segura
add_filter('et_builder_post_types', 'ufoweb_activar_divi_en_cpt');

function ufoweb_activar_divi_en_cpt($post_types) {
    // Asegurarnos de que el array original exista
    $post_types = is_array($post_types) ? $post_types : array();
    
    // Lista de tus CPTs
    $mis_custom_types = array(
        'banners', 
        'proyectos',
        'servicios'
    );
    
    // Fusionar de forma segura
    foreach ($mis_custom_types as $cpt) {
        if (!in_array($cpt, $post_types)) {
            $post_types[] = $cpt;
        }
    }
    
    return $post_types;
}