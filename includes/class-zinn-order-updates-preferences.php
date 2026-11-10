<?php
/**
 * The shopper's "Messages" panel (docs/1297 §8.3): a WooCommerce My Account tab and the
 * `zinn-ou/preferences` block (for any account or profile page, including PBS-built ones).
 *
 * It shows the shopper's number (editable), separate toggles for order updates and offers,
 * one-click "Stop all messages", and the last 20 texts this store sent them, read from Zinn®
 * (`POST /v1/store-connect/shopper`). A change is posted as consents (source `my_account`); only
 * a toggle that CHANGED is posted, so the original checkout proof of an unchanged one is kept.
 *
 * ⛔ A number is the shopper's only when it is the billing phone on one of THEIR OWN orders
 * (`wc_get_orders( customer_id )`); an account number is typed, so it proves nothing. Any other
 * number shows nothing (no texts, no consents; Zinn® is not even asked) and changes nothing, and
 * the history lists only texts about the shopper's own orders (VAPI-r2 D1).
 *
 * @package ZinnOrderUpdates
 */

defined( 'ABSPATH' ) || exit;

/**
 * My Account "Messages" and the preferences block.
 */
final class Zinn_Order_Updates_Preferences {

	public const ENDPOINT  = 'messages';
	public const BLOCK     = 'zinn-ou/preferences';
	public const ACTION    = 'zinn_ou_prefs';
	public const RESULT    = 'zinn_ou_prefs';
	public const WORDING_V = 'prefs-1';
	private const HANDLE   = 'zinn-ou-preferences-block';
	private const FLUSHED  = 'zinn_order_updates_messages_rewrite';

	/**
	 * The three toggles: channel and purpose. Order updates are sent by text only today.
	 */
	public const TOGGLES = array( 'sms:updates', 'sms:marketing', 'whatsapp:marketing' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'menu' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( self::class, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( self::class, 'account_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * The tab's URL slug, translated (§8.3), and one rewrite flush per plugin version.
	 */
	public static function endpoint(): void {
		add_rewrite_endpoint( self::slug(), EP_ROOT | EP_PAGES );
		// WooCommerce reads its query vars when it loads, before this plugin's init hooks run.
		if ( function_exists( 'WC' ) && WC()->query && is_array( WC()->query->query_vars ) ) {
			WC()->query->query_vars[ self::ENDPOINT ] = self::slug();
		}
		if ( get_option( self::FLUSHED ) !== ZINN_ORDER_UPDATES_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::FLUSHED, ZINN_ORDER_UPDATES_VERSION, true );
		}
	}

	/**
	 * The endpoint's slug in the site's language: a URL slug, so lower case letters and dashes.
	 */
	public static function slug(): string {
		/* translators: URL slug of the My Account tab where shoppers manage their text messages. Lower case, no spaces. */
		$slug = sanitize_title( _x( 'messages', 'my account endpoint slug', 'zinn-order-updates' ) );
		return '' !== $slug ? $slug : self::ENDPOINT;
	}

	/**
	 * WooCommerce's query vars: our endpoint, under its translated slug.
	 *
	 * @param mixed $vars Endpoint => slug.
	 * @return array<string, string>
	 */
	public static function query_vars( $vars ): array {
		$vars                   = is_array( $vars ) ? $vars : array();
		$vars[ self::ENDPOINT ] = self::slug();
		return $vars;
	}

	/**
	 * The tab, before "Log out" (or last).
	 *
	 * @param mixed $items Endpoint => label.
	 * @return array<string, string>
	 */
	public static function menu( $items ): array {
		$items  = is_array( $items ) ? $items : array();
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = self::title();
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * The tab's title.
	 */
	public static function title(): string {
		return __( 'Messages', 'zinn-order-updates' );
	}

	/**
	 * The signed-in shopper's number in E.164 (their billing phone), or ''.
	 *
	 * @param int $user The user id.
	 */
	public static function phone( int $user ): string {
		if ( $user <= 0 ) {
			return '';
		}
		return Zinn_Order_Updates_Events::e164( (string) get_user_meta( $user, 'billing_phone', true ), (string) get_user_meta( $user, 'billing_country', true ) );
	}

	/**
	 * The shopper's own orders: order id => the billing phone in E.164 (as the event sent it).
	 *
	 * @param int $user The user id.
	 * @return array<string, string>
	 */
	public static function own_orders( int $user ): array {
		if ( $user <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_orders(
			array(
				'customer_id' => $user,
				'limit'       => -1,
				'type'        => 'shop_order',
			)
		) as $order ) {
			if ( is_object( $order ) ) {
				$out[ (string) $order->get_id() ] = Zinn_Order_Updates_Events::e164( (string) $order->get_billing_phone(), (string) $order->get_billing_country() );
			}
		}
		return $out;
	}

	/**
	 * Whether a number is the shopper's: the billing phone on one of their own orders.
	 *
	 * @param string                $phone E.164.
	 * @param array<string, string> $own   own_orders().
	 */
	public static function proven( string $phone, array $own ): bool {
		return '' !== $phone && in_array( $phone, $own, true );
	}

	/**
	 * Keep only the texts about the shopper's own orders.
	 *
	 * @param array<string, mixed>|null $shopper The read.
	 * @param array<string, string>     $own     own_orders().
	 * @return array<string, mixed>|null
	 */
	public static function only_own( ?array $shopper, array $own ): ?array {
		if ( null === $shopper ) {
			return null;
		}
		$shopper['messages'] = array_values(
			array_filter(
				(array) ( $shopper['messages'] ?? array() ),
				static fn( $m ) => is_array( $m ) && isset( $own[ (string) ( $m['ref'] ?? '' ) ] )
			)
		);
		return $shopper;
	}

	/**
	 * Read the shopper's preferences and recent texts from Zinn®. Null when Zinn® could not answer.
	 *
	 * @param string $phone E.164.
	 * @return array<string, mixed>|null
	 */
	public static function read( string $phone ): ?array {
		if ( '' === $phone || '' === Zinn_Order_Updates_Api::token() ) {
			return null;
		}
		list( $code, $body ) = Zinn_Order_Updates_Api::call( 'POST', '/v1/store-connect/shopper', array( 'phone' => $phone ) );
		return 200 === $code ? $body : null;
	}

	/**
	 * The toggles as Zinn® holds them: "channel:purpose" => on.
	 *
	 * @param array<string, mixed>|null $shopper The read.
	 * @return array<string, bool>
	 */
	public static function current( ?array $shopper ): array {
		$map = array_fill_keys( self::TOGGLES, false );
		foreach ( (array) ( $shopper['consents'] ?? array() ) as $c ) {
			$key = (string) ( $c['channel'] ?? '' ) . ':' . (string) ( $c['purpose'] ?? '' );
			if ( isset( $map[ $key ] ) ) {
				$map[ $key ] = ! empty( $c['active'] );
			}
		}
		return $map;
	}

	/**
	 * The consents to post: only what changed. A new number withdraws everything on the old one
	 * and records the wanted toggles on the new one.
	 *
	 * @param array<string, bool> $current   What Zinn® holds for the old number.
	 * @param array<string, bool> $wanted    What the shopper chose.
	 * @param string              $old_phone The number the panel showed.
	 * @param string              $new_phone The number the shopper saved.
	 * @param string              $lang      The page language.
	 * @return array<int, array<string, mixed>>
	 */
	public static function changes( array $current, array $wanted, string $old_phone, string $new_phone, string $lang ): array {
		$out = array();
		foreach ( self::TOGGLES as $key ) {
			$was = ! empty( $current[ $key ] );
			$now = ! empty( $wanted[ $key ] );
			if ( $old_phone !== $new_phone ) {
				if ( $was && '' !== $old_phone ) {
					$out[] = self::item( $old_phone, $key, false, $lang );
				}
				if ( $now ) {
					$out[] = self::item( $new_phone, $key, true, $lang );
				}
			} elseif ( $was !== $now ) {
				$out[] = self::item( $new_phone, $key, $now, $lang );
			}
		}
		return $out;
	}

	/**
	 * One consent record (§9.1: the wording the shopper saw is the proof).
	 *
	 * @param string $phone E.164.
	 * @param string $key   "channel:purpose".
	 * @param bool   $on    Given (true) or withdrawn (false).
	 * @param string $lang  The page language.
	 * @return array<string, mixed>
	 */
	public static function item( string $phone, string $key, bool $on, string $lang ): array {
		list( $channel, $purpose ) = explode( ':', $key );
		return array(
			'phone'       => $phone,
			'channel'     => $channel,
			'purpose'     => $purpose,
			'ref'         => 'my_account',
			'captured_at' => gmdate( DATE_ATOM ),
			'withdrawn'   => ! $on,
			'evidence'    => array(
				'wording'         => self::label( $key ),
				'wording_version' => self::WORDING_V,
				'language'        => Zinn_Order_Updates_Locale::clean( $lang ),
				'source'          => 'my_account',
			),
		);
	}

	/**
	 * A toggle's label (also the consent wording).
	 *
	 * @param string $key "channel:purpose".
	 */
	public static function label( string $key ): string {
		switch ( $key ) {
			case 'sms:updates':
				return __( 'Text me updates about my orders', 'zinn-order-updates' );
			case 'sms:marketing':
				return __( 'Text me offers and news from this store', 'zinn-order-updates' );
			default:
				return __( 'Send me offers and news from this store on WhatsApp', 'zinn-order-updates' );
		}
	}

	/**
	 * A message kind, in words.
	 *
	 * @param string $kind The event kind.
	 */
	public static function kind_label( string $kind ): string {
		$labels = array(
			'order_received'    => __( 'Order received', 'zinn-order-updates' ),
			'payment_confirmed' => __( 'Payment received', 'zinn-order-updates' ),
			'on_hold'           => __( 'Order on hold', 'zinn-order-updates' ),
			'shipped'           => __( 'Order shipped', 'zinn-order-updates' ),
			'completed'         => __( 'Order completed', 'zinn-order-updates' ),
			'ready_for_pickup'  => __( 'Ready for pickup', 'zinn-order-updates' ),
			'payment_failed'    => __( 'Payment failed', 'zinn-order-updates' ),
			'cancelled'         => __( 'Order cancelled', 'zinn-order-updates' ),
			'refunded'          => __( 'Order refunded', 'zinn-order-updates' ),
		);
		return $labels[ $kind ] ?? __( 'Order update', 'zinn-order-updates' );
	}

	/**
	 * Why a number shows nothing and cannot be changed.
	 */
	public static function unproven_text(): string {
		return __( 'Your message settings cover the mobile number on your orders with this store. Use the number from one of your orders.', 'zinn-order-updates' );
	}

	/**
	 * The result notice after a save.
	 *
	 * @param string $result `saved`, `stopped`, `phone`, `unproven` or `error`.
	 */
	public static function notice( string $result ): string {
		$text = array(
			'saved'    => __( 'Your message settings are saved.', 'zinn-order-updates' ),
			'stopped'  => __( 'Done. This store will not send you any more messages.', 'zinn-order-updates' ),
			'phone'    => __( 'Please enter a mobile number with its country code, for example +44 7700 900123.', 'zinn-order-updates' ),
			'error'    => __( 'We could not save your settings just now. Please try again in a minute.', 'zinn-order-updates' ),
			'unproven' => self::unproven_text(),
		);
		return isset( $text[ $result ] ) ? '<p class="zinn-ou-prefs__notice" role="status">' . esc_html( $text[ $result ] ) . '</p>' : '';
	}

	/**
	 * The panel's markup.
	 *
	 * @param string                    $phone   E.164, or '' when the shopper has none yet.
	 * @param array<string, mixed>|null $shopper The read, or null when Zinn® could not answer.
	 * @param string                    $result  The last save's result.
	 * @param bool                      $proven  Whether the number is on one of the shopper's own orders.
	 */
	public static function panel( string $phone, ?array $shopper, string $result = '', bool $proven = true ): string {
		$shopper = $proven ? $shopper : array();
		$on      = self::current( $shopper );
		$html    = '<div class="zinn-ou-prefs">' . self::notice( $result );
		if ( ! $proven && '' !== $phone && 'unproven' !== $result ) {
			$html .= '<p>' . esc_html( self::unproven_text() ) . '</p>';
		}
		if ( '' !== $phone && null === $shopper ) {
			$html .= '<p>' . esc_html__( 'Your message settings cannot be shown just now. Please try again in a minute.', 'zinn-order-updates' ) . '</p></div>';
			return $html;
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		$html .= wp_nonce_field( self::ACTION, '_zinn_ou_prefs_nonce', true, false );
		$html .= '<p><label for="zinn-ou-prefs-phone">' . esc_html__( 'Mobile number', 'zinn-order-updates' ) . '</label> ';
		$html .= '<input type="tel" id="zinn-ou-prefs-phone" name="zinn_ou_phone" autocomplete="tel" dir="ltr" value="' . esc_attr( $phone ) . '" /></p>';
		$html .= '<fieldset><legend>' . esc_html__( 'Messages from this store', 'zinn-order-updates' ) . '</legend>';
		foreach ( self::TOGGLES as $key ) {
			$id    = 'zinn-ou-prefs-' . str_replace( ':', '-', $key );
			$html .= '<p><input type="checkbox" id="' . esc_attr( $id ) . '" name="zinn_ou_on[]" value="' . esc_attr( $key ) . '"' . ( $on[ $key ] ? ' checked="checked"' : '' ) . ' /> ';
			$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( self::label( $key ) ) . '</label></p>';
		}
		$html    .= '</fieldset>';
		$html    .= '<p><button type="submit" name="zinn_ou_do" value="save" class="button">' . esc_html__( 'Save', 'zinn-order-updates' ) . '</button> ';
		$html    .= '<button type="submit" name="zinn_ou_do" value="stop" class="button">' . esc_html__( 'Stop all messages', 'zinn-order-updates' ) . '</button></p>';
		$html    .= '</form>';
		$html    .= '<h3>' . esc_html__( 'Recent messages', 'zinn-order-updates' ) . '</h3>';
		$messages = (array) ( $shopper['messages'] ?? array() );
		if ( array() === $messages ) {
			$html .= '<p>' . esc_html__( 'This store has not sent you any messages yet.', 'zinn-order-updates' ) . '</p>';
		} else {
			$html .= '<ul class="zinn-ou-prefs__history">';
			foreach ( $messages as $m ) {
				$at    = strtotime( (string) ( $m['sent_at'] ?? '' ) );
				$when  = $at ? wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $at ) : '';
				$html .= '<li>' . esc_html(
					sprintf(
						/* translators: 1: what the message was about, e.g. "Order shipped", 2: the order number, 3: the date it was sent. */
						__( '%1$s, order %2$s, %3$s', 'zinn-order-updates' ),
						self::kind_label( (string) ( $m['kind'] ?? '' ) ),
						(string) ( $m['order_number'] ?? '' ),
						(string) $when
					)
				) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '</div>';
	}

	/**
	 * The My Account tab.
	 */
	public static function account_page(): void {
		echo self::render_block( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- panel() escapes every value it prints.
	}

	/**
	 * The block (and the tab): the panel for a signed-in shopper, a sign-in link for a guest.
	 *
	 * @param mixed $attributes Block attributes (none).
	 */
	public static function render_block( $attributes = array() ): string {
		unset( $attributes );
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return '<p class="zinn-ou-prefs"><a href="' . esc_url( wp_login_url( (string) get_permalink() ) ) . '">' . esc_html__( 'Sign in to manage the messages this store sends you.', 'zinn-order-updates' ) . '</a></p>';
		}
		$phone  = self::phone( $user );
		$own    = self::own_orders( $user );
		$proven = self::proven( $phone, $own );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which fixed notice to show.
		$result = isset( $_GET[ self::RESULT ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT ] ) ) : '';
		return self::panel( $phone, $proven ? self::only_own( self::read( $phone ), $own ) : array(), $result, $proven );
	}

	/**
	 * Save the panel (signed-in shoppers with `read` only, nonce-checked; it changes only their own number's consents), then go back with the result.
	 */
	public static function handle(): void {
		$back = wp_get_referer() ? (string) wp_get_referer() : home_url( '/' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- submit() verifies the nonce before it reads anything else.
		wp_safe_redirect( self::submit( wp_unslash( $_POST ), $back ) );
		exit;
	}

	/**
	 * The gate and the save, pure of the redirect: where to send the shopper back to. Signed-in,
	 * `read` and a good nonce, or nothing happens (D78081).
	 *
	 * @param array<string, mixed> $post The unslashed form post.
	 * @param string               $back Where the form was.
	 */
	public static function submit( array $post, string $back ): string {
		$user = get_current_user_id();
		if ( $user <= 0 || ! current_user_can( 'read' ) || ! isset( $post['_zinn_ou_prefs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $post['_zinn_ou_prefs_nonce'] ), self::ACTION ) ) {
			return $back;
		}
		$in     = array(
			'phone' => isset( $post['zinn_ou_phone'] ) ? sanitize_text_field( (string) $post['zinn_ou_phone'] ) : '',
			'do'    => isset( $post['zinn_ou_do'] ) ? sanitize_key( (string) $post['zinn_ou_do'] ) : 'save',
			'on'    => isset( $post['zinn_ou_on'] ) ? array_map( 'sanitize_text_field', array_map( 'strval', (array) $post['zinn_ou_on'] ) ) : array(),
		);
		$result = self::save( $user, $in, determine_locale() );
		return add_query_arg( self::RESULT, $result, $back );
	}

	/**
	 * Apply a save (pure of redirects). Returns `saved`, `stopped`, `phone`, `unproven` or `error`.
	 * Only a number on one of the shopper's own orders is changed: the new one, and the old one
	 * when its consents are withdrawn.
	 *
	 * @param int                  $user The signed-in user.
	 * @param array<string, mixed> $in   phone, do (`save`|`stop`), on (list of toggles).
	 * @param string               $lang The page language.
	 */
	public static function save( int $user, array $in, string $lang ): string {
		$old  = self::phone( $user );
		$raw  = trim( (string) ( $in['phone'] ?? '' ) );
		$new  = '' === $raw ? '' : Zinn_Order_Updates_Events::e164( $raw, (string) get_user_meta( $user, 'billing_country', true ) );
		$stop = 'stop' === ( $in['do'] ?? '' );
		if ( '' === $new && ! ( $stop && '' !== $old ) ) {
			return 'phone';
		}
		$new = $stop ? $old : $new;
		$own = self::own_orders( $user );
		if ( ! self::proven( $new, $own ) ) {
			return 'unproven';
		}
		// The old number's consents are withdrawn only when it was the shopper's too.
		$old  = self::proven( $old, $own ) ? $old : '';
		$read = '' !== $old ? self::read( $old ) : array();
		if ( null === $read ) {
			return 'error';
		}
		$wanted = array_fill_keys( self::TOGGLES, false );
		if ( ! $stop ) {
			foreach ( (array) ( $in['on'] ?? array() ) as $key ) {
				if ( isset( $wanted[ (string) $key ] ) ) {
					$wanted[ (string) $key ] = true;
				}
			}
		}
		$items = self::changes( self::current( $read ), $wanted, $old, $new, $lang );
		if ( array() !== $items ) {
			list( $code ) = Zinn_Order_Updates_Api::call( 'POST', '/v1/store-connect/consents', array( 'consents' => $items ) );
			if ( 200 !== $code ) {
				return 'error';
			}
		}
		if ( $new !== $old ) {
			update_user_meta( $user, 'billing_phone', $new );
		}
		update_user_meta( $user, Zinn_Order_Updates_Whatsapp::USER_META, $wanted['sms:updates'] ? 'sms' : '' );
		return $stop ? 'stopped' : 'saved';
	}

	/**
	 * The block (server-rendered; no front-end script).
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( self::HANDLE, plugins_url( 'assets/preferences-block.js', ZINN_ORDER_UPDATES_FILE ), array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render' ), ZINN_ORDER_UPDATES_VERSION, true );
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Zinn® message settings', 'zinn-order-updates' ),
				'category'        => Zinn_Order_Updates_Optin::CATEGORY,
				'editor_script'   => self::HANDLE,
				'supports'        => array( 'html' => false ),
				'render_callback' => array( self::class, 'render_block' ),
			)
		);
	}

	/**
	 * The editor's strings, translated in PHP.
	 */
	public static function editor_data(): void {
		$data = array(
			'title'       => __( 'Zinn® message settings', 'zinn-order-updates' ),
			'description' => __( 'Lets a signed-in shopper change the text messages your store sends them, stop them all, and see the last 20.', 'zinn-order-updates' ),
		);
		wp_add_inline_script( self::HANDLE, 'window.zinnOuPreferences = ' . wp_json_encode( $data ) . ';', 'before' );
	}
}
