<?php
/**
 * Order updates: the shopper's checkout opt-in and the order events (docs/1297 §8, §11.1).
 *
 * The opt-in is one unticked box at checkout (classic and block), using the checkout's own
 * billing phone. Order status changes are queued (Action Scheduler when present, else WP-Cron)
 * and posted to Zinn, which sends the update in the shopper's language. A failed post is
 * retried a few times; the engine's idempotency key means a retry never sends twice.
 *
 * @package ZinnOrderUpdates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout opt-in and order-event delivery.
 */
final class Zinn_Order_Updates_Events {

	public const OPTIN_META = '_zinn_ou_sms_updates';
	public const FIELD      = 'zinn_ou_sms_updates';
	public const BLOCK_ID   = 'zinn-ou/sms-updates';
	public const HOOK       = 'zinn_order_updates_send';
	public const WORDING_V  = '1';
	private const RETRIES   = 5;

	private const ZERO_DECIMAL  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
	private const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Orders created by THIS request's checkout (id => true). A non-actionable status change in the
	 * same request (a cheque or bank order set on-hold, a card set processing) sends no text of
	 * its own: "order received" already tells the shopper (2026-11-10 pilot, docs/72).
	 *
	 * @var array<int, bool>
	 */
	private static $in_checkout = array();

	/**
	 * WooCommerce order status → the event kind the engine has a template for.
	 */
	private const KINDS = array(
		'processing' => 'payment_confirmed',
		'on-hold'    => 'on_hold',
		'completed'  => 'completed',
		'cancelled'  => 'cancelled',
		'refunded'   => 'refunded',
		'failed'     => 'payment_failed',
	);

	/**
	 * Statuses a checkout sets that tell the shopper nothing "order received" does not
	 * (coordinator ruling 2026-11-10): silenced in the same checkout request. Anything else,
	 * a failed payment above all, still sends its text.
	 */
	private const CHECKOUT_SILENT = array( 'pending', 'on-hold', 'processing' );

	/**
	 * Hooks, only when WooCommerce runs and the store is connected.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'deliver' ), 10, 3 );
		if ( ! class_exists( 'WooCommerce' ) || '' === Zinn_Order_Updates_Api::token() ) {
			return;
		}
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'classic_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'on_classic_checkout' ), 25, 1 );
		// We register on init (priority 5); WooCommerce fires woocommerce_init on init priority 0.
		if ( did_action( 'woocommerce_init' ) ) {
			self::block_field();
		} else {
			add_action( 'woocommerce_init', array( self::class, 'block_field' ) );
		}
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_order_created' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_order_created' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status' ), 20, 3 );
	}

	/**
	 * The opt-in label, translated (§8.1).
	 */
	public static function label(): string {
		return __( 'Send me order updates by SMS to my phone number. Message and data rates may apply. Reply STOP to stop.', 'zinn-order-updates' );
	}

	/**
	 * Classic checkout: the unticked box above Place order.
	 */
	public static function classic_field(): void {
		if ( ! function_exists( 'woocommerce_form_field' ) ) {
			return;
		}
		woocommerce_form_field(
			self::FIELD,
			array(
				'type'     => 'checkbox',
				'class'    => array( 'form-row-wide' ),
				'label'    => esc_html( self::label() ),
				'required' => false,
				'default'  => 0,
			),
			0
		);
	}

	/**
	 * Classic checkout: keep the tick on the order (saved by WooCommerce right after).
	 *
	 * @param object $order The WC_Order.
	 */
	public static function on_classic_checkout( $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		$ticked = ! empty( $_POST[ self::FIELD ] );
		if ( $ticked && is_object( $order ) && method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( self::OPTIN_META, 'yes' );
		}
	}

	/**
	 * Block checkout: an additional checkout field (WooCommerce 8.9+).
	 */
	public static function block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::BLOCK_ID,
				'label'    => self::label(),
				'location' => 'order',
				'type'     => 'checkbox',
			)
		);
	}

	/**
	 * Did the shopper tick the box on this order (classic meta or block field)?
	 *
	 * @param object $order The WC_Order.
	 */
	public static function opted_in( $order ): bool {
		if ( 'yes' === (string) $order->get_meta( self::OPTIN_META ) ) {
			return true;
		}
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) && class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
			try {
				$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
				return (bool) $fields->get_field_from_object( self::BLOCK_ID, $order, 'other' );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * The billing phone in E.164, or '' when it cannot be made one (§8.1).
	 *
	 * @param string $raw     The phone as typed.
	 * @param string $country The billing country (ISO 3166-1 alpha-2).
	 */
	public static function e164( string $raw, string $country ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( str_starts_with( $raw, '+' ) ) {
			$number = '+' . $digits;
		} elseif ( str_starts_with( $digits, '00' ) ) {
			$number = '+' . substr( $digits, 2 );
		} else {
			$code = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_country_calling_code( $country ) : '';
			$code = is_array( $code ) ? (string) reset( $code ) : (string) $code;
			if ( '' === $code ) {
				return '';
			}
			$number = '+' . preg_replace( '/\D+/', '', $code ) . ltrim( $digits, '0' );
		}
		return preg_match( '/^\+[1-9]\d{6,14}$/', $number ) ? $number : '';
	}

	/**
	 * A new order: record the tick (when given) and queue "order received".
	 *
	 * @param int|object $order_or_id The order or its id.
	 */
	public static function on_order_created( $order_or_id ): void {
		$order = is_object( $order_or_id ) ? $order_or_id : wc_get_order( (int) $order_or_id );
		if ( ! $order || $order->get_meta( '_zinn_ou_received' ) ) {
			return;
		}
		$order->update_meta_data( '_zinn_ou_received', '1' );
		$order->save_meta_data();
		self::queue( (int) $order->get_id(), 'order_received', 0 );
		self::$in_checkout[ (int) $order->get_id() ] = true;
		if ( self::opted_in( $order ) ) {
			Zinn_Order_Updates_Whatsapp::remember_shopper( $order, 'sms' );
		}
	}

	/**
	 * A status change: queue the matching update (the engine skips a shopper with no consent).
	 *
	 * @param int    $order_id The order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function on_status( $order_id, $from, $to ): void {
		$kind = self::KINDS[ (string) $to ] ?? '';
		if ( '' === $kind ) {
			return;
		}
		// One checkout, one text: a non-actionable status the checkout itself set is already in
		// "order received". A failed payment is actionable for the shopper, so it always sends.
		if ( isset( self::$in_checkout[ (int) $order_id ] ) && in_array( (string) $to, self::CHECKOUT_SILENT, true ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		// Only a shopper who ticked the box: nothing about any other order leaves the store (§19.1).
		if ( ! $order || ! self::opted_in( $order ) ) {
			return;
		}
		// The sequence keeps a status bounced back and forth a NEW event, and a retried hook the SAME one.
		$seq = (int) $order->get_meta( '_zinn_ou_seq' ) + 1;
		$order->update_meta_data( '_zinn_ou_seq', (string) $seq );
		$order->save_meta_data();
		self::queue( (int) $order_id, $kind, $seq );
	}

	/**
	 * Queue one delivery off the request (Action Scheduler, else WP-Cron).
	 *
	 * @param int    $order_id The order id.
	 * @param string $kind     Event kind.
	 * @param int    $seq      The order's event sequence.
	 * @param int    $delay    Seconds from now.
	 */
	private static function queue( int $order_id, string $kind, int $seq, int $delay = 0 ): void {
		$args = array( $order_id, $kind, $seq );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK, $args, 'zinn-order-updates' );
			return;
		}
		wp_schedule_single_event( time() + $delay, self::HOOK, $args );
	}

	/**
	 * The queued job: post the shopper's tick (once) and the event; retry a failed post with a back-off.
	 *
	 * @param int    $order_id The order id.
	 * @param string $kind     Event kind.
	 * @param int    $seq      The order's event sequence.
	 */
	public static function deliver( $order_id, $kind, $seq ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order || '' === Zinn_Order_Updates_Api::token() ) {
			return;
		}
		$code = 200;
		// The tick goes first, in the same job, so the engine has it before the first event.
		if ( ! $order->get_meta( '_zinn_ou_consent_sent' ) && self::opted_in( $order ) && '' !== self::consent( $order )['phone'] ) {
			list( $code ) = Zinn_Order_Updates_Api::call( 'POST', '/v1/store-connect/consents', array( 'consents' => array( self::consent( $order ) ) ) );
			if ( $code >= 200 && $code < 300 ) {
				$order->update_meta_data( '_zinn_ou_consent_sent', '1' );
				$order->save_meta_data();
			}
		}
		if ( $code >= 200 && $code < 300 ) {
			list( $code ) = Zinn_Order_Updates_Api::call( 'POST', '/v1/store-connect/events', array( 'events' => array( self::event( $order, (string) $kind, (int) $seq ) ) ) );
		}
		if ( $code >= 200 && $code < 300 ) {
			return;
		}
		$tries = (int) $order->get_meta( '_zinn_ou_tries_' . $kind . '_' . $seq );
		// 4xx other than 429 will not succeed on a retry; everything else (0 = network) does.
		if ( $tries >= self::RETRIES || ( $code >= 400 && $code < 500 && 429 !== $code ) ) {
			return;
		}
		$order->update_meta_data( '_zinn_ou_tries_' . $kind . '_' . $seq, (string) ( $tries + 1 ) );
		$order->save_meta_data();
		self::queue( (int) $order_id, (string) $kind, (int) $seq, 60 * ( 2 ** $tries ) );
	}

	/**
	 * The consent record (§9.1: the wording, its version and language are the proof).
	 *
	 * @param object $order The WC_Order.
	 * @return array<string, mixed>
	 */
	public static function consent( $order ): array {
		$created = $order->get_date_created();
		return array(
			'phone'       => self::e164( (string) $order->get_billing_phone(), (string) $order->get_billing_country() ),
			'channel'     => 'sms',
			'purpose'     => 'updates',
			'ref'         => (string) $order->get_order_number(),
			'captured_at' => $created ? $created->format( DATE_ATOM ) : gmdate( DATE_ATOM ),
			'evidence'    => array(
				'wording'         => self::label(),
				'wording_version' => self::WORDING_V,
				'language'        => (string) $order->get_meta( Zinn_Order_Updates_Locale::META ),
				'integration'     => 'woocommerce',
			),
		);
	}

	/**
	 * The event body (docs/1297 §4.4; the id is the idempotency key).
	 *
	 * @param object $order The WC_Order.
	 * @param string $kind  Event kind.
	 * @param int    $seq   The order's event sequence.
	 * @return array<string, mixed>
	 */
	public static function event( $order, string $kind, int $seq ): array {
		$currency = strtoupper( (string) $order->get_currency() );
		// ISO 4217 minor units (the engine formats by the currency's own exponent), not the store's display decimals.
		$decimals = in_array( $currency, self::ZERO_DECIMAL, true ) ? 0 : ( in_array( $currency, self::THREE_DECIMAL, true ) ? 3 : 2 );
		$minor    = static function ( $amount ) use ( $decimals ): int {
			return (int) round( (float) $amount * ( 10 ** $decimals ) );
		};
		$locale   = (string) $order->get_meta( Zinn_Order_Updates_Locale::META );
		// A signed-in shopper's My Account order page; a guest's order-received page (its key opens it without a login).
		$view  = (int) $order->get_customer_id() > 0 ? (string) $order->get_view_order_url() : (string) $order->get_checkout_order_received_url();
		$event = array(
			'id'           => Zinn_Order_Updates_Api::site_uuid() . ':woocommerce:' . $order->get_id() . ':' . $kind . ':' . $seq,
			'kind'         => $kind,
			'integration'  => 'woocommerce',
			'ref'          => (string) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'occurred_at'  => gmdate( DATE_ATOM ),
			'locale'       => $locale,
			'store_name'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'customer'     => array(
				'first_name' => (string) $order->get_billing_first_name(),
				'phone'      => self::e164( (string) $order->get_billing_phone(), (string) $order->get_billing_country() ),
			),
			'totals'       => array(
				'minor'    => $minor( $order->get_total() ),
				'currency' => $currency,
			),
			'order_url'    => Zinn_Order_Updates_Locale::url( $view, $locale ),
			'pay_url'      => 'payment_failed' === $kind ? Zinn_Order_Updates_Locale::url( (string) $order->get_checkout_payment_url(), $locale ) : '',
		);
		if ( 'refunded' === $kind ) {
			$event['refund'] = array(
				'minor'    => $minor( $order->get_total_refunded() ),
				'currency' => $currency,
			);
		}
		if ( '' === $event['customer']['phone'] ) {
			unset( $event['customer']['phone'] );
		}
		return $event;
	}
}
