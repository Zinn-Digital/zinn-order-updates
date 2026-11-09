<?php
/**
 * The admin screen: Connect, the connection's state, Disconnect (docs/1297 §5, §13.6).
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen.
 */
final class Zinn_Order_Updates_Admin {

	/** Cached status read (five minutes), so opening the screen is not an API call each time. */
	public const STATUS_CACHE = 'zinn_order_updates_status';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	/**
	 * The menu entry.
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'Zinn® Order Updates', 'zinn-order-updates' ),
			__( 'Order Updates', 'zinn-order-updates' ),
			Zinn_Order_Updates_Connect::CAPABILITY,
			Zinn_Order_Updates_Connect::PAGE,
			array( self::class, 'render' ),
			'dashicons-format-chat',
			56
		);
	}

	/**
	 * The connection as Zinn® sees it, or null when there is none (a revoked token is forgotten).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function status(): ?array {
		if ( '' === Zinn_Order_Updates_Api::token() ) {
			return null;
		}
		$cached = get_transient( self::STATUS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		list( $code, $body ) = Zinn_Order_Updates_Api::call(
			'GET',
			'/v1/store-connect/status?' . http_build_query(
				array(
					'plugin_version' => ZINN_ORDER_UPDATES_VERSION,
					'wp_version'     => get_bloginfo( 'version' ),
					'integrations'   => implode( ',', Zinn_Order_Updates_Api::integrations() ),
				)
			)
		);
		if ( 401 === $code ) {
			// Disconnected from the dashboard: show Reconnect (docs/1297 §5 step 5).
			Zinn_Order_Updates_Api::set_token( '' );
			return null;
		}
		if ( 200 !== $code ) {
			return array( 'unreachable' => true );
		}
		set_transient( self::STATUS_CACHE, $body, 5 * MINUTE_IN_SECONDS );
		return $body;
	}

	/**
	 * A one-line notice after a redirect.
	 */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks which fixed sentence to show.
		$key      = isset( $_GET['zou_notice'] ) ? sanitize_key( wp_unslash( $_GET['zou_notice'] ) ) : '';
		$messages = array(
			'connected'    => array( 'success', __( 'Your store is connected to Zinn® Campaigns.', 'zinn-order-updates' ) ),
			'disconnected' => array( 'info', __( 'Your store is disconnected. No messages will be sent.', 'zinn-order-updates' ) ),
			'expired'      => array( 'warning', __( 'That connection took too long or was started in another browser. Press Connect again.', 'zinn-order-updates' ) ),
			'refused'      => array( 'error', __( 'Zinn® did not accept the connection. Press Connect again; if it keeps failing, contact Zinn® support.', 'zinn-order-updates' ) ),
			'unreachable'  => array( 'error', __( 'Your site could not reach Zinn®. Check that your host allows outgoing connections, then press Connect again.', 'zinn-order-updates' ) ),
		);
		if ( isset( $messages[ $key ] ) ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $messages[ $key ][0] ),
				esc_html( $messages[ $key ][1] )
			);
		}
	}

	/**
	 * A POST button to an admin-post action.
	 *
	 * @param string $action The action name.
	 * @param string $label  The button text.
	 * @param string $classes Button classes.
	 */
	private static function button( string $action, string $label, string $classes ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<p><button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * The screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Zinn_Order_Updates_Connect::CAPABILITY ) ) {
			return;
		}
		$status = self::status();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Zinn® Order Updates', 'zinn-order-updates' ) . ' <a class="page-title-action" href="' . esc_url( Zinn_Order_Updates_Help::url() ) . '">' . esc_html__( 'Help', 'zinn-order-updates' ) . '</a></h1>';
		self::notice();
		if ( null === $status ) {
			echo '<p>' . esc_html__( 'Send your customers SMS and WhatsApp updates about their orders, under your store\'s own name, paid from your Zinn® credit.', 'zinn-order-updates' ) . '</p>';
			echo '<p>' . esc_html__( 'Connect opens Zinn® in this window. Sign in or create an account, approve this store, and you come straight back here. You never paste a key.', 'zinn-order-updates' ) . '</p>';
			self::button( 'zinn_order_updates_connect', __( 'Connect to Zinn® Campaigns', 'zinn-order-updates' ), 'button button-primary' );
			Zinn_Order_Updates_Promo::render_panel();
			echo '</div>';
			return;
		}
		if ( ! empty( $status['unreachable'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Connected, but Zinn® could not be reached just now. We will try again shortly.', 'zinn-order-updates' ) . '</p></div>';
		} else {
			echo '<table class="form-table" role="presentation"><tbody>';
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html__( 'Store', 'zinn-order-updates' ),
				esc_html( (string) ( $status['site_host'] ?? '' ) )
			);
			$connected = strtotime( (string) ( $status['connected_at'] ?? '' ) );
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html__( 'Connected', 'zinn-order-updates' ),
				esc_html( $connected ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $connected ) : '' )
			);
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html__( 'Zinn® Campaigns', 'zinn-order-updates' ),
				! empty( $status['campaigns_open'] )
					? esc_html__( 'Open for your account', 'zinn-order-updates' )
					: esc_html__( 'Not open for your account yet. Zinn® will e-mail you when it is.', 'zinn-order-updates' )
			);
			echo '</tbody></table>';
		}
		self::button( 'zinn_order_updates_disconnect', __( 'Disconnect', 'zinn-order-updates' ), 'button' );
		Zinn_Order_Updates_Promo::render_panel();
		echo '</div>';
	}
}
