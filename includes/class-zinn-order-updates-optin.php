<?php
/**
 * The marketing sign-up on any page: the `zinn-ou/opt-in` block, the `[zinn_ou_opt_in]`
 * shortcode and PBS forms (docs/1297 §8.2 "Any page", §15.1; master row 1108#8 P1).
 *
 * Every path posts ONE ConsentEvent through the same `POST /v1/store-connect/consents` the
 * checkout uses, always with `purpose: marketing` (GDPR Art 7(2): an offers tick is never an
 * order-updates tick, and nothing here can write one). The evidence names its source
 * (`block` or `pbs_form`), the wording the visitor saw, its version and the page language.
 *
 * ⛔ No REST route: the form posts to `admin-post.php`, guarded by a signed, time-boxed token
 * (cached pages keep working, which a nonce would not), a honeypot and a per-visitor rate limit.
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The sign-up block, shortcode and PBS form hook.
 */
final class Zinn_Order_Updates_Optin {

	public const BLOCK     = 'zinn-ou/opt-in';
	public const SHORTCODE = 'zinn_ou_opt_in';
	public const ACTION    = 'zinn_ou_opt_in';
	public const CATEGORY  = 'zinn';
	public const HANDLE    = 'zinn-ou-opt-in-block';
	public const RETRY     = 'zinn_order_updates_consent_retry';
	public const RESULT    = 'zinn_ou_optin';
	public const NONCE     = '_zinn_ou_nonce';
	public const WORDING_V = '1';
	/** PBS form fields with these names (type consent or checkbox) are our consent element. */
	public const PBS_FIELDS = array(
		'zinn_ou_sms'      => 'sms',
		'zinn_ou_whatsapp' => 'whatsapp',
	);
	private const MIN_AGE   = 3;
	private const MAX_AGE   = 2 * DAY_IN_SECONDS;
	private const RATE      = 5;
	private const RETRIES   = 5;
	private const CHANNELS  = array( 'sms', 'whatsapp', 'both' );

	/**
	 * Hooks (any site: the sign-up needs no WooCommerce).
	 */
	public static function register(): void {
		add_filter( 'block_categories_all', array( self::class, 'categories' ), 10, 1 );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_signed_in' ) );
		add_action( self::RETRY, array( self::class, 'retry' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
		self::register_block();
		// PBS Pro only: the free build has no forms, so the hook is joined only when they exist.
		if ( self::pbs_forms() ) {
			add_action( 'pbsw_form_submitted', array( self::class, 'on_pbs_form' ), 10, 1 );
		}
	}

	/**
	 * Are PBS Pro forms present (the class that fires `pbsw_form_submitted`)?
	 */
	public static function pbs_forms(): bool {
		return defined( 'PBSW_VERSION' ) && class_exists( '\ZinnDigital\PBS\Pro\Marketing\Forms' );
	}

	/**
	 * The "Zinn Digital®" inserter category (slug `zinn`), shared with every Zinn® plugin (added once).
	 *
	 * @param mixed $categories The block categories.
	 * @return mixed
	 */
	public static function categories( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}
		if ( ! in_array( self::CATEGORY, array_column( $categories, 'slug' ), true ) ) {
			$categories[] = array(
				'slug'  => self::CATEGORY,
				'title' => __( 'Zinn Digital®', 'zinn-order-updates' ),
				'icon'  => null,
			);
		}
		return $categories;
	}

	/**
	 * The dynamic block (rendered on the server; the editor previews it the same way).
	 */
	private static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/opt-in-block.js', ZINN_ORDER_UPDATES_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-dom-ready' ),
			ZINN_ORDER_UPDATES_VERSION,
			true
		);
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => self::title(),
				'category'        => self::CATEGORY,
				'editor_script'   => self::HANDLE,
				'attributes'      => array(
					'channel' => array(
						'type'    => 'string',
						'enum'    => self::CHANNELS,
						'default' => 'sms',
					),
				),
				'supports'        => array( 'html' => false ),
				'render_callback' => array( self::class, 'render_block' ),
			)
		);
	}

	/**
	 * The block title, translated.
	 */
	public static function title(): string {
		return __( 'Zinn® SMS and WhatsApp sign-up', 'zinn-order-updates' );
	}

	/**
	 * The editor's strings, translated in PHP (so the plugin's catalogues carry them all).
	 */
	public static function editor_data(): void {
		$data = array(
			'title'       => self::title(),
			'description' => __( 'Lets visitors sign up for offers and news by SMS or WhatsApp. Unticked by default; marketing only.', 'zinn-order-updates' ),
			'settings'    => __( 'Sign-up settings', 'zinn-order-updates' ),
			'channel'     => __( 'Channel', 'zinn-order-updates' ),
			'channels'    => array(
				array(
					'value' => 'sms',
					'label' => __( 'SMS', 'zinn-order-updates' ),
				),
				array(
					'value' => 'whatsapp',
					'label' => __( 'WhatsApp', 'zinn-order-updates' ),
				),
				array(
					'value' => 'both',
					'label' => __( 'Visitor chooses SMS or WhatsApp', 'zinn-order-updates' ),
				),
			),
			'pbs'         => array(),
		);
		if ( self::pbs_forms() ) {
			foreach ( self::PBS_FIELDS as $name => $channel ) {
				$data['pbs'][] = array(
					'name'        => 'zinn-ou-' . $channel,
					'title'       => 'sms' === $channel ? __( 'Zinn® SMS offers consent', 'zinn-order-updates' ) : __( 'Zinn® WhatsApp offers consent', 'zinn-order-updates' ),
					'description' => __( 'Records a marketing opt-in in Zinn® Campaigns for the phone number in this form.', 'zinn-order-updates' ),
					'scope'       => array( 'inserter' ),
					'attributes'  => array(
						'type'     => 'consent',
						'name'     => $name,
						'label'    => self::wording( $channel ),
						'required' => false,
					),
				);
			}
		}
		wp_add_inline_script( self::HANDLE, 'window.zinnOuOptIn = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * The consent wording, per channel (docs/1297 §8.1, second box; versioned by WORDING_V).
	 *
	 * @param string $channel `sms` or `whatsapp`.
	 */
	public static function wording( string $channel ): string {
		return 'whatsapp' === $channel
			? __( 'Send me offers and news on WhatsApp to this number. Optional; unsubscribe any time.', 'zinn-order-updates' )
			: __( 'Send me offers and news by SMS to this number. Optional; unsubscribe any time by replying STOP. Message and data rates may apply.', 'zinn-order-updates' );
	}

	/**
	 * `[zinn_ou_opt_in channel="sms|whatsapp|both"]`.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'channel' => 'sms' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		return self::form( (string) $atts['channel'] );
	}

	/**
	 * The block's render callback.
	 *
	 * @param mixed $attributes Block attributes.
	 */
	public static function render_block( $attributes ): string {
		return self::form( (string) ( is_array( $attributes ) ? ( $attributes['channel'] ?? 'sms' ) : 'sms' ) );
	}

	/**
	 * The signed form token: the page and the moment it was drawn.
	 *
	 * @param int $post_id The page.
	 * @param int $ts      Unix time the form was drawn.
	 */
	public static function token( int $post_id, int $ts ): string {
		return wp_hash( self::ACTION . '|' . $post_id . '|' . $ts, 'nonce' );
	}

	/**
	 * `ok`, `forged`, `early` (faster than a person) or `expired`.
	 *
	 * @param int    $post_id The page.
	 * @param int    $ts      The drawn time posted back.
	 * @param string $token   The token posted back.
	 * @param int    $now     Now.
	 */
	public static function token_state( int $post_id, int $ts, string $token, int $now ): string {
		if ( ! hash_equals( self::token( $post_id, $ts ), $token ) ) {
			return 'forged';
		}
		if ( $now - $ts < self::MIN_AGE ) {
			return 'early';
		}
		return $now - $ts > self::MAX_AGE ? 'expired' : 'ok';
	}

	/**
	 * The form. Nothing at all until the store is connected (a manager sees why).
	 *
	 * @param string $channel `sms`, `whatsapp` or `both`.
	 */
	public static function form( string $channel ): string {
		if ( '' === Zinn_Order_Updates_Api::token() ) {
			return current_user_can( 'manage_options' )
				? '<p class="zinn-ou-opt-in-notice">' . esc_html__( 'Connect this store to Zinn® Campaigns to show this sign-up form.', 'zinn-order-updates' ) . '</p>'
				: '';
		}
		$channel = in_array( $channel, self::CHANNELS, true ) ? $channel : 'sms';
		$post_id = (int) get_the_ID();
		$ts      = time();
		$id      = 'zinn-ou-opt-in-' . $post_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only result code for display, set by our own redirect.
		$result = isset( $_GET[ self::RESULT ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::RESULT ] ) ) : '';
		$out    = '<form id="' . esc_attr( $id ) . '" class="zinn-ou-opt-in" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$msg    = self::message( $result );
		$out   .= '<p class="zinn-ou-opt-in-message" role="status" aria-live="polite">' . esc_html( $msg ) . '</p>';
		$out   .= '<p><label for="' . esc_attr( $id ) . '-phone">' . esc_html__( 'Mobile number', 'zinn-order-updates' ) . '</label> ';
		$out   .= '<input id="' . esc_attr( $id ) . '-phone" type="tel" name="phone" autocomplete="tel" inputmode="tel" required placeholder="+44 7700 900123" /></p>';
		if ( 'both' === $channel ) {
			$out    .= '<fieldset><legend>' . esc_html__( 'How should we send them?', 'zinn-order-updates' ) . '</legend>';
			$out    .= '<label><input type="radio" name="channel" value="sms" checked /> ' . esc_html__( 'SMS', 'zinn-order-updates' ) . '</label> ';
			$out    .= '<label><input type="radio" name="channel" value="whatsapp" /> ' . esc_html__( 'WhatsApp', 'zinn-order-updates' ) . '</label></fieldset>';
			$wording = __( 'Send me offers and news by the channel I chose above. Optional; unsubscribe any time.', 'zinn-order-updates' );
		} else {
			$out    .= '<input type="hidden" name="channel" value="' . esc_attr( $channel ) . '" />';
			$wording = self::wording( $channel );
		}
		$out .= '<p><label><input type="checkbox" name="agree" value="1" required /> ' . esc_html( $wording ) . '</label></p>';
		$out .= '<p style="position:absolute;inset-inline-start:-9999px" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'zinn-order-updates' ) . ' <input type="text" name="website" tabindex="-1" autocomplete="off" /></label></p>';
		$out .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		$out .= '<input type="hidden" name="mode" value="' . esc_attr( $channel ) . '" />';
		$out .= '<input type="hidden" name="post" value="' . esc_attr( (string) $post_id ) . '" />';
		$out .= '<input type="hidden" name="ts" value="' . esc_attr( (string) $ts ) . '" />';
		$out .= '<input type="hidden" name="token" value="' . esc_attr( self::token( $post_id, $ts ) ) . '" />';
		$out .= is_user_logged_in() ? wp_nonce_field( self::ACTION, self::NONCE, true, false ) : '';
		$out .= '<input type="hidden" name="lang" value="' . esc_attr( Zinn_Order_Updates_Locale::resolve() ) . '" />';
		$out .= '<p><button type="submit">' . esc_html__( 'Sign up', 'zinn-order-updates' ) . '</button></p>';
		return $out . '</form>';
	}

	/**
	 * The visitor-facing result line.
	 *
	 * @param string $result The result code.
	 */
	public static function message( string $result ): string {
		switch ( $result ) {
			case 'ok':
				return __( 'Thank you, you are signed up. You can unsubscribe any time.', 'zinn-order-updates' );
			case 'phone':
				return __( 'Please enter your mobile number with its country code, for example +44 7700 900123.', 'zinn-order-updates' );
			case 'agree':
				return __( 'Please tick the box to agree.', 'zinn-order-updates' );
			case 'expired':
				return __( 'This form was open for a long time. Please reload the page and send it again.', 'zinn-order-updates' );
			case 'rate':
				return __( 'Too many sign-ups from here. Please wait a few minutes and try again.', 'zinn-order-updates' );
			case 'refused':
				return __( 'We could not save your sign-up. Please check your number and try again.', 'zinn-order-updates' );
			default:
				return '';
		}
	}

	/**
	 * The consent record for a sign-up (§9.1), always MARKETING.
	 *
	 * @param string $phone   E.164.
	 * @param string $channel `sms` or `whatsapp`.
	 * @param string $source  `block` or `pbs_form`.
	 * @param string $ref     The page or form it came from.
	 * @param string $wording The text the visitor agreed to.
	 * @param string $version The wording version.
	 * @param string $lang    The page language.
	 * @return array<string, mixed>
	 */
	public static function consent( string $phone, string $channel, string $source, string $ref, string $wording, string $version, string $lang ): array {
		return array(
			'phone'       => $phone,
			'channel'     => 'whatsapp' === $channel ? 'whatsapp' : 'sms',
			'purpose'     => 'marketing',
			'ref'         => substr( $ref, 0, 64 ),
			'captured_at' => gmdate( DATE_ATOM ),
			'evidence'    => array(
				'wording'         => $wording,
				'wording_version' => $version,
				'language'        => Zinn_Order_Updates_Locale::clean( $lang ),
				'integration'     => $source,
				'source'          => $source,
				'page_url'        => substr( (string) home_url( '/' ), 0, 200 ),
			),
		);
	}

	/**
	 * Post one consent; queue a retry when the API could not take it now. Returns the HTTP code.
	 *
	 * @param array<string, mixed> $consent The consent record.
	 * @param int                  $tries   Attempts so far.
	 */
	public static function send( array $consent, int $tries = 0 ): int {
		list( $code ) = Zinn_Order_Updates_Api::call( 'POST', '/v1/store-connect/consents', array( 'consents' => array( $consent ) ) );
		$retryable    = 0 === $code || 429 === $code || $code >= 500;
		if ( $retryable && $tries < self::RETRIES ) {
			$args = array( $consent, $tries + 1 );
			$when = time() + 60 * ( 2 ** $tries );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( $when, self::RETRY, $args, 'zinn-order-updates' );
			} else {
				wp_schedule_single_event( $when, self::RETRY, $args );
			}
		}
		return $code;
	}

	/**
	 * The queued retry.
	 *
	 * @param mixed $consent The consent record.
	 * @param mixed $tries   Attempts so far.
	 */
	public static function retry( $consent, $tries ): void {
		if ( is_array( $consent ) && 'marketing' === ( $consent['purpose'] ?? '' ) && '' !== Zinn_Order_Updates_Api::token() ) {
			self::send( $consent, (int) $tries );
		}
	}

	/**
	 * Check a posted sign-up and post its consent. Returns the result code (pure: no redirect).
	 *
	 * @param array<string, mixed> $in  The posted fields (unslashed).
	 * @param int                  $now Now.
	 */
	public static function process( array $in, int $now ): string {
		$post_id = (int) ( $in['post'] ?? 0 );
		$state   = self::token_state( $post_id, (int) ( $in['ts'] ?? 0 ), (string) ( $in['token'] ?? '' ), $now );
		// A bot (filled honeypot, forged token, faster than a person) is answered like a success.
		if ( '' !== trim( (string) ( $in['website'] ?? '' ) ) || 'forged' === $state || 'early' === $state ) {
			return 'ok';
		}
		if ( 'expired' === $state ) {
			return 'expired';
		}
		if ( '' === Zinn_Order_Updates_Api::token() ) {
			return 'refused';
		}
		if ( empty( $in['agree'] ) ) {
			return 'agree';
		}
		$country = function_exists( 'WC' ) && WC()->countries ? (string) WC()->countries->get_base_country() : '';
		$phone   = Zinn_Order_Updates_Events::e164( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ), $country );
		if ( '' === $phone ) {
			return 'phone';
		}
		if ( ! self::within_rate() ) {
			return 'rate';
		}
		$mode    = in_array( (string) ( $in['mode'] ?? '' ), self::CHANNELS, true ) ? (string) $in['mode'] : 'sms';
		$channel = 'whatsapp' === (string) ( $in['channel'] ?? '' ) ? 'whatsapp' : 'sms';
		if ( 'both' !== $mode ) {
			$channel = $mode;
		}
		$wording = 'both' === $mode
			? __( 'Send me offers and news by the channel I chose above. Optional; unsubscribe any time.', 'zinn-order-updates' )
			: self::wording( $channel );
		$consent = self::consent( $phone, $channel, 'block', 'block:' . $post_id, $wording, self::WORDING_V, (string) ( $in['lang'] ?? '' ) );
		if ( $post_id > 0 ) {
			$consent['evidence']['page_url'] = substr( (string) get_permalink( $post_id ), 0, 200 );
		}
		$code = self::send( $consent );
		return $code >= 400 && $code < 500 && 429 !== $code ? 'refused' : 'ok';
	}

	/**
	 * At most RATE sign-ups per visitor (address hashed, never stored) per 10 minutes.
	 */
	private static function within_rate(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'zinn_ou_optin_' . substr( wp_hash( $ip, 'nonce' ), 0, 20 );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE ) {
			return false;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * `admin-post.php` for a SIGNED-IN visitor: their session could be ridden by another site, so
	 * a nonce and the plain `read` capability (any account on this site) come first.
	 */
	public static function handle_signed_in(): void {
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the nonce, verified next in signed_in_ok().
		if ( ! self::signed_in_ok( $nonce ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce just failed; the page id only picks where to send them back.
			$post = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
			wp_safe_redirect( add_query_arg( self::RESULT, 'expired', $post > 0 ? (string) get_permalink( $post ) : home_url( '/' ) ) . '#zinn-ou-opt-in-' . $post );
			exit;
		}
		self::handle();
	}

	/**
	 * A signed-in post is accepted only with this site's `read` capability and a valid nonce.
	 *
	 * @param string $nonce The posted nonce.
	 */
	public static function signed_in_ok( string $nonce ): bool {
		return current_user_can( 'read' ) && false !== wp_verify_nonce( $nonce, self::ACTION );
	}

	/**
	 * `admin-post.php` for a visitor with no session (nothing to forge): the signed, time-boxed
	 * token in process() is the check, so cached pages keep working. Back to the page with the result.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- a public sign-up form on cacheable pages: the signed, time-boxed token checked in process() replaces the nonce.
		$in = array();
		foreach ( array( 'post', 'ts', 'token', 'website', 'agree', 'phone', 'mode', 'channel', 'lang' ) as $key ) {
			$in[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = self::process( $in, time() );
		$post   = (int) $in['post'];
		$back   = $post > 0 ? (string) get_permalink( $post ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( self::RESULT, $result, $back ) . '#zinn-ou-opt-in-' . $post );
		exit;
	}

	/**
	 * PBS Pro form: a phone field and one of our consent fields, ticked, send a MARKETING consent
	 * (source `pbs_form`, the form id, the field's own wording). Anything else is left alone.
	 *
	 * @param mixed $submission The PBS submission (fields, values, form, post).
	 * @return int Consents posted (0 or 1 per ticked channel).
	 */
	public static function on_pbs_form( $submission ): int {
		if ( ! is_array( $submission ) || '' === Zinn_Order_Updates_Api::token() ) {
			return 0;
		}
		$fields = is_array( $submission['fields'] ?? null ) ? $submission['fields'] : array();
		$values = is_array( $submission['values'] ?? null ) ? $submission['values'] : array();
		$phone  = '';
		foreach ( $fields as $f ) {
			if ( is_array( $f ) && 'tel' === ( $f['type'] ?? '' ) && '' !== (string) ( $values[ $f['name'] ?? '' ] ?? '' ) ) {
				$phone = (string) $values[ $f['name'] ];
				break;
			}
		}
		$country = function_exists( 'WC' ) && WC()->countries ? (string) WC()->countries->get_base_country() : '';
		$phone   = Zinn_Order_Updates_Events::e164( $phone, $country );
		if ( '' === $phone ) {
			return 0;
		}
		$post = $submission['post'] ?? null;
		$ref  = 'pbs_form:' . (string) ( $submission['form'] ?? '' );
		$sent = 0;
		foreach ( $fields as $f ) {
			$name = is_array( $f ) ? (string) ( $f['name'] ?? '' ) : '';
			if ( ! isset( self::PBS_FIELDS[ $name ] ) || ! in_array( $f['type'] ?? '', array( 'consent', 'checkbox' ), true ) ) {
				continue;
			}
			$value = $values[ $name ] ?? '';
			if ( is_array( $value ) ? array() === $value : '' === trim( (string) $value ) ) {
				continue;
			}
			$label   = (string) ( $f['label'] ?? '' );
			$version = 'pbs:' . substr( md5( $label ), 0, 12 );
			$consent = self::consent( $phone, self::PBS_FIELDS[ $name ], 'pbs_form', $ref, $label, $version, Zinn_Order_Updates_Locale::resolve() );
			if ( is_object( $post ) && isset( $post->ID ) ) {
				$consent['evidence']['page_url'] = substr( (string) get_permalink( (int) $post->ID ), 0, 200 );
			}
			self::send( $consent );
			++$sent;
		}
		return $sent;
	}
}
