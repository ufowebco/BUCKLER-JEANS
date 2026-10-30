<?php
add_action( 'wp_enqueue_scripts', 'limpiar_scripts_woo_fuera_de_tienda', 99 );
function limpiar_scripts_woo_fuera_de_tienda() {
    // Si WooCommerce está activo
    if ( function_exists( 'is_woocommerce' ) ) {
        // Si NO estamos en una página de tienda, carrito, checkout o cuenta
        if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_account_page() ) {
            // Desregistramos estilos y scripts pesados
            wp_dequeue_style( 'woocommerce-layout' );
            wp_dequeue_style( 'woocommerce-smallscreen' );
            wp_dequeue_style( 'woocommerce-general' );
            wp_dequeue_script( 'wc-add-to-cart' );
            wp_dequeue_script( 'woocommerce' );
            wp_dequeue_script( 'jquery-blockui' );
            wp_dequeue_script( 'jquery-placeholder' );
        }
    }
}

add_action( 'init', 'detener_heartbeat_frontend', 1 );
function detener_heartbeat_frontend() {
    // Apaga el latido constante si el usuario no está en el escritorio de WordPress (wp-admin)
    if ( ! is_admin() ) {
        wp_deregister_script('heartbeat');
    }
}