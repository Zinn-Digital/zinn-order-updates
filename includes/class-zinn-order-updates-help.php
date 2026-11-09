<?php
/**
 * Zinn® Order Updates → Help: ask the Zinn® AI, report a problem, follow your tickets
 * (docs/1297 §16, master row 1108#9).
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Help screen and its form handlers.
 */
final class Zinn_Order_Updates_Help {

	/** The Help page slug. */
	public const PAGE = 'zinn-order-updates-help';

	/** Per-user transient prefix holding the last AI answer (30 minutes). */
	private const ANSWER = 'zinn_order_updates_answer_';

	/** The form actions. */
	private const ACTIONS = array( 'consent', 'withdraw', 'ask', 'solved', 'ticket', 'reply' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_zinn_order_updates_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	/**
	 * The Help submenu, with the answered-tickets badge on the menu (the count, never a nag).
	 */
	public static function menu(): void {
		add_submenu_page(
			Zinn_Order_Updates_Connect::PAGE,
			__( 'Zinn® Order Updates', 'zinn-order-updates' ),
			__( 'Connection', 'zinn-order-updates' ),
			Zinn_Order_Updates_Connect::CAPABILITY,
			Zinn_Order_Updates_Connect::PAGE
		);
		$answered = current_user_can( Zinn_Order_Updates_Connect::CAPABILITY ) ? Zinn_Order_Updates_Support::answered() : 0;
		$badge    = $answered > 0
			? ' <span class="awaiting-mod count-' . (int) $answered . '"><span class="pending-count">' . (int) $answered . '</span></span>'
			: '';
		add_submenu_page(
			Zinn_Order_Updates_Connect::PAGE,
			__( 'Help', 'zinn-order-updates' ),
			esc_html__( 'Help', 'zinn-order-updates' ) . $badge,
			Zinn_Order_Updates_Connect::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
		if ( $answered > 0 ) {
			global $menu;
			foreach ( (array) $menu as $i => $item ) {
				if ( isset( $item[2] ) && Zinn_Order_Updates_Connect::PAGE === $item[2] ) {
					$menu[ $i ][0] .= $badge; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the documented way to badge a menu entry.
				}
			}
		}
	}

	/**
	 * The Help page URL.
	 *
	 * @param array<string, string> $args Query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Checks the capability and nonce of a form post.
	 *
	 * @param string $action The action name, without the prefix.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( Zinn_Order_Updates_Connect::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'zinn-order-updates' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'zinn_order_updates_' . $action );
	}

	/**
	 * A posted text field.
	 *
	 * @param string $key       Field name.
	 * @param bool   $multiline Keep line breaks.
	 */
	private static function field( string $key, bool $multiline = false ): string {
		$raw = isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every caller ran guard() (check_admin_referer) first; sanitised on the next line.
		return $multiline ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
	}

	/**
	 * Back to the Help page with a notice.
	 *
	 * @param string                $notice The notice key.
	 * @param array<string, string> $args   More query args.
	 */
	private static function back( string $notice, array $args = array() ): void {
		wp_safe_redirect( self::url( array_merge( array( 'zou_notice' => $notice ), $args ) ) );
		exit;
	}

	/**
	 * "Agree and continue".
	 */
	public static function handle_consent(): void {
		self::guard( 'consent' );
		Zinn_Order_Updates_Support::give_consent();
		self::back( 'consented' );
	}

	/**
	 * "Withdraw consent".
	 */
	public static function handle_withdraw(): void {
		self::guard( 'withdraw' );
		Zinn_Order_Updates_Support::withdraw_consent();
		delete_transient( self::ANSWER . get_current_user_id() );
		self::back( 'withdrawn' );
	}

	/**
	 * Ask the Zinn® AI first.
	 */
	public static function handle_ask(): void {
		self::guard( 'ask' );
		$question = self::field( 'question', true );
		$answer   = Zinn_Order_Updates_Support::ask( $question );
		set_transient(
			self::ANSWER . get_current_user_id(),
			array(
				'question' => $question,
				'answer'   => $answer,
			),
			30 * MINUTE_IN_SECONDS
		);
		self::back( null === $answer ? 'ask_failed' : 'answered' );
	}

	/**
	 * "That solved it".
	 */
	public static function handle_solved(): void {
		self::guard( 'solved' );
		Zinn_Order_Updates_Support::solved( self::field( 'deflection_id' ) );
		delete_transient( self::ANSWER . get_current_user_id() );
		self::back( 'solved' );
	}

	/**
	 * Send a ticket to the Zinn® team.
	 */
	public static function handle_ticket(): void {
		self::guard( 'ticket' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer.
		$ticked              = isset( $_POST['zou_blocks'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['zou_blocks'] ) ) : array();
		list( $code, $body ) = Zinn_Order_Updates_Support::file(
			array(
				'kind'          => sanitize_key( self::field( 'kind' ) ),
				'subject'       => self::field( 'subject' ),
				'message'       => self::field( 'message', true ),
				'email'         => self::field( 'email' ),
				'deflection_id' => self::field( 'deflection_id' ),
			),
			$ticked
		);
		if ( 201 === $code ) {
			delete_transient( self::ANSWER . get_current_user_id() );
			self::back( 'ticket_sent', array( 'zou_ref' => (string) ( $body['reference'] ?? '' ) ) );
		}
		self::back( 0 === $code ? 'ticket_incomplete' : ( 429 === $code ? 'too_many' : 'ticket_failed' ) );
	}

	/**
	 * Reply to one of this store's tickets.
	 */
	public static function handle_reply(): void {
		self::guard( 'reply' );
		$code = Zinn_Order_Updates_Support::reply( strtoupper( self::field( 'reference' ) ), self::field( 'message', true ) );
		self::back( 201 === $code ? 'reply_sent' : ( 422 === $code ? 'reply_closed' : 'reply_failed' ) );
	}

	/**
	 * A form posting to one of the handlers.
	 *
	 * @param string $action The action, without the prefix.
	 */
	private static function open_form( string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'zinn_order_updates_' . $action ) . '" />';
		wp_nonce_field( 'zinn_order_updates_' . $action );
	}

	/**
	 * The notice after a redirect.
	 */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks which fixed sentence to show.
		$key = isset( $_GET['zou_notice'] ) ? sanitize_key( wp_unslash( $_GET['zou_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$ref      = isset( $_GET['zou_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['zou_ref'] ) ) : '';
		$messages = array(
			'consented'         => array( 'success', __( 'Thank you. You can now ask for help and send reports to Zinn® support.', 'zinn-order-updates' ) ),
			'withdrawn'         => array( 'info', __( 'Consent withdrawn. Nothing is sent to Zinn® support until you agree again.', 'zinn-order-updates' ) ),
			'ask_failed'        => array( 'warning', __( 'The Zinn® AI could not answer just now. Send your question to the team below; a person will reply.', 'zinn-order-updates' ) ),
			'solved'            => array( 'success', __( 'Glad that solved it.', 'zinn-order-updates' ) ),
			/* translators: %s: the ticket reference, such as ZT-7KQ3MX. */
			'ticket_sent'       => array( 'success', sprintf( __( 'Sent. Your ticket is %s; the team replies here and by e-mail.', 'zinn-order-updates' ), $ref ) ),
			'ticket_incomplete' => array( 'error', __( 'Fill in the subject, the message and, while your store is not connected, your e-mail address.', 'zinn-order-updates' ) ),
			'ticket_failed'     => array( 'error', __( 'Zinn® did not accept the ticket. Check the fields and try again; if it keeps failing, e-mail support@zinndigital.com.', 'zinn-order-updates' ) ),
			'too_many'          => array( 'error', __( 'Too many requests in a short time. Wait a few minutes and try again.', 'zinn-order-updates' ) ),
			'reply_sent'        => array( 'success', __( 'Reply sent.', 'zinn-order-updates' ) ),
			'reply_closed'      => array( 'error', __( 'That ticket is closed. Send a new ticket instead.', 'zinn-order-updates' ) ),
			'reply_failed'      => array( 'error', __( 'Your reply could not be sent. Try again in a moment.', 'zinn-order-updates' ) ),
		);
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	/**
	 * The label of a ticket state.
	 *
	 * @param string $state The engine's `state`.
	 */
	public static function state_label( string $state ): string {
		switch ( $state ) {
			case 'waiting_on_us':
				return __( 'Waiting on Zinn® support', 'zinn-order-updates' );
			case 'waiting_on_you':
				return __( 'Waiting on you', 'zinn-order-updates' );
			case 'answered':
				return __( 'Answered', 'zinn-order-updates' );
			case 'closed':
				return __( 'Closed', 'zinn-order-updates' );
		}
		return $state;
	}

	/**
	 * The label of a ticket kind.
	 *
	 * @param string $kind The kind.
	 */
	private static function kind_label( string $kind ): string {
		switch ( $kind ) {
			case 'bug':
				return __( 'Report a bug', 'zinn-order-updates' );
			case 'feedback':
				return __( 'Send feedback', 'zinn-order-updates' );
			case 'feature_request':
				return __( 'Request a feature', 'zinn-order-updates' );
		}
		return __( 'Ask for help', 'zinn-order-updates' );
	}

	/**
	 * The title of a diagnostics block.
	 *
	 * @param string $block The block.
	 */
	private static function block_label( string $block ): string {
		switch ( $block ) {
			case 'software':
				return __( 'Versions: WordPress, PHP, this plugin, WooCommerce (and whether it uses HPOS) and the supported plugins we detected', 'zinn-order-updates' );
			case 'theme':
				return __( 'Your active theme and its version', 'zinn-order-updates' );
			case 'plugins':
				return __( 'The list of installed plugins, their versions and whether each is active', 'zinn-order-updates' );
		}
		return __( 'Connection: your store address, whether it is connected, this install\'s id and whether Zinn® answered its last check (never the store\'s token)', 'zinn-order-updates' );
	}

	/**
	 * A date from the API in the site's format.
	 *
	 * @param string $iso An ISO 8601 date.
	 */
	private static function when( string $iso ): string {
		$time = strtotime( $iso );
		return $time ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
	}

	/**
	 * The screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Zinn_Order_Updates_Connect::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Zinn® Order Updates: Help', 'zinn-order-updates' ) . '</h1>';
		self::notice();
		if ( ! Zinn_Order_Updates_Support::has_consent() ) {
			self::render_consent();
			echo '</div>';
			return;
		}
		$connected = '' !== Zinn_Order_Updates_Api::token();
		$stored    = get_transient( self::ANSWER . get_current_user_id() );
		$stored    = is_array( $stored ) ? $stored : array();
		self::render_ask( $stored );
		self::render_ticket_form( $stored, $connected );
		self::render_tickets( $connected );
		self::open_form( 'withdraw' );
		echo '<p><button type="submit" class="button-link">' . esc_html__( 'Withdraw my consent to send help requests', 'zinn-order-updates' ) . '</button></p></form>';
		echo '</div>';
	}

	/**
	 * What goes where, before anything is sent.
	 */
	private static function render_consent(): void {
		echo '<p>' . esc_html__( 'Help here is answered by Zinn Digital® Ltd. Before anything leaves your site, this is exactly what is sent, and where:', 'zinn-order-updates' ) . '</p>';
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html__( 'A question you type: sent to the Zinn® AI at api.zinndigital.com, which answers from the Zinn® help articles.', 'zinn-order-updates' ) . '</li>';
		echo '<li>' . esc_html__( 'A ticket you send: its subject, your message, your admin language and a reply e-mail address, sent to Zinn® support.', 'zinn-order-updates' ) . '</li>';
		echo '<li>' . esc_html__( 'Diagnostics: only the blocks you tick, each shown in full before you send. Never the store\'s token, passwords or your customers\' details.', 'zinn-order-updates' ) . '</li>';
		echo '<li>' . esc_html__( 'Your tickets: while your store is connected, this screen reads your tickets and the team\'s replies from Zinn®.', 'zinn-order-updates' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__( 'We record who agreed and when. You can withdraw at any time on this screen.', 'zinn-order-updates' ) . '</p>';
		self::open_form( 'consent' );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Agree and continue', 'zinn-order-updates' ) . '</button></p></form>';
	}

	/**
	 * Ask first, and the answer.
	 *
	 * @param array<string, mixed> $stored The last question and answer.
	 */
	private static function render_ask( array $stored ): void {
		echo '<h2>' . esc_html__( 'Ask the Zinn® AI', 'zinn-order-updates' ) . '</h2>';
		self::open_form( 'ask' );
		echo '<p><label for="zou-question">' . esc_html__( 'What do you need help with?', 'zinn-order-updates' ) . '</label><br />';
		echo '<textarea id="zou-question" name="question" rows="3" class="large-text" maxlength="8000" required>' . esc_textarea( (string) ( $stored['question'] ?? '' ) ) . '</textarea></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Ask', 'zinn-order-updates' ) . '</button></p></form>';
		$answer = isset( $stored['answer'] ) && is_array( $stored['answer'] ) ? $stored['answer'] : null;
		if ( null === $answer ) {
			return;
		}
		$text    = (string) ( $answer['answer']['text'] ?? '' );
		$failure = (string) ( $answer['answer']['failure'] ?? '' );
		echo '<div class="card" style="max-inline-size:none">';
		if ( '' !== $failure || '' === $text ) {
			echo '<p>' . esc_html__( 'The Zinn® AI could not answer just now. Send your question to the team below; a person will reply.', 'zinn-order-updates' ) . '</p>';
		} else {
			echo '<p>' . nl2br( esc_html( $text ) ) . '</p>';
		}
		$articles = isset( $answer['articles'] ) && is_array( $answer['articles'] ) ? $answer['articles'] : array();
		if ( array() !== $articles ) {
			echo '<p><strong>' . esc_html__( 'Help articles', 'zinn-order-updates' ) . '</strong></p><ul class="ul-disc">';
			foreach ( $articles as $article ) {
				if ( is_array( $article ) && ! empty( $article['url'] ) ) {
					printf(
						'<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
						esc_url( (string) $article['url'] ),
						esc_html( (string) ( $article['title'] ?? '' ) )
					);
				}
			}
			echo '</ul>';
		}
		if ( '' === $failure && '' !== $text ) {
			self::open_form( 'solved' );
			echo '<input type="hidden" name="deflection_id" value="' . esc_attr( (string) ( $answer['deflection_id'] ?? '' ) ) . '" />';
			echo '<p><button type="submit" class="button">' . esc_html__( 'That solved it', 'zinn-order-updates' ) . '</button> ';
			echo esc_html__( 'Still need help? Send it to the team below.', 'zinn-order-updates' ) . '</p></form>';
		}
		echo '</div>';
	}

	/**
	 * The ticket form, with each diagnostics block shown in full beside its own box.
	 *
	 * @param array<string, mixed> $stored    The last question and answer.
	 * @param bool                 $connected Whether the store is connected.
	 */
	private static function render_ticket_form( array $stored, bool $connected ): void {
		echo '<h2>' . esc_html__( 'Send it to the Zinn® team', 'zinn-order-updates' ) . '</h2>';
		self::open_form( 'ticket' );
		$deflection = isset( $stored['answer']['deflection_id'] ) ? (string) $stored['answer']['deflection_id'] : '';
		echo '<input type="hidden" name="deflection_id" value="' . esc_attr( $deflection ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="zou-kind">' . esc_html__( 'What is it?', 'zinn-order-updates' ) . '</label></th><td><select id="zou-kind" name="kind">';
		foreach ( Zinn_Order_Updates_Support::KINDS as $kind ) {
			echo '<option value="' . esc_attr( $kind ) . '">' . esc_html( self::kind_label( $kind ) ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="zou-subject">' . esc_html__( 'Subject', 'zinn-order-updates' ) . '</label></th><td><input id="zou-subject" name="subject" type="text" class="regular-text" maxlength="200" required /></td></tr>';
		echo '<tr><th scope="row"><label for="zou-message">' . esc_html__( 'What happened? For a bug, the steps that show it.', 'zinn-order-updates' ) . '</label></th><td><textarea id="zou-message" name="message" rows="6" class="large-text" maxlength="20000" required>' . esc_textarea( (string) ( $stored['question'] ?? '' ) ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="zou-email">' . esc_html__( 'Reply e-mail', 'zinn-order-updates' ) . '</label></th><td>';
		if ( $connected ) {
			echo '<input id="zou-email" name="email" type="email" class="regular-text" maxlength="320" />';
			echo '<p class="description">' . esc_html__( 'Leave empty to use the e-mail of the Zinn® member who connected this store.', 'zinn-order-updates' ) . '</p>';
		} else {
			$user = wp_get_current_user();
			echo '<input id="zou-email" name="email" type="email" class="regular-text" maxlength="320" required value="' . esc_attr( (string) $user->user_email ) . '" />';
			echo '<p class="description">' . esc_html__( 'Your store is not connected, so the team replies by e-mail.', 'zinn-order-updates' ) . '</p>';
		}
		echo '</td></tr></tbody></table>';
		echo '<fieldset><legend><strong>' . esc_html__( 'Diagnostics (optional). Tick a block to attach it; this is exactly what is sent.', 'zinn-order-updates' ) . '</strong></legend>';
		foreach ( Zinn_Order_Updates_Support::blocks() as $block => $data ) {
			$id = 'zou-block-' . $block;
			echo '<p><label for="' . esc_attr( $id ) . '"><input id="' . esc_attr( $id ) . '" type="checkbox" name="zou_blocks[]" value="' . esc_attr( $block ) . '" /> ' . esc_html( self::block_label( $block ) ) . '</label></p>';
			echo '<pre style="max-block-size:16em;overflow:auto;background:#f6f7f7;padding:8px">' . esc_html( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		}
		echo '</fieldset>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Send to Zinn® support', 'zinn-order-updates' ) . '</button></p></form>';
	}

	/**
	 * My tickets, with support's latest reply and a reply box.
	 *
	 * @param bool $connected Whether the store is connected.
	 */
	private static function render_tickets( bool $connected ): void {
		echo '<h2>' . esc_html__( 'My tickets', 'zinn-order-updates' ) . '</h2>';
		if ( ! $connected ) {
			echo '<p>' . esc_html__( 'Connect your store to follow your tickets here. Until then the team replies by e-mail.', 'zinn-order-updates' ) . '</p>';
			return;
		}
		$list = Zinn_Order_Updates_Support::tickets();
		if ( null === $list ) {
			echo '<p>' . esc_html__( 'Your tickets could not be loaded just now. Try again in a moment.', 'zinn-order-updates' ) . '</p>';
			return;
		}
		$items = isset( $list['items'] ) && is_array( $list['items'] ) ? $list['items'] : array();
		if ( array() === $items ) {
			echo '<p>' . esc_html__( 'No tickets yet.', 'zinn-order-updates' ) . '</p>';
			return;
		}
		foreach ( $items as $ticket ) {
			if ( ! is_array( $ticket ) ) {
				continue;
			}
			$reference = (string) ( $ticket['reference'] ?? '' );
			$state     = (string) ( $ticket['state'] ?? '' );
			echo '<div class="card" style="max-inline-size:none">';
			printf(
				'<h3>%1$s <span class="description">%2$s</span></h3>',
				esc_html( (string) ( $ticket['subject'] ?? '' ) ),
				esc_html( $reference . ' · ' . self::state_label( $state ) . ' · ' . self::when( (string) ( $ticket['created_at'] ?? '' ) ) )
			);
			$reply = isset( $ticket['latest_reply'] ) && is_array( $ticket['latest_reply'] ) ? $ticket['latest_reply'] : null;
			if ( null !== $reply ) {
				/* translators: %s: the date and time of the reply. */
				echo '<p><strong>' . esc_html( sprintf( __( 'Zinn® support replied on %s:', 'zinn-order-updates' ), self::when( (string) ( $reply['at'] ?? '' ) ) ) ) . '</strong></p>';
				echo '<blockquote>' . nl2br( esc_html( (string) ( $reply['body'] ?? '' ) ) ) . '</blockquote>';
			}
			if ( 'closed' !== $state ) {
				self::open_form( 'reply' );
				echo '<input type="hidden" name="reference" value="' . esc_attr( $reference ) . '" />';
				$id = 'zou-reply-' . sanitize_key( $reference );
				echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Your reply', 'zinn-order-updates' ) . '</label><br />';
				echo '<textarea id="' . esc_attr( $id ) . '" name="message" rows="3" class="large-text" maxlength="20000" required></textarea></p>';
				echo '<p><button type="submit" class="button">' . esc_html__( 'Send reply', 'zinn-order-updates' ) . '</button></p></form>';
			}
			echo '</div>';
		}
	}
}
