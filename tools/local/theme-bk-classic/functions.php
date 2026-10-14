<?php
add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-slider' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'title-tag' );
} );
add_action( 'wp_enqueue_scripts', fn() => wp_enqueue_style( 'bk-classic', get_stylesheet_uri() ) );
