<?php
/**
 * The shopper's language, resolved at checkout and stored on the order (docs/1297 §12.2, 1108#8).
 *
 * First match wins: Tranzly, WPML, Polylang, TranslatePress, the signed-in shopper's own
 * language, then the site language. The result is kept on the order as `_zinn_ou_locale`
 * through the order object, so it works with HPOS and the posts table alike. It is read when
 * an order message is sent, in the language the shopper bought in.
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The language resolver.
 */
final class Zinn_Order_Updates_Locale {

	/** The order meta key. */
	public const META = '_zinn_ou_locale';

	/**
	 * Hooks: the classic checkout and the block (Store API) checkout.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'on_classic_checkout' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( self::class, 'on_block_checkout' ), 20, 1 );
	}

	/**
	 * Classic checkout: the order is saved by WooCommerce right after this hook.
	 *
	 * @param object $order The WC_Order being created.
	 */
	public static function on_classic_checkout( $order ): void {
		self::stamp( $order );
	}

	/**
	 * Block checkout: the order already exists, so the meta is saved here.
	 *
	 * @param object $order The WC_Order being updated.
	 */
	public static function on_block_checkout( $order ): void {
		if ( self::stamp( $order ) && method_exists( $order, 'save_meta_data' ) ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Writes the resolved language on the order. Returns whether it changed the order.
	 *
	 * @param object $order A WC_Order.
	 */
	public static function stamp( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return false;
		}
		$locale = self::resolve( $order );
		if ( '' === $locale ) {
			return false;
		}
		$order->update_meta_data( self::META, $locale );
		return true;
	}

	/**
	 * The shopper's language for one order, first match wins (docs/1297 §12.2).
	 *
	 * @param object|null $order A WC_Order, or null for the current request.
	 */
	public static function resolve( $order = null ): string {
		$meta       = static function ( string $key ) use ( $order ): string {
			return is_object( $order ) && method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( $key, true ) : '';
		};
		$candidates = array(
			// 1. Tranzly: the order's language (Tranzly Pro writes it), else the request's.
			static function () use ( $meta ): string {
				$code = $meta( '_tranzly_language' );
				if ( '' === $code && function_exists( 'tranzly_current_language' ) ) {
					$code = (string) tranzly_current_language();
				}
				return $code;
			},
			// 2. WPML: the order's language, else the request's.
			static function () use ( $meta ): string {
				$code = $meta( 'wpml_language' );
				if ( '' === $code && defined( 'ICL_LANGUAGE_CODE' ) ) {
					$code = (string) constant( 'ICL_LANGUAGE_CODE' );
				}
				return $code;
			},
			// 3. Polylang: the request's language as a locale.
			static function (): string {
				return function_exists( 'pll_current_language' ) ? (string) pll_current_language( 'locale' ) : '';
			},
			// 4. TranslatePress: its current language (a locale) during checkout.
			static function (): string {
				global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- TranslatePress's own global.
				return isset( $TRP_LANGUAGE ) && is_string( $TRP_LANGUAGE ) ? $TRP_LANGUAGE : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- see above.
			},
			// 5. The signed-in shopper's own language.
			static function () use ( $order ): string {
				$user = is_object( $order ) && method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
				return $user > 0 ? (string) get_user_meta( $user, 'locale', true ) : '';
			},
			// 6. The site language.
			static function (): string {
				return function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale();
			},
		);
		foreach ( $candidates as $candidate ) {
			$code = self::clean( $candidate() );
			if ( '' !== $code ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * A locale or language code, at most 16 characters of letters, digits, `_` and `-`.
	 *
	 * @param string $code A code.
	 */
	public static function clean( string $code ): string {
		$code = trim( $code );
		return preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,2}$/', $code ) ? substr( $code, 0, 16 ) : '';
	}
}
