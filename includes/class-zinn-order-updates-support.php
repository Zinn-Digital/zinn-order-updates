<?php
/**
 * Support inside the plugin: the AI answer first, then a ticket, then the store's tickets
 * (docs/1297 §16, master row 1108#9).
 *
 * ⛔ Nothing leaves the site before the site owner agrees on the Help screen, which says exactly
 * what goes where. The consent is recorded per site with who gave it and when (the admin kit's
 * `Support::CONSENT` idiom). Diagnostics are collected from an ALLOW-LIST, never a deny-list:
 * only the fields named in {@see self::ALLOW} can leave, the store token is never one of them,
 * and each block is shown in full and sent only when its own box is ticked.
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consent, diagnostics and the support calls.
 */
final class Zinn_Order_Updates_Support {

	/** The plugin-support product this plugin files under (engine `pluginsupport/products.py`). */
	public const PRODUCT = 'zinn-order-updates';

	/** Option recording the consent: `{user: int, at: int}`. Never autoloaded. */
	public const CONSENT = 'zinn_order_updates_support_consent';

	/** The menu badge: tickets support answered, cached ten minutes. */
	public const ANSWERED_CACHE = 'zinn_order_updates_support_answered';

	/** The ticket kinds the engine accepts. */
	public const KINDS = array( 'help', 'bug', 'feedback', 'feature_request' );

	/**
	 * The diagnostics blocks, each with its own toggle, and the ONLY keys each may carry.
	 * Top-level keys are the `PluginSupportDiagnostics` fields; `extra.*` keys sit under `extra`.
	 */
	public const ALLOW = array(
		'software'   => array( 'wp_version', 'php_version', 'plugin_version', 'multisite', 'extra.woocommerce', 'extra.hpos', 'extra.integrations' ),
		'theme'      => array( 'theme' ),
		'plugins'    => array( 'plugins' ),
		'connection' => array( 'site_url', 'extra.connected', 'extra.store_uuid', 'extra.health' ),
	);

	/** The keys one entry of `plugins[]` may carry. */
	private const PLUGIN_KEYS = array( 'slug', 'version', 'active' );

	/**
	 * Whether the site owner agreed to send support requests to Zinn®.
	 */
	public static function has_consent(): bool {
		$row = get_option( self::CONSENT, array() );
		return is_array( $row ) && ! empty( $row['at'] );
	}

	/**
	 * Records the consent: who gave it and when.
	 */
	public static function give_consent(): void {
		update_option(
			self::CONSENT,
			array(
				'user' => get_current_user_id(),
				'at'   => time(),
			),
			false
		);
	}

	/**
	 * Withdraws the consent. Nothing is sent again until it is given again.
	 */
	public static function withdraw_consent(): void {
		delete_option( self::CONSENT );
		delete_transient( self::ANSWERED_CACHE );
	}

	/**
	 * Every diagnostics block, already allow-listed and redacted, keyed by block.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function blocks(): array {
		$raw = self::collect();
		$out = array();
		foreach ( array_keys( self::ALLOW ) as $block ) {
			$out[ $block ] = self::only( $raw, array( $block ) );
		}
		return $out;
	}

	/**
	 * The diagnostics for the ticked blocks: exactly what the Help screen showed, nothing else.
	 *
	 * @param string[] $ticked Block names.
	 * @return array<string, mixed>
	 */
	public static function diagnostics( array $ticked ): array {
		return self::only( self::collect(), array_values( array_intersect( array_keys( self::ALLOW ), $ticked ) ) );
	}

	/**
	 * Keeps only the allow-listed keys of the given blocks, then redacts every string.
	 *
	 * @param array<string, mixed> $raw    A collected report.
	 * @param string[]             $blocks Block names.
	 * @return array<string, mixed>
	 */
	public static function only( array $raw, array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			foreach ( self::ALLOW[ $block ] ?? array() as $key ) {
				if ( 0 === strpos( $key, 'extra.' ) ) {
					$sub = substr( $key, 6 );
					if ( isset( $raw['extra'] ) && is_array( $raw['extra'] ) && array_key_exists( $sub, $raw['extra'] ) ) {
						$out['extra'][ $sub ] = $raw['extra'][ $sub ];
					}
					continue;
				}
				if ( array_key_exists( $key, $raw ) ) {
					$out[ $key ] = 'plugins' === $key ? self::plugin_rows( $raw[ $key ] ) : $raw[ $key ];
				}
			}
		}
		return self::redact( $out );
	}

	/**
	 * `plugins[]` rows with only slug, version and active.
	 *
	 * @param mixed $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function plugin_rows( $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array_intersect_key( $row, array_flip( self::PLUGIN_KEYS ) );
			}
		}
		return $out;
	}

	/**
	 * The raw facts, before the allow-list. Only named values are read; nothing is enumerated
	 * from options, the environment or the database.
	 *
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		global $wp_version;
		$theme  = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$status = get_transient( Zinn_Order_Updates_Admin::STATUS_CACHE ); // The cached read only: no call.
		return array(
			'site_url'       => home_url( '/' ),
			'wp_version'     => (string) $wp_version,
			'php_version'    => PHP_VERSION,
			'plugin_version' => ZINN_ORDER_UPDATES_VERSION,
			'multisite'      => is_multisite(),
			'theme'          => $theme ? trim( (string) $theme->get( 'Name' ) . ' ' . (string) $theme->get( 'Version' ) ) : '',
			'plugins'        => self::plugins(),
			'extra'          => array(
				'woocommerce'  => defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
				'hpos'         => self::hpos(),
				'integrations' => Zinn_Order_Updates_Api::integration_versions(),
				'connected'    => '' !== Zinn_Order_Updates_Api::token(),
				'store_uuid'   => Zinn_Order_Updates_Api::site_uuid(),
				'health'       => array(
					'api_reachable'  => is_array( $status ) ? empty( $status['unreachable'] ) : null,
					'campaigns_open' => is_array( $status ) && isset( $status['campaigns_open'] ) ? (bool) $status['campaigns_open'] : null,
				),
			),
		);
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS), or null without WooCommerce.
	 */
	private static function hpos(): ?bool {
		$utils = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( ! class_exists( $utils ) || ! method_exists( $utils, 'custom_orders_table_usage_is_enabled' ) ) {
			return null;
		}
		return (bool) call_user_func( array( $utils, 'custom_orders_table_usage_is_enabled' ) );
	}

	/**
	 * Installed plugins: slug, version, active. Active first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$file  = (string) $file;
			$out[] = array(
				'slug'    => false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => function_exists( 'is_plugin_active' ) && is_plugin_active( $file ),
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return array( ! $a['active'], $a['slug'] ) <=> array( ! $b['active'], $b['slug'] );
			}
		);
		return $out;
	}

	/**
	 * Redacts every phone number in every string of a report.
	 *
	 * @param mixed $value A report, or one value of it.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'redact' ), $value );
		}
		if ( ! is_string( $value ) || wp_is_uuid( $value ) ) {
			return $value; // An install id is not a phone number, even when it is mostly digits.
		}
		return (string) preg_replace_callback(
			'/\+?\d[\d \-().]{7,}\d/',
			static function ( array $m ): string {
				return self::redact_phone( $m[0] );
			},
			$value
		);
	}

	/**
	 * `+447700900916` → `+44 7••• •••916`: the country code, the first digit and the last three.
	 * A run with fewer than nine digits (a version, a date) is left as it was.
	 *
	 * @param string $raw A phone-like run.
	 */
	public static function redact_phone( string $raw ): string {
		$digits = (string) preg_replace( '/\D/', '', $raw );
		if ( strlen( $digits ) < 9 || strlen( $digits ) > 15 || preg_match( '/^\d{1,4}(\.\d+)+$|^\d{4}-\d{2}-\d{2}/', trim( $raw ) ) ) {
			return $raw;
		}
		$plus    = 0 === strpos( ltrim( $raw ), '+' );
		$country = $plus ? '+' . substr( $digits, 0, 2 ) . ' ' : '';
		$rest    = $plus ? substr( $digits, 2 ) : $digits;
		return $country . substr( $rest, 0, 1 ) . '••• •••' . substr( $rest, -3 );
	}

	/**
	 * Asks the Zinn® AI first (`deflectPublicSupportQuestion`). Null before consent.
	 *
	 * @param string $question The question.
	 * @return array<string, mixed>|null The answer, or null when nothing was sent or it failed.
	 */
	public static function ask( string $question ): ?array {
		if ( ! self::has_consent() || '' === trim( $question ) ) {
			return null;
		}
		list( $code, $body ) = Zinn_Order_Updates_Api::call(
			'POST',
			'/v1/public/support/deflect',
			array(
				'question' => mb_substr( $question, 0, 8000 ),
				'surface'  => 'plugin',
				'locale'   => self::locale(),
				'product'  => self::PRODUCT,
			),
			false
		);
		return 200 === $code ? $body : null;
	}

	/**
	 * "That solved it" (`markPublicSupportAnswerSolved`).
	 *
	 * @param string $deflection_id The answer's id.
	 */
	public static function solved( string $deflection_id ): void {
		if ( ! self::has_consent() || ! wp_is_uuid( $deflection_id ) ) {
			return;
		}
		Zinn_Order_Updates_Api::call( 'POST', '/v1/public/support/deflect/' . rawurlencode( $deflection_id ) . '/solved', null, false );
	}

	/**
	 * Files a ticket: with the store token when connected, else by e-mail.
	 *
	 * @param array<string, mixed> $input  kind, subject, message, email, deflection_id.
	 * @param string[]             $ticked The diagnostics blocks the site owner ticked.
	 * @return array{0: int, 1: array<string, mixed>} HTTP status (0 = not sent) and body.
	 */
	public static function file( array $input, array $ticked ): array {
		if ( ! self::has_consent() ) {
			return array( 0, array() );
		}
		$kind        = (string) ( $input['kind'] ?? 'help' );
		$body        = array(
			'kind'                => in_array( $kind, self::KINDS, true ) ? $kind : 'help',
			'subject'             => mb_substr( (string) ( $input['subject'] ?? '' ), 0, 200 ),
			'message'             => mb_substr( (string) ( $input['message'] ?? '' ), 0, 20000 ),
			'locale'              => self::locale(),
			'consent_diagnostics' => false,
		);
		$diagnostics = self::diagnostics( $ticked );
		if ( array() !== $diagnostics ) {
			$body['consent_diagnostics'] = true;
			$body['diagnostics']         = $diagnostics;
		}
		$deflection = (string) ( $input['deflection_id'] ?? '' );
		if ( wp_is_uuid( $deflection ) ) {
			$body['deflection_id'] = $deflection;
		}
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( '' !== $email ) {
			$body['email'] = $email;
		}
		if ( '' !== Zinn_Order_Updates_Api::token() ) {
			$result = Zinn_Order_Updates_Api::call( 'POST', '/v1/store-connect/support/tickets', $body );
		} else {
			if ( '' === $email ) {
				return array( 0, array() );
			}
			$body['product'] = self::PRODUCT;
			$body['website'] = '';
			$result          = Zinn_Order_Updates_Api::call( 'POST', '/v1/plugin-support/tickets', $body, false );
		}
		delete_transient( self::ANSWERED_CACHE );
		return $result;
	}

	/**
	 * This store's tickets (`listStoreSupportTickets`), or null when not connected or it failed.
	 * Refreshes the menu badge.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function tickets(): ?array {
		if ( ! self::has_consent() || '' === Zinn_Order_Updates_Api::token() ) {
			return null;
		}
		list( $code, $body ) = Zinn_Order_Updates_Api::call( 'GET', '/v1/store-connect/support/tickets' );
		if ( 200 !== $code ) {
			return null;
		}
		set_transient( self::ANSWERED_CACHE, (int) ( $body['answered'] ?? 0 ), 10 * MINUTE_IN_SECONDS );
		return $body;
	}

	/**
	 * Replies to one of this store's tickets (`replyStoreSupportTicket`).
	 *
	 * @param string $reference The ticket reference.
	 * @param string $message   The reply.
	 * @return int HTTP status, 0 when nothing was sent.
	 */
	public static function reply( string $reference, string $message ): int {
		if ( ! self::has_consent() || '' === Zinn_Order_Updates_Api::token() || '' === trim( $message ) || ! preg_match( '/^[A-Z0-9-]{1,16}$/', $reference ) ) {
			return 0;
		}
		list( $code ) = Zinn_Order_Updates_Api::call(
			'POST',
			'/v1/store-connect/support/tickets/' . rawurlencode( $reference ) . '/replies',
			array( 'message' => mb_substr( $message, 0, 20000 ) )
		);
		delete_transient( self::ANSWERED_CACHE );
		return $code;
	}

	/**
	 * How many tickets support answered and wait on the store: the menu badge. Cached ten
	 * minutes; a failed read caches 0 so a down API is not asked on every admin page.
	 */
	public static function answered(): int {
		if ( ! self::has_consent() || '' === Zinn_Order_Updates_Api::token() ) {
			return 0;
		}
		$cached = get_transient( self::ANSWERED_CACHE );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		if ( null === self::tickets() ) {
			set_transient( self::ANSWERED_CACHE, 0, 10 * MINUTE_IN_SECONDS );
			return 0;
		}
		return (int) get_transient( self::ANSWERED_CACHE );
	}

	/**
	 * The admin's language, for the answer and the ticket.
	 */
	public static function locale(): string {
		return function_exists( 'get_user_locale' ) ? (string) get_user_locale() : (string) get_locale();
	}
}
