<?php
/**
 * One-click connect (docs/1297 §5): PKCE (S256), the approval in the Zinn® dashboard, the code
 * exchange from the server, and disconnect. No vendor keys and no pasted secrets.
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The connect flow.
 */
final class Zinn_Order_Updates_Connect {

	/** The admin page slug, also the return address the dashboard sends the browser back to. */
	public const PAGE = 'zinn-order-updates';

	/** Who may connect or disconnect the store. */
	public const CAPABILITY = 'manage_options';

	/** How long a started connection waits for the approval (matches the engine's grant). */
	private const TTL = 600;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_zinn_order_updates_connect', array( self::class, 'start' ) );
		add_action( 'admin_post_zinn_order_updates_disconnect', array( self::class, 'disconnect' ) );
		add_action( 'admin_init', array( self::class, 'maybe_finish' ) );
	}

	/**
	 * The admin page URL, with optional query arguments.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 */
	public static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Base64url without padding (RFC 7636).
	 *
	 * @param string $bytes Raw bytes.
	 */
	public static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 PKCE encoding, not obfuscation.
	}

	/**
	 * The S256 challenge for a verifier.
	 *
	 * @param string $verifier The PKCE verifier.
	 */
	public static function challenge( string $verifier ): string {
		return self::b64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Transient key for the signed-in admin's pending connection.
	 */
	private static function pending_key(): string {
		return 'zinn_order_updates_pkce_' . get_current_user_id();
	}

	/**
	 * The Connect button: make the PKCE pair and send the admin to the Zinn® dashboard to approve.
	 */
	public static function start(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'zinn-order-updates' ), 403 );
		}
		check_admin_referer( 'zinn_order_updates_connect' );
		$verifier = self::b64url( random_bytes( 48 ) );
		$state    = wp_generate_password( 32, false );
		set_transient(
			self::pending_key(),
			array(
				'state'    => $state,
				'verifier' => $verifier,
			),
			self::TTL
		);
		$url = add_query_arg(
			array(
				'site'           => rawurlencode( home_url() ),
				'return'         => rawurlencode( self::page_url() ),
				'state'          => rawurlencode( $state ),
				'code_challenge' => rawurlencode( self::challenge( $verifier ) ),
				'locale'         => rawurlencode( get_user_locale() ),
			),
			zinn_order_updates_app_base() . '/connect/store'
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the Zinn® dashboard, a fixed host from zinn_order_updates_app_base().
		exit;
	}

	/**
	 * The dashboard sends the browser back with `code` and `state`; exchange them from the server.
	 */
	public static function maybe_finish(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the PKCE `state` below is this request's CSRF check (RFC 6749 §10.12); a nonce cannot survive the round trip through the dashboard.
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE !== $page || '' === $code || '' === $state || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$pending = get_transient( self::pending_key() );
		delete_transient( self::pending_key() );
		if ( ! is_array( $pending ) || ! hash_equals( (string) ( $pending['state'] ?? '' ), $state ) ) {
			wp_safe_redirect( self::page_url( array( 'zou_notice' => 'expired' ) ) );
			exit;
		}
		list( $status, $body ) = Zinn_Order_Updates_Api::call(
			'POST',
			'/v1/store-connect/token',
			array(
				'code'           => $code,
				'code_verifier'  => (string) $pending['verifier'],
				'site_url'       => home_url(),
				'site_uuid'      => Zinn_Order_Updates_Api::site_uuid(),
				'plugin_version' => ZINN_ORDER_UPDATES_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'integrations'   => Zinn_Order_Updates_Api::integrations(),
				'platform'       => Zinn_Order_Updates_Api::platform(),
			),
			false
		);
		if ( 201 !== $status || empty( $body['token'] ) ) {
			wp_safe_redirect( self::page_url( array( 'zou_notice' => 0 === $status ? 'unreachable' : 'refused' ) ) );
			exit;
		}
		Zinn_Order_Updates_Api::set_token( (string) $body['token'] );
		delete_transient( Zinn_Order_Updates_Admin::STATUS_CACHE );
		wp_safe_redirect( self::page_url( array( 'zou_notice' => 'connected' ) ) );
		exit;
	}

	/**
	 * The Disconnect button: revoke the token at Zinn® and forget it here.
	 */
	public static function disconnect(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to disconnect this store.', 'zinn-order-updates' ), 403 );
		}
		check_admin_referer( 'zinn_order_updates_disconnect' );
		if ( '' !== Zinn_Order_Updates_Api::token() ) {
			Zinn_Order_Updates_Api::call( 'DELETE', '/v1/store-connect/connection' );
		}
		Zinn_Order_Updates_Api::set_token( '' );
		delete_transient( Zinn_Order_Updates_Admin::STATUS_CACHE );
		wp_safe_redirect( self::page_url( array( 'zou_notice' => 'disconnected' ) ) );
		exit;
	}
}
