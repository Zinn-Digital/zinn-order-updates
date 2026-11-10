<?php
/**
 * The store's WhatsApp button and the PBS dynamic data (docs/1297 §15.1, master row 1108#8 P2).
 *
 * - `zinn-ou/whatsapp-button`: a "message us on WhatsApp" link to the store's OWN number, the one
 *   it connected through Embedded Signup. Zinn® reports it on the status read
 *   (`whatsapp_number`); this plugin keeps the last good value in an option so a page view never
 *   calls Zinn®. No number = the block renders nothing: never a broken or empty link.
 * - PBS Pro dynamic data, source `zinn-ou/updates`, fields `opted_in`, `channel` and
 *   `whatsapp_link`: a PBS page can show "Get updates on WhatsApp" only to a shopper who has
 *   not opted in. A signed-in shopper's tick at checkout is remembered on their account; a guest
 *   reads as not opted in.
 *
 * @package ZinnOrderUpdates
 */

defined( 'ABSPATH' ) || exit;

/**
 * The WhatsApp button block and the `zinn-ou/updates` dynamic source.
 */
final class Zinn_Order_Updates_Whatsapp {

	public const BLOCK         = 'zinn-ou/whatsapp-button';
	public const HANDLE        = 'zinn-ou-whatsapp-block';
	public const SOURCE        = 'zinn-ou/updates';
	public const NUMBER_OPTION = 'zinn_order_updates_whatsapp_number';
	public const USER_META     = '_zinn_ou_channel';
	public const FIELDS        = array( 'opted_in', 'channel', 'whatsapp_link' );
	private const SHOW         = array( 'everyone', 'not_opted_in' );

	/**
	 * Hooks. The PBS filters cost nothing when PBS Pro is absent: they simply never fire.
	 */
	public static function register(): void {
		add_filter( 'pbsw_dynamic_sources', array( self::class, 'sources' ) );
		add_filter( 'pbsw_dynamic_value', array( self::class, 'value' ), 10, 4 );
		add_filter( 'pbsw_dynamic_editor_sources', array( self::class, 'editor_sources' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
		self::register_block();
	}

	/**
	 * Keep the number Zinn® reported (status read). Anything not E.164 clears it.
	 *
	 * @param mixed $status The decoded status body.
	 */
	public static function remember( $status ): void {
		if ( ! is_array( $status ) || ! array_key_exists( 'whatsapp_number', $status ) ) {
			return; // An older engine, or no answer: keep what we had.
		}
		$number = is_string( $status['whatsapp_number'] ) && preg_match( '/^\+[1-9]\d{6,14}$/', $status['whatsapp_number'] ) ? $status['whatsapp_number'] : '';
		if ( (string) get_option( self::NUMBER_OPTION, '' ) !== $number ) {
			update_option( self::NUMBER_OPTION, $number, true );
		}
	}

	/**
	 * The `https://wa.me/<digits>` link to the store's own number, or '' when there is none.
	 */
	public static function link(): string {
		$number = (string) get_option( self::NUMBER_OPTION, '' );
		if ( ! preg_match( '/^\+[1-9]\d{6,14}$/', $number ) ) {
			return '';
		}
		return 'https://wa.me/' . substr( $number, 1 );
	}

	/**
	 * Remember a signed-in shopper's checkout tick on their account (the channel they chose).
	 *
	 * @param object $order The order.
	 * @param string $channel `sms` or `whatsapp`.
	 */
	public static function remember_shopper( $order, string $channel ): void {
		$user = is_object( $order ) && method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
		if ( $user > 0 && in_array( $channel, array( 'sms', 'whatsapp' ), true ) ) {
			update_user_meta( $user, self::USER_META, $channel );
		}
	}

	/**
	 * The current visitor's order-updates channel: `sms`, `whatsapp`, or '' (guest or not opted in).
	 */
	public static function channel(): string {
		$user = get_current_user_id();
		if ( $user <= 0 ) {
			return '';
		}
		$channel = (string) get_user_meta( $user, self::USER_META, true );
		return in_array( $channel, array( 'sms', 'whatsapp' ), true ) ? $channel : '';
	}

	/**
	 * The source for PBS (front end and editor).
	 *
	 * @param mixed $labels Sources: name => label.
	 * @return array<string, string>
	 */
	public static function sources( $labels ): array {
		$labels                 = (array) $labels;
		$labels[ self::SOURCE ] = __( 'Order updates', 'zinn-order-updates' );
		return $labels;
	}

	/**
	 * A field of `zinn-ou/updates`. `opted_in` is `yes` or `no`; `channel` is `sms`, `whatsapp`
	 * or ''; `whatsapp_link` is the wa.me link or ''. Another source passes through untouched.
	 *
	 * @param mixed $value  The value so far.
	 * @param mixed $source Source name.
	 * @param mixed $args   Binding args.
	 * @param mixed $ctx    Item context.
	 * @return mixed
	 */
	public static function value( $value, $source, $args = array(), $ctx = array() ) {
		unset( $ctx );
		if ( self::SOURCE !== $source ) {
			return $value;
		}
		switch ( sanitize_key( (string) ( ( (array) $args )['field'] ?? '' ) ) ) {
			case 'opted_in':
				return '' !== self::channel() ? 'yes' : 'no';
			case 'channel':
				return self::channel();
			case 'whatsapp_link':
				return self::link();
			default:
				return null;
		}
	}

	/**
	 * The source's fields in the PBS editor picker.
	 *
	 * @param mixed $sources Editor sources.
	 * @return array<int, mixed>
	 */
	public static function editor_sources( $sources ): array {
		$sources = (array) $sources;
		$labels  = array(
			'opted_in'      => __( 'Opted in (yes or no)', 'zinn-order-updates' ),
			'channel'       => __( 'Channel (SMS or WhatsApp)', 'zinn-order-updates' ),
			'whatsapp_link' => __( 'WhatsApp link', 'zinn-order-updates' ),
		);
		$fields  = array();
		foreach ( self::FIELDS as $f ) {
			$fields[] = array(
				'value' => $f,
				'label' => $labels[ $f ],
			);
		}
		$sources[] = array(
			'value'  => self::SOURCE,
			'label'  => __( 'Order updates', 'zinn-order-updates' ),
			'fields' => $fields,
		);
		return $sources;
	}

	/**
	 * The button block's label, translated.
	 */
	public static function default_label(): string {
		return __( 'Message us on WhatsApp', 'zinn-order-updates' );
	}

	/**
	 * The dynamic block.
	 */
	private static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/whatsapp-block.js', ZINN_ORDER_UPDATES_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			ZINN_ORDER_UPDATES_VERSION,
			true
		);
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Zinn® WhatsApp button', 'zinn-order-updates' ),
				'category'        => Zinn_Order_Updates_Optin::CATEGORY,
				'editor_script'   => self::HANDLE,
				'attributes'      => array(
					'label'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'showTo' => array(
						'type'    => 'string',
						'enum'    => self::SHOW,
						'default' => 'everyone',
					),
				),
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
			'title'       => __( 'Zinn® WhatsApp button', 'zinn-order-updates' ),
			'description' => __( 'A button that opens a WhatsApp chat with your store. It shows only once your WhatsApp number is connected in Zinn® Campaigns.', 'zinn-order-updates' ),
			'settings'    => __( 'Button settings', 'zinn-order-updates' ),
			'label'       => __( 'Button text', 'zinn-order-updates' ),
			'placeholder' => self::default_label(),
			'showTo'      => __( 'Show to', 'zinn-order-updates' ),
			'showOptions' => array(
				array(
					'value' => 'everyone',
					'label' => __( 'Everyone', 'zinn-order-updates' ),
				),
				array(
					'value' => 'not_opted_in',
					'label' => __( 'Shoppers not yet getting order updates', 'zinn-order-updates' ),
				),
			),
			'noNumber'    => __( 'Connect your WhatsApp number in Zinn® Campaigns to show this button. Until then it shows nothing on your site.', 'zinn-order-updates' ),
		);
		wp_add_inline_script( self::HANDLE, 'window.zinnOuWhatsapp = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * The block's markup: the link, or nothing at all.
	 *
	 * @param mixed $attributes Block attributes.
	 */
	public static function render_block( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$link       = self::link();
		if ( '' === $link ) {
			return ''; // No connected number: never a broken link (docs/1297 §15.1).
		}
		if ( 'not_opted_in' === (string) ( $attributes['showTo'] ?? '' ) && '' !== self::channel() ) {
			return '';
		}
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		$label = '' !== $label ? $label : self::default_label();
		$wrap  = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'wp-block-buttons' ) ) : 'class="wp-block-buttons"';
		return sprintf(
			'<div %1$s><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></div></div>',
			$wrap,
			esc_url( $link ),
			esc_html( $label )
		);
	}
}
