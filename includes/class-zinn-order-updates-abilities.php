<?php
/**
 * Zinn® Order Updates: WordPress Abilities (docs/1297 §22.3).
 *
 * Three abilities an AI assistant (or any Abilities API client, such as an MCP adapter) may use
 * on this store, each behind a REAL permission check:
 *
 * - `zinn-ou/get-order-messages` (`edit_shop_orders`): every update for one order, newest first.
 * - `zinn-ou/send-order-update` (`edit_shop_orders`): send one APPROVED template to the order's
 *   own shopper. Never free text, never another number: the engine takes the phone, language and
 *   details from the order's own events, and every Campaigns guard (consent, good standing, the
 *   daily limit per order) still applies. `request_id` makes a retry safe.
 * - `zinn-ou/get-health` (`manage_options`): the connection as Zinn® sees it.
 *
 * The Abilities API is WordPress 6.9 core; on an older WordPress nothing is registered (the
 * plugin still works).
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's abilities.
 */
final class Zinn_Order_Updates_Abilities {

	/** The ability category. */
	public const CATEGORY = 'zinn-order-updates';

	/** Who may read an order's messages or send an update. */
	public const ORDERS_CAP = 'edit_shop_orders';

	/** Who may read the connection's health. */
	public const HEALTH_CAP = 'manage_options';

	/** The approved templates staff may send (engine `order_updates.TEMPLATES`). */
	public const KINDS = array(
		'order_received',
		'payment_confirmed',
		'on_hold',
		'shipped',
		'completed',
		'ready_for_pickup',
		'payment_failed',
		'cancelled',
		'refunded',
	);

	/** The engine's idempotency-key shape. */
	public const REQUEST_ID_PATTERN = '^[A-Za-z0-9._-]{8,64}$';

	/**
	 * Hooks the registrations (both fire only on a WordPress with the Abilities API).
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * The category the three abilities sit in.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Zinn® Order Updates', 'zinn-order-updates' ),
				'description' => __( 'Read and send SMS and WhatsApp order updates for this store.', 'zinn-order-updates' ),
			)
		);
	}

	/**
	 * The three abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$order_id = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'The WooCommerce order ID.', 'zinn-order-updates' ),
		);

		wp_register_ability(
			'zinn-ou/get-order-messages',
			array(
				'label'               => __( 'Get order messages', 'zinn-order-updates' ),
				'description'         => __( 'Lists every SMS or WhatsApp update for one order, newest first, with its outcome. The phone number is masked.', 'zinn-order-updates' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'order_id' => $order_id ),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ref'       => array( 'type' => 'string' ),
						'messages'  => array( 'type' => 'array' ),
						'templates' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( self::class, 'get_order_messages' ),
				'permission_callback' => array( self::class, 'can_manage_orders' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		wp_register_ability(
			'zinn-ou/send-order-update',
			array(
				'label'               => __( 'Send an order update', 'zinn-order-updates' ),
				'description'         => __( 'Sends one approved order update (never free text) to the shopper of the order, only if they agreed to receive it. Reusing a request_id never sends twice.', 'zinn-order-updates' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id'     => $order_id,
						'kind'         => array(
							'type'        => 'string',
							'enum'        => self::KINDS,
							'description' => __( 'Which approved update to send.', 'zinn-order-updates' ),
						),
						'request_id'   => array(
							'type'        => 'string',
							'pattern'     => self::REQUEST_ID_PATTERN,
							'description' => __( 'A unique ID for this send (8 to 64 letters, digits, dots, dashes or underscores). Send the same ID again to retry safely.', 'zinn-order-updates' ),
						),
						'tracking_url' => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'Optional https tracking link for a shipped update.', 'zinn-order-updates' ),
						),
					),
					'required'             => array( 'order_id', 'kind', 'request_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'string' ),
						'kind'      => array( 'type' => 'string' ),
						'outcome'   => array( 'type' => 'string' ),
						'duplicate' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( self::class, 'send_order_update' ),
				'permission_callback' => array( self::class, 'can_manage_orders' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		wp_register_ability(
			'zinn-ou/get-health',
			array(
				'label'               => __( 'Get connection health', 'zinn-order-updates' ),
				'description'         => __( 'Shows whether this store is connected to Zinn® Campaigns and what Zinn® Campaigns sees for it.', 'zinn-order-updates' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'connected' => array( 'type' => 'boolean' ),
						'reachable' => array( 'type' => 'boolean' ),
						'version'   => array( 'type' => 'string' ),
						'store'     => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( self::class, 'get_health' ),
				'permission_callback' => array( self::class, 'can_read_health' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Permission for the two order abilities.
	 */
	public static function can_manage_orders(): bool {
		return current_user_can( self::ORDERS_CAP );
	}

	/**
	 * Permission for the health ability.
	 */
	public static function can_read_health(): bool {
		return current_user_can( self::HEALTH_CAP );
	}

	/**
	 * `zinn-ou/get-order-messages`.
	 *
	 * @param mixed $input `{order_id}`.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_order_messages( $input = null ) {
		$order_id = self::order_id( $input );
		if ( $order_id instanceof WP_Error ) {
			return $order_id;
		}
		list( $code, $body ) = Zinn_Order_Updates_Api::call( 'GET', '/v1/store-connect/orders/' . $order_id . '/messages' );
		return 200 === $code ? $body : self::refusal( $code, $body );
	}

	/**
	 * `zinn-ou/send-order-update`.
	 *
	 * @param mixed $input `{order_id, kind, request_id, tracking_url?}`.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function send_order_update( $input = null ) {
		$order_id = self::order_id( $input );
		if ( $order_id instanceof WP_Error ) {
			return $order_id;
		}
		$kind       = (string) ( $input['kind'] ?? '' );
		$request_id = (string) ( $input['request_id'] ?? '' );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new WP_Error( 'zinn_ou_kind', __( 'Choose one of the approved order updates.', 'zinn-order-updates' ), array( 'status' => 400 ) );
		}
		if ( 1 !== preg_match( '/' . self::REQUEST_ID_PATTERN . '/', $request_id ) ) {
			return new WP_Error( 'zinn_ou_request_id', __( 'The request ID must be 8 to 64 letters, digits, dots, dashes or underscores.', 'zinn-order-updates' ), array( 'status' => 400 ) );
		}
		$body     = array(
			'kind'       => $kind,
			'request_id' => $request_id,
		);
		$tracking = trim( (string) ( $input['tracking_url'] ?? '' ) );
		if ( '' !== $tracking ) {
			// The engine is the authority on what link it accepts (a 422 comes back as an error).
			$body['tracking_url'] = $tracking;
		}
		list( $code, $answer ) = Zinn_Order_Updates_Api::call( 'POST', '/v1/store-connect/orders/' . $order_id . '/updates', $body );
		return 202 === $code ? $answer : self::refusal( $code, $answer );
	}

	/**
	 * `zinn-ou/get-health`.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_health(): array {
		$status = Zinn_Order_Updates_Admin::status();
		return array(
			'connected' => null !== $status,
			'reachable' => null !== $status && empty( $status['unreachable'] ),
			'version'   => ZINN_ORDER_UPDATES_VERSION,
			'store'     => null !== $status && empty( $status['unreachable'] ) ? $status : (object) array(),
		);
	}

	/**
	 * The order ID from the input, refused unless it is one of this store's orders and the store
	 * is connected.
	 *
	 * @param mixed $input Ability input.
	 * @return int|WP_Error
	 */
	private static function order_id( $input ) {
		if ( '' === Zinn_Order_Updates_Api::token() ) {
			return new WP_Error( 'zinn_ou_not_connected', __( 'Connect this store to Zinn® Campaigns first.', 'zinn-order-updates' ), array( 'status' => 409 ) );
		}
		$order_id = is_array( $input ) && isset( $input['order_id'] ) && is_numeric( $input['order_id'] ) ? (int) $input['order_id'] : 0;
		if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) || ! wc_get_order( $order_id ) ) {
			return new WP_Error( 'zinn_ou_order_unknown', __( 'There is no order with that ID on this store.', 'zinn-order-updates' ), array( 'status' => 404 ) );
		}
		return $order_id;
	}

	/**
	 * A refusal from the Zinn® API as an error the caller can act on.
	 *
	 * @param int                  $code HTTP status (0 = the request did not reach Zinn®).
	 * @param array<string, mixed> $body Decoded body.
	 */
	private static function refusal( int $code, array $body ): WP_Error {
		if ( 0 === $code ) {
			return new WP_Error( 'zinn_ou_unreachable', __( 'Zinn® Campaigns could not be reached. Try again in a minute.', 'zinn-order-updates' ), array( 'status' => 503 ) );
		}
		$error   = is_array( $body['error'] ?? null ) ? $body['error'] : array();
		$details = is_array( $error['details'] ?? null ) ? $error['details'] : array();
		$detail  = is_array( $details[0] ?? null ) ? $details[0] : array();
		$reason  = (string) ( $detail['code'] ?? $error['code'] ?? 'error' );
		$message = (string) ( $error['message'] ?? '' );
		if ( '' === $message ) {
			$message = __( 'Zinn® Campaigns refused the request.', 'zinn-order-updates' );
		}
		return new WP_Error(
			'zinn_ou_' . preg_replace( '/[^A-Za-z0-9_]/', '', $reason ),
			$message,
			array(
				'status' => $code,
				'field'  => (string) ( $detail['field'] ?? '' ),
			)
		);
	}
}
