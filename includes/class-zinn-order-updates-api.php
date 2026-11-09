<?php
/**
 * The only outbound HTTP the plugin makes: the Zinn® API, from the server (readme `External services`).
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A thin client for the Zinn® store-connect API (docs/1297 §4.4).
 */
final class Zinn_Order_Updates_Api {

	/** Option holding the store token. ⛔ Never autoloaded, never shown, never logged. */
	public const TOKEN_OPTION = 'zinn_order_updates_token';

	/** Option holding this install's own id (made once). */
	public const UUID_OPTION = 'zinn_order_updates_site_uuid';

	/**
	 * The stored store token, or an empty string.
	 */
	public static function token(): string {
		return (string) get_option( self::TOKEN_OPTION, '' );
	}

	/**
	 * Keeps the store token out of autoload: it is read only when the plugin calls the API.
	 *
	 * @param string $token The token, or '' to forget it.
	 */
	public static function set_token( string $token ): void {
		if ( '' === $token ) {
			delete_option( self::TOKEN_OPTION );
			return;
		}
		update_option( self::TOKEN_OPTION, $token, false );
	}

	/**
	 * This install's id, made once.
	 */
	public static function site_uuid(): string {
		$uuid = (string) get_option( self::UUID_OPTION, '' );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( self::UUID_OPTION, $uuid, false );
		}
		return $uuid;
	}

	/**
	 * The integration keys active on this site (docs/1297 §3.2). Woo first.
	 *
	 * @return string[]
	 */
	public static function integrations(): array {
		$found = array();
		if ( class_exists( 'WooCommerce' ) ) {
			$found[] = 'woocommerce';
		}
		/**
		 * Filters the integration keys this store reports.
		 *
		 * @param string[] $found Integration keys, lower case.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'zinn_order_updates_integrations', $found ) ) ) );
	}

	/**
	 * Facts about the platform, reported at connect time.
	 *
	 * @return array<string, mixed>
	 */
	public static function platform(): array {
		return array(
			'woocommerce' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
			'multisite'   => is_multisite(),
			'php'         => PHP_VERSION,
		);
	}

	/**
	 * One call. Returns the HTTP status (0 when the request never completed) and the decoded body.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path under the API base, starting with a slash.
	 * @param array<string, mixed>|null $body   JSON body, or null.
	 * @param bool                      $signed Send the store token.
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	public static function call( string $method, string $path, ?array $body = null, bool $signed = true ): array {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'ZinnOrderUpdates/' . ZINN_ORDER_UPDATES_VERSION . '; ' . home_url( '/' ),
		);
		if ( $signed ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}
		$response = wp_remote_request( zinn_order_updates_api_base() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return array( 0, array() );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array( (int) wp_remote_retrieve_response_code( $response ), is_array( $decoded ) ? $decoded : array() );
	}
}
