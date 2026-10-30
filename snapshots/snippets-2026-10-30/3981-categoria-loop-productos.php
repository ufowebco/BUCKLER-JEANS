<?php
declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Divi5_Random_By_Block_Class {

	private const TRIGGER_CLASS = 'products-random';

	/** Fragmento del blockName del módulo Woo Products. Ajusta si tu log mostró otro. */
	private const TARGET_BLOCK = 'shop';

	private static bool $active = false;

	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'render_block_data', [ self::class, 'detect' ], 5 );
		add_filter( 'render_block', [ self::class, 'reset' ], 999, 2 );
		add_filter( 'woocommerce_shortcode_products_query', [ self::class, 'apply' ], 20 );
	}

	private static function is_target( $block ): bool {
		if ( ! is_array( $block ) ) {
			return false;
		}

		$name = (string) ( $block['blockName'] ?? '' );

		return str_starts_with( $name, 'divi/' ) && str_contains( $name, self::TARGET_BLOCK );
	}

	/**
	 * @param mixed $block
	 * @return mixed
	 */
	public static function detect( $block ) {
		if ( ! self::is_target( $block ) ) {
			return $block;
		}

		$attrs = wp_json_encode( $block['attrs'] ?? [] );

		// Coincidencia exacta de clase, no subcadena.
		if ( is_string( $attrs ) && preg_match( '/(?<![\w-])' . preg_quote( self::TRIGGER_CLASS, '/' ) . '(?![\w-])/', $attrs ) ) {
			self::$active = true;
		}

		return $block;
	}

	/**
	 * @param mixed $content
	 * @param mixed $block
	 * @return mixed
	 */
	public static function reset( $content, $block ) {
		if ( self::is_target( $block ) ) {
			self::$active = false;
		}

		return $content;
	}

	/**
	 * @param array<string,mixed> $query_args
	 * @return array<string,mixed>
	 */
	public static function apply( array $query_args ): array {
		if ( ! self::$active ) {
			return $query_args;
		}

		$query_args['orderby'] = 'rand';
		unset( $query_args['order'] );

		return $query_args;
	}
}

Divi5_Random_By_Block_Class::boot();