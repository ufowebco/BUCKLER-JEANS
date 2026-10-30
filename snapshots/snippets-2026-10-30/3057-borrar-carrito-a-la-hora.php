<?php
add_filter( 'wc_session_expiring', 'ufoweb_cart_expiring' );
function ufoweb_cart_expiring( $seconds ) {
    // La sesión comienza a expirar a los 50 minutos (3000 segundos)
    return 60 * 50; 
}

add_filter( 'wc_session_expiration', 'ufoweb_cart_expiration' );
function ufoweb_cart_expiration( $seconds ) {
    // La sesión expira completamente y el carrito se vacía al cumplir 1 hora (3600 segundos)
    return 60 * 60; 
}