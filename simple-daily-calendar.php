<?php
/**
 * Plugin Name: Simple Daily Calendar with Popups
 * Description: Second Brain Tracker: Books, Films & Series Tracking (Version 6.2 – PIN lock, writing mode, Sol, reading habit).
 * Version: 6.5
 * Author: AI Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class SimpleDailyCalendar {

	const VERSION = '6.5';
	// Bump when the database tables change; existing sites are upgraded automatically.
	const DB_VERSION = '6.1';
	const OPT_DB_VERSION = 'sdc_db_version';

	// --- PIN LOCK SETTINGS ---
	// Since 6.5 there is no automatic idle lock: Kalendarz locks when you press
	// the Lock button or close the tab. As a backstop, the server forgets an unlocked
	// session after this many seconds without any request. The open page sends a
	// heartbeat every minute, so this only matters if the computer was asleep.
	const SERVER_IDLE_SECONDS = 43200; // 12 hours
	// A session's last-seen time is only rewritten when older than this. Fewer writes
	// means two overlapping requests can no longer wipe out each other's session.
	const SESSION_TOUCH_SECONDS = 300;
	// Unsaved text rescued at lock time is kept this long.
	const DRAFT_KEEP_SECONDS = 1209600; // 14 days
	// Wrong PIN attempts allowed before a temporary lockout.
	const MAX_PIN_ATTEMPTS = 5;
	const PIN_LOCKOUT_SECONDS = 900; // 15 minutes
	// "Forgot PIN" e-mail link validity.
	const PIN_RESET_SECONDS = 1800; // 30 minutes

	const META_PIN       = '_sdc_pin_hash';
	const META_SESSIONS  = '_sdc_unlock_sessions';
	const META_FAILS     = '_sdc_pin_fails';
	const META_LOCKOUT   = '_sdc_pin_lockout_until';
	const META_RESET     = '_sdc_pin_reset';
	const META_DRAFT     = '_sdc_draft';

	// The one user who may open the calendar. Not a role: other administrators are refused too.
	const OPTION_OWNER   = 'sdc_owner_id';

	private $table_content;
	private $table_holidays;
	private $table_events;
	private $table_books;
	private $table_films;
	private $table_series;

	public function __construct() {
		global $wpdb;
		$this->table_content  = $wpdb->prefix . 'daily_calendar_content';
		$this->table_holidays = $wpdb->prefix . 'daily_calendar_holidays';
		$this->table_events   = $wpdb->prefix . 'daily_calendar_events';
		$this->table_books    = $wpdb->prefix . 'daily_calendar_books';
		$this->table_films    = $wpdb->prefix . 'daily_calendar_films';
		$this->table_series   = $wpdb->prefix . 'daily_calendar_series';

		register_activation_hook( __FILE__, array( $this, 'create_tables' ) );
		// Only the current owner may change who the owner is (blocks edits via wp-admin/options.php).
		add_filter( 'pre_update_option_' . self::OPTION_OWNER, array( $this, 'guard_owner_option' ), 10, 2 );
		add_shortcode( 'daily_calendar', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'maybe_no_cache' ) );
		add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );
		// Files can arrive by automatic deploy (no plugin re-activation), so check the DB on every load.
		add_action( 'init', array( $this, 'maybe_upgrade_db' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_sdc_get_content', array( $this, 'ajax_get_content' ) );
		add_action( 'wp_ajax_sdc_save_content', array( $this, 'ajax_save_content' ) );
		add_action( 'wp_ajax_sdc_add_holiday', array( $this, 'ajax_add_holiday' ) );
		add_action( 'wp_ajax_sdc_delete_holiday', array( $this, 'ajax_delete_holiday' ) );
		add_action( 'wp_ajax_sdc_download_report', array( $this, 'ajax_download_report' ) );
		add_action( 'wp_ajax_sdc_add_event', array( $this, 'ajax_add_event' ) );
		add_action( 'wp_ajax_sdc_delete_event', array( $this, 'ajax_delete_event' ) );

		// PIN lock endpoints
		add_action( 'wp_ajax_sdc_unlock', array( $this, 'ajax_unlock' ) );
		add_action( 'wp_ajax_sdc_resume', array( $this, 'ajax_resume' ) );
		add_action( 'wp_ajax_sdc_ping', array( $this, 'ajax_ping' ) );
		add_action( 'wp_ajax_sdc_lock', array( $this, 'ajax_lock' ) );
		add_action( 'wp_ajax_sdc_set_pin', array( $this, 'ajax_set_pin' ) );
		add_action( 'wp_ajax_sdc_request_pin_reset', array( $this, 'ajax_request_pin_reset' ) );
		add_action( 'wp_ajax_sdc_discard_draft', array( $this, 'ajax_discard_draft' ) );

		// Sol – coach / mentor chat (OpenAI)
		require_once plugin_dir_path( __FILE__ ) . 'includes/sdc-sol.php';
		$this->sol = new SDC_Sol( $this );
	}

	/** @var SDC_Sol */
	public $sol;

	// =========================================================
	// --- PIN LOCK: helpers ---
	// =========================================================

	/**
	 * The owner's user ID.
	 *
	 * 1. SDC_OWNER_ID in wp-config.php, if defined (cannot be changed from wp-admin).
	 * 2. The saved sdc_owner_id option.
	 * 3. First run after this update: the only user who already has a PIN, otherwise
	 *    the administrator whose e-mail is the site's admin e-mail. Saved once.
	 */
	private function owner_id() {
		if ( defined( 'SDC_OWNER_ID' ) ) {
			return (int) SDC_OWNER_ID;
		}
		$owner = (int) get_option( self::OPTION_OWNER, 0 );
		if ( $owner && get_userdata( $owner ) ) {
			return $owner;
		}
		$with_pin = get_users( array( 'meta_key' => self::META_PIN, 'fields' => 'ID', 'number' => 2 ) );
		if ( 1 === count( $with_pin ) ) {
			$owner = (int) $with_pin[0];
		} else {
			$admin = get_user_by( 'email', get_option( 'admin_email' ) );
			$owner = ( $admin && user_can( $admin, 'manage_options' ) ) ? (int) $admin->ID : 0;
		}
		if ( $owner ) {
			update_option( self::OPTION_OWNER, $owner, false );
		}
		return $owner;
	}

	public function guard_owner_option( $new, $old ) {
		$old = (int) $old;
		if ( $old && $old !== get_current_user_id() ) {
			return $old;
		}
		return $new;
	}

	/** Only the owner — no matter what role anyone else has. */
	private function can_use_calendar() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$owner = $this->owner_id();
		return $owner > 0 && get_current_user_id() === $owner;
	}

	private function lockout_remaining( $uid ) {
		$until = (int) get_user_meta( $uid, self::META_LOCKOUT, true );
		return ( $until > time() ) ? $until - time() : 0;
	}

	/** Records a failed PIN / password attempt. Returns attempts left (0 = now locked out). */
	private function register_failure( $uid ) {
		$fails = (int) get_user_meta( $uid, self::META_FAILS, true ) + 1;
		if ( $fails >= self::MAX_PIN_ATTEMPTS ) {
			update_user_meta( $uid, self::META_LOCKOUT, time() + self::PIN_LOCKOUT_SECONDS );
			update_user_meta( $uid, self::META_FAILS, 0 );
			return 0;
		}
		update_user_meta( $uid, self::META_FAILS, $fails );
		return self::MAX_PIN_ATTEMPTS - $fails;
	}

	private function clear_failures( $uid ) {
		delete_user_meta( $uid, self::META_FAILS );
		delete_user_meta( $uid, self::META_LOCKOUT );
	}

	private function send_throttled( $seconds ) {
		$mins = max( 1, (int) ceil( $seconds / 60 ) );
		wp_send_json_error( array(
			'code'    => 'sdc_throttled',
			'message' => sprintf( 'Too many wrong attempts. Try again in %d min.', $mins ),
		), 429 );
	}

	/** Active (non-expired) unlock sessions: sha256(token) => last-seen timestamp. */
	private function get_sessions( $uid ) {
		$sessions = get_user_meta( $uid, self::META_SESSIONS, true );
		if ( ! is_array( $sessions ) ) $sessions = array();
		$now = time();
		foreach ( $sessions as $hash => $last ) {
			if ( $now - (int) $last > self::SERVER_IDLE_SECONDS ) unset( $sessions[ $hash ] );
		}
		return $sessions;
	}

	private function start_session( $uid ) {
		$token    = bin2hex( random_bytes( 32 ) );
		$sessions = $this->get_sessions( $uid );
		$sessions[ hash( 'sha256', $token ) ] = time();
		if ( count( $sessions ) > 5 ) { // keep at most 5 open tabs/devices
			asort( $sessions );
			$sessions = array_slice( $sessions, -5, null, true );
		}
		update_user_meta( $uid, self::META_SESSIONS, $sessions );
		return $token;
	}

	/** Validates a token and refreshes its last-seen time. */
	private function touch_session( $uid, $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return false;
		$sessions = $this->get_sessions( $uid );
		$hash     = hash( 'sha256', $token );
		if ( ! isset( $sessions[ $hash ] ) ) return false;
		if ( time() - (int) $sessions[ $hash ] >= self::SESSION_TOUCH_SECONDS ) {
			$sessions[ $hash ] = time();
			update_user_meta( $uid, self::META_SESSIONS, $sessions );
		}
		return true;
	}

	private function end_session( $uid, $token ) {
		$sessions = $this->get_sessions( $uid );
		if ( is_string( $token ) ) unset( $sessions[ hash( 'sha256', $token ) ] );
		update_user_meta( $uid, self::META_SESSIONS, $sessions );
	}

	private function posted_token() {
		return isset( $_POST['sdc_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sdc_token'] ) ) : '';
	}

	private function posted_pin( $key ) {
		return isset( $_POST[ $key ] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Gate for every endpoint that reads or writes calendar data.
	 * Stops the request unless the user is allowed AND has a live PIN session.
	 */
	public function require_unlocked() {
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! $this->can_use_calendar() ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		if ( ! $this->touch_session( get_current_user_id(), $this->posted_token() ) ) {
			wp_send_json_error( array( 'code' => 'sdc_locked', 'message' => 'Calendar is locked.' ), 401 );
		}
	}

	private function send_unlocked( $uid ) {
		wp_send_json_success( array(
			'token'       => $this->start_session( $uid ),
			'events_json' => $this->get_all_events_json(),
			'draft'       => $this->get_draft( $uid ),
			'chat_draft'  => $this->sol->take_chat_draft( $uid ),
		) );
	}

	// =========================================================
	// --- DRAFT RESCUE: text that was being written when the calendar locked ---
	// It is stored on the server (never in the browser) and only handed back
	// after the PIN has been entered again.
	// =========================================================

	private function get_draft( $uid ) {
		$draft = get_user_meta( $uid, self::META_DRAFT, true );
		if ( ! is_array( $draft ) || empty( $draft['date'] ) ) return null;
		if ( time() - (int) $draft['saved'] > self::DRAFT_KEEP_SECONDS ) {
			delete_user_meta( $uid, self::META_DRAFT );
			return null;
		}
		return $draft;
	}

	private function clean_rating( $r ) {
		if ( $r === '' || $r === null || ! is_numeric( $r ) ) return '';
		return (string) max( 0, min( 6, intval( $r ) ) );
	}

	private function clean_rows( $rows, $with_status ) {
		$out = array();
		if ( ! is_array( $rows ) ) return $out;
		foreach ( array_slice( $rows, 0, 100 ) as $row ) {
			if ( ! is_array( $row ) ) continue;
			$title = sanitize_text_field( isset( $row['title'] ) ? (string) $row['title'] : '' );
			if ( $title === '' ) continue;
			$item = array(
				'id'     => ! empty( $row['id'] ) ? (string) intval( $row['id'] ) : '',
				'title'  => $title,
				'rating' => $this->clean_rating( isset( $row['rating'] ) ? $row['rating'] : '' ),
			);
			if ( $with_status ) {
				$item['status'] = ( isset( $row['status'] ) && in_array( $row['status'], array( 'start', 'in_progress', 'completed' ), true ) ) ? $row['status'] : 'start';
			}
			$out[] = $item;
		}
		return $out;
	}

	/** Turns the JSON sent by the browser into a safe, known structure (or null). */
	private function clean_draft( $raw ) {
		if ( ! is_string( $raw ) || $raw === '' || strlen( $raw ) > 1048576 ) return null;
		$d = json_decode( $raw, true );
		if ( ! is_array( $d ) || empty( $d['date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d['date'] ) ) return null;
		$f = ( isset( $d['fields'] ) && is_array( $d['fields'] ) ) ? $d['fields'] : array();

		$fields = array();
		foreach ( array( 'sdc_daily_text', 'sdc_highlights', 'sdc_talking_head', 'sdc_podcasts', 'sdc_lessons', 'sdc_posts_entries' ) as $k ) {
			$fields[ $k ] = isset( $f[ $k ] ) ? wp_kses_post( (string) $f[ $k ] ) : '';
		}
		foreach ( array( 'sdc_ykw', 'sdc_sport', 'sdc_shower', 'sdc_reading' ) as $k ) {
			$fields[ $k ] = ! empty( $f[ $k ] );
		}
		$fields['sdc_weight']        = ( isset( $f['sdc_weight'] ) && is_numeric( $f['sdc_weight'] ) ) ? (string) floatval( $f['sdc_weight'] ) : '';
		$fields['sdc_image_url']     = isset( $f['sdc_image_url'] ) ? esc_url_raw( (string) $f['sdc_image_url'] ) : '';
		$fields['sdc_image_caption'] = isset( $f['sdc_image_caption'] ) ? sanitize_textarea_field( (string) $f['sdc_image_caption'] ) : '';

		$ids = function ( $list ) {
			return is_array( $list ) ? array_values( array_filter( array_map( 'intval', array_slice( $list, 0, 200 ) ) ) ) : array();
		};

		return array(
			'date'           => $d['date'],
			'fields'         => $fields,
			'books'          => $this->clean_rows( isset( $d['books'] ) ? $d['books'] : array(), true ),
			'films'          => $this->clean_rows( isset( $d['films'] ) ? $d['films'] : array(), false ),
			'series'         => $this->clean_rows( isset( $d['series'] ) ? $d['series'] : array(), true ),
			'deleted_books'  => $ids( isset( $d['deleted_books'] ) ? $d['deleted_books'] : array() ),
			'deleted_series' => $ids( isset( $d['deleted_series'] ) ? $d['deleted_series'] : array() ),
			'saved'          => time(),
		);
	}

	public function ajax_discard_draft() {
		$this->require_unlocked();
		delete_user_meta( get_current_user_id(), self::META_DRAFT );
		wp_send_json_success( 'discarded' );
	}

	// =========================================================
	// --- PIN LOCK: AJAX endpoints ---
	// =========================================================

	public function ajax_unlock() {
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! $this->can_use_calendar() ) wp_send_json_error( 'Permission denied.', 403 );
		$uid = get_current_user_id();

		$wait = $this->lockout_remaining( $uid );
		if ( $wait ) $this->send_throttled( $wait );

		$hash = get_user_meta( $uid, self::META_PIN, true );
		if ( ! $hash ) wp_send_json_error( array( 'code' => 'sdc_no_pin', 'message' => 'No PIN set yet.' ), 400 );

		$pin = $this->posted_pin( 'pin' );
		// NB: no user ID passed, so WordPress never touches the account password.
		if ( $pin === '' || ! wp_check_password( $pin, $hash ) ) {
			$left = $this->register_failure( $uid );
			if ( $left === 0 ) $this->send_throttled( self::PIN_LOCKOUT_SECONDS );
			wp_send_json_error( array(
				'code'    => 'sdc_bad_pin',
				'message' => sprintf( 'Wrong PIN. %d attempt%s left.', $left, $left === 1 ? '' : 's' ),
			), 403 );
		}

		$this->clear_failures( $uid );
		$this->send_unlocked( $uid );
	}

	/** Re-opens the calendar after a page reload (e.g. after saving) in the same tab. */
	public function ajax_resume() {
		$this->require_unlocked();
		wp_send_json_success( array( 'events_json' => $this->get_all_events_json() ) );
	}

	public function ajax_ping() {
		$this->require_unlocked();
		wp_send_json_success( 'ok' );
	}

	/**
	 * Locks this tab. If the browser sends the text you were writing, it is kept
	 * as a draft and offered back after the next PIN unlock. Storing a draft does
	 * not need a live session (it may have just expired); reading it back does.
	 */
	public function ajax_lock() {
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! is_user_logged_in() ) wp_send_json_success( 'locked' );
		$uid = get_current_user_id();

		$draft_saved = false;
		if ( $this->can_use_calendar() && isset( $_POST['draft'] ) ) {
			$draft = $this->clean_draft( wp_unslash( $_POST['draft'] ) );
			if ( $draft ) {
				update_user_meta( $uid, self::META_DRAFT, $draft );
				$draft_saved = true;
			}
		}
		// A message to Sol that was typed but not sent yet.
		if ( $this->can_use_calendar() && isset( $_POST['chat_draft'] ) ) {
			if ( $this->sol->store_chat_draft( $uid, wp_unslash( $_POST['chat_draft'] ) ) ) $draft_saved = true;
		}

		$this->end_session( $uid, $this->posted_token() );
		wp_send_json_success( array( 'locked' => true, 'draft_saved' => $draft_saved ) );
	}

	/**
	 * Sets the PIN. Allowed when:
	 *  - no PIN exists yet (first-time setup), or
	 *  - a valid "Forgot PIN" e-mail reset token is supplied.
	 */
	public function ajax_set_pin() {
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! $this->can_use_calendar() ) wp_send_json_error( 'Permission denied.', 403 );
		$uid = get_current_user_id();

		$wait = $this->lockout_remaining( $uid );
		if ( $wait ) $this->send_throttled( $wait );

		$pin  = $this->posted_pin( 'pin' );
		$pin2 = $this->posted_pin( 'pin_confirm' );
		if ( ! preg_match( '/^\d{4,8}$/', $pin ) ) {
			wp_send_json_error( array( 'code' => 'sdc_invalid', 'message' => 'PIN must be 4–8 digits.' ), 400 );
		}
		if ( $pin !== $pin2 ) {
			wp_send_json_error( array( 'code' => 'sdc_invalid', 'message' => 'The two PINs don\'t match.' ), 400 );
		}

		if ( get_user_meta( $uid, self::META_PIN, true ) ) {
			$reset = get_user_meta( $uid, self::META_RESET, true );
			$given = isset( $_POST['reset_token'] ) ? sanitize_text_field( wp_unslash( $_POST['reset_token'] ) ) : '';
			$valid = is_array( $reset ) && ! empty( $reset['hash'] ) && (int) $reset['expires'] > time()
				&& $given !== '' && hash_equals( $reset['hash'], hash( 'sha256', $given ) );
			if ( ! $valid ) {
				$left = $this->register_failure( $uid );
				if ( $left === 0 ) $this->send_throttled( self::PIN_LOCKOUT_SECONDS );
				wp_send_json_error( array(
					'code'    => 'sdc_bad_reset',
					'message' => 'This reset link is invalid or has expired. Request a new one.',
				), 403 );
			}
			delete_user_meta( $uid, self::META_RESET );
		}

		update_user_meta( $uid, self::META_PIN, wp_hash_password( $pin ) );
		delete_user_meta( $uid, self::META_SESSIONS ); // sign out every other open tab
		$this->clear_failures( $uid );
		$this->send_unlocked( $uid );
	}

	/** "Forgot PIN": e-mails a one-time link to the account's e-mail address. */
	public function ajax_request_pin_reset() {
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! $this->can_use_calendar() ) wp_send_json_error( 'Permission denied.', 403 );
		$uid  = get_current_user_id();
		$user = wp_get_current_user();

		$reset = get_user_meta( $uid, self::META_RESET, true );
		if ( is_array( $reset ) && isset( $reset['sent'] ) && time() - (int) $reset['sent'] < 60 ) {
			wp_send_json_error( array( 'code' => 'sdc_wait', 'message' => 'A link was just sent. Wait a minute before asking again.' ), 429 );
		}

		$return_url = isset( $_POST['return_url'] ) ? esc_url_raw( wp_unslash( $_POST['return_url'] ) ) : '';
		$return_url = wp_validate_redirect( $return_url, home_url( '/' ) );

		$token = bin2hex( random_bytes( 32 ) );
		update_user_meta( $uid, self::META_RESET, array(
			'hash'    => hash( 'sha256', $token ),
			'expires' => time() + self::PIN_RESET_SECONDS,
			'sent'    => time(),
		) );

		$link    = add_query_arg( 'sdc_pin_reset', $token, $return_url );
		$subject = 'Kalendarz – reset your PIN';
		$body    = "Someone (hopefully you) asked to reset the Kalendarz PIN on " . home_url( '/' ) . ".\n\n"
			. "Open this link within 30 minutes to choose a new PIN:\n" . $link . "\n\n"
			. "If it wasn't you, ignore this e-mail – your current PIN keeps working.";

		if ( ! wp_mail( $user->user_email, $subject, $body ) ) {
			delete_user_meta( $uid, self::META_RESET );
			wp_send_json_error( array( 'code' => 'sdc_mail', 'message' => 'The e-mail could not be sent from this site.' ), 500 );
		}

		$at = strpos( $user->user_email, '@' );
		$masked = ( $at > 1 ) ? substr( $user->user_email, 0, 1 ) . str_repeat( '•', $at - 1 ) . substr( $user->user_email, $at ) : $user->user_email;
		wp_send_json_success( array( 'message' => 'Reset link sent to ' . $masked . '. It works for 30 minutes.' ) );
	}

	public function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql1 = "CREATE TABLE $this->table_content (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			event_date DATE NOT NULL,
			image_url TEXT,
			image_caption TEXT,
			ykw TINYINT(1) DEFAULT 0,
			sport TINYINT(1) DEFAULT 0,
			shower TINYINT(1) DEFAULT 0,
			reading TINYINT(1) DEFAULT 0,
			weight FLOAT,
			highlights TEXT,
			daily_text LONGTEXT,
			talking_head TEXT,
			podcasts TEXT,
			books TEXT,
			films TEXT,
			films_rating TINYINT(1) DEFAULT 0,
			lessons TEXT,
			posts_entries TEXT,
			PRIMARY KEY  (id),
			UNIQUE KEY event_date (event_date)
		) $charset_collate;";

		$sql2 = "CREATE TABLE $this->table_holidays (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			image_url TEXT,
			start_date DATE NOT NULL,
			end_date DATE NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		$sql3 = "CREATE TABLE $this->table_events (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			image_url TEXT,
			start_date DATE NOT NULL,
			end_date DATE NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		$sql4 = "CREATE TABLE $this->table_books (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			title VARCHAR(500) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'start',
			rating TINYINT(1) DEFAULT NULL,
			start_date DATE NOT NULL,
			completion_date DATE DEFAULT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		$sql5 = "CREATE TABLE $this->table_films (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			event_date DATE NOT NULL,
			title VARCHAR(500) NOT NULL,
			rating TINYINT(1) DEFAULT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		$sql6 = "CREATE TABLE $this->table_series (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			title VARCHAR(500) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'start',
			rating TINYINT(1) DEFAULT NULL,
			start_date DATE NOT NULL,
			completion_date DATE DEFAULT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql1 );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
		dbDelta( $sql4 );
		dbDelta( $sql5 );
		dbDelta( $sql6 );
		foreach ( $this->sol->create_tables_sql( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Brings an existing database up to DB_VERSION (e.g. adds the `reading` column in 6.1).
	 * Cheap: one option read per request; the real work runs once after an update.
	 */
	public function maybe_upgrade_db() {
		if ( get_option( self::OPT_DB_VERSION ) === self::DB_VERSION ) return;
		global $wpdb;
		$this->create_tables(); // dbDelta adds missing columns, never removes data
		// Belt and braces: make sure the column really exists before saves start using it.
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $this->table_content LIKE %s", 'reading' ) ) ) {
			$wpdb->query( "ALTER TABLE $this->table_content ADD COLUMN reading TINYINT(1) DEFAULT 0 AFTER shower" );
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $this->table_content LIKE %s", 'reading' ) ) ) {
			update_option( self::OPT_DB_VERSION, self::DB_VERSION, false );
		}
	}

	public function maybe_create_tables() {
		global $wpdb;
		$tables = array_merge( array( $this->table_events, $this->table_books, $this->table_films, $this->table_series ), $this->sol->table_names() );
		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) != $table ) {
				$this->create_tables();
				break;
			}
		}
	}

	public function enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'daily_calendar' ) && $this->can_use_calendar() ) {
			wp_enqueue_media();
			wp_enqueue_style( 'fullcalendar-css', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css', array(), '6.1.10' );
			wp_enqueue_script( 'fullcalendar-js', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js', array( 'jquery' ), '6.1.10', true );
			wp_enqueue_script( 'ckeditor-js', 'https://cdn.ckeditor.com/ckeditor5/41.2.0/classic/ckeditor.js', array(), '41.2.0', true );

			wp_enqueue_style( 'sdc-style', plugin_dir_url( __FILE__ ) . 'assets/sdc-style.css', array(), self::VERSION );
			wp_enqueue_script( 'sdc-lock', plugin_dir_url( __FILE__ ) . 'assets/sdc-lock.js', array( 'jquery' ), self::VERSION, true );
			wp_enqueue_script( 'sdc-script', plugin_dir_url( __FILE__ ) . 'assets/sdc-script.js', array( 'jquery', 'fullcalendar-js', 'ckeditor-js', 'sdc-lock' ), self::VERSION, true );
			wp_enqueue_script( 'sdc-sol', plugin_dir_url( __FILE__ ) . 'assets/sdc-sol.js', array( 'jquery', 'sdc-lock' ), self::VERSION, true );

			// Calendar data is NOT embedded in the page any more – it is only
			// returned by AJAX after the PIN has been entered.
			$vars = array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'sdc_nonce' ),
				'can_edit'      => $this->can_use_calendar(),
				'has_pin'       => (bool) get_user_meta( get_current_user_id(), self::META_PIN, true ),
				'user_key'      => md5( get_current_user_id() . wp_salt( 'nonce' ) ),
				'sol_configured' => SDC_Sol::api_key() !== '',
			);
			wp_localize_script( 'sdc-lock', 'sdcVars', $vars );
		}
	}

	/** Keep the private calendar page out of browser / proxy caches. */
	public function maybe_no_cache() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'daily_calendar' ) ) {
			nocache_headers();
		}
	}

	private function get_all_events_json() {
		global $wpdb;
		$events = array();

		// Build lookup maps for books/films/series tables
		$book_dates = array();
		$all_books = $wpdb->get_results( "SELECT start_date, completion_date FROM $this->table_books" );
		if ( $all_books ) {
			foreach ( $all_books as $b ) {
				$book_dates[ $b->start_date ] = true;
				if ( $b->completion_date ) $book_dates[ $b->completion_date ] = true;
			}
		}

		$film_dates = array();
		$all_films = $wpdb->get_results( "SELECT DISTINCT event_date FROM $this->table_films" );
		if ( $all_films ) {
			foreach ( $all_films as $f ) {
				$film_dates[ $f->event_date ] = true;
			}
		}

		$series_dates = array();
		$all_series_db = $wpdb->get_results( "SELECT start_date, completion_date FROM $this->table_series" );
		if ( $all_series_db ) {
			foreach ( $all_series_db as $s ) {
				$series_dates[ $s->start_date ] = true;
				if ( $s->completion_date ) $series_dates[ $s->completion_date ] = true;
			}
		}

		$content_dates = array();
		$results_content = $wpdb->get_results( "SELECT * FROM $this->table_content", ARRAY_A );
		foreach( $results_content as $row ) {
			$content_dates[ $row['event_date'] ] = true;
			$icons = '';
			if ( ! empty( $row['weight'] ) ) $icons .= '⚖️ ';
			if ( $row['shower'] == 1 ) $icons .= '🚿 ';
			if ( ! empty( $row['reading'] ) ) $icons .= '👓 ';
			if ( $row['sport'] == 1 )  $icons .= '🚴 ';
			if ( $row['ykw'] == 1 )    $icons .= '🤫 ';
			if ( ! empty( $row['highlights'] ) )    $icons .= '⭐ ';
			if ( ! empty( $row['daily_text'] ) )    $icons .= '📝 ';
			if ( ! empty( $row['talking_head'] ) )  $icons .= '🗣️ ';
			if ( ! empty( $row['podcasts'] ) )      $icons .= '🎧 ';
			if ( ! empty( $row['books'] ) || isset( $book_dates[ $row['event_date'] ] ) ) $icons .= '📖 ';
			if ( ! empty( $row['films'] ) || isset( $film_dates[ $row['event_date'] ] ) ) $icons .= '🎬 ';
			if ( isset( $series_dates[ $row['event_date'] ] ) ) $icons .= '📺 ';
			if ( ! empty( $row['lessons'] ) )       $icons .= '🌳 ';
			if ( ! empty( $row['posts_entries'] ) ) $icons .= '✍️ ';
			// The picture icon itself is drawn by the month view (it opens the image),
			// so it is not part of the title text.
			$has_image = ! empty( $row['image_url'] );

			if( ! empty( $icons ) || $has_image ) {
				$events[] = array(
					'id'              => 'content-' . $row['id'],
					'start'           => $row['event_date'],
					'title'           => $icons,
					'display'         => 'block',
					'backgroundColor' => 'transparent',
					'borderColor'     => 'transparent',
					'textColor'       => '#2c3338',
					'classNames'      => ['sdc-habit-row'],
					'extendedProps'   => array(
						'type' => 'content',
						'weight' => !empty($row['weight']),
						'shower' => $row['shower'] == 1,
						'reading' => ! empty( $row['reading'] ),
						'sport'  => $row['sport'] == 1,
						'ykw'    => $row['ykw'] == 1,
						'highlights' => !empty($row['highlights']),
						'daily_text' => !empty($row['daily_text']),
						'talking_head' => !empty($row['talking_head']),
						'podcasts' => !empty($row['podcasts']),
						'books' => !empty($row['books']) || isset( $book_dates[ $row['event_date'] ] ),
						'films' => !empty($row['films']) || isset( $film_dates[ $row['event_date'] ] ),
						'series' => isset( $series_dates[ $row['event_date'] ] ),
						'lessons' => !empty($row['lessons']),
						'posts_entries' => !empty($row['posts_entries']),
						'image' => $has_image,
						'image_url' => $has_image ? esc_url_raw( $row['image_url'] ) : '',
						'image_caption' => $has_image ? (string) $row['image_caption'] : ''
					)
				);
			}
		}

		// Add icon events for dates that only have books/films/series (no content row)
		$extra_dates = array();
		foreach ( array_keys( $book_dates ) as $d ) {
			if ( ! isset( $content_dates[ $d ] ) ) $extra_dates[ $d ] = true;
		}
		foreach ( array_keys( $film_dates ) as $d ) {
			if ( ! isset( $content_dates[ $d ] ) ) $extra_dates[ $d ] = true;
		}
		foreach ( array_keys( $series_dates ) as $d ) {
			if ( ! isset( $content_dates[ $d ] ) ) $extra_dates[ $d ] = true;
		}
		foreach ( array_keys( $extra_dates ) as $d ) {
			$icons = '';
			if ( isset( $book_dates[ $d ] ) ) $icons .= '📖 ';
			if ( isset( $film_dates[ $d ] ) ) $icons .= '🎬 ';
			if ( isset( $series_dates[ $d ] ) ) $icons .= '📺 ';
			if ( ! empty( $icons ) ) {
				$events[] = array(
					'id'              => 'extra-' . $d,
					'start'           => $d,
					'title'           => $icons,
					'display'         => 'block',
					'backgroundColor' => 'transparent',
					'borderColor'     => 'transparent',
					'textColor'       => '#2c3338',
					'classNames'      => ['sdc-habit-row'],
					'extendedProps'   => array(
						'type'   => 'content',
						'books'  => isset( $book_dates[ $d ] ),
						'films'  => isset( $film_dates[ $d ] ),
						'series' => isset( $series_dates[ $d ] )
					)
				);
			}
		}

		$results_holidays = $wpdb->get_results( "SELECT * FROM $this->table_holidays", ARRAY_A );
		foreach( $results_holidays as $row ) {
			$end_date_visual = date('Y-m-d', strtotime($row['end_date'] . ' +1 day'));
			$events[] = array(
				'id'              => 'holiday-' . $row['id'],
				'title'           => '',
				'start'           => $row['start_date'],
				'end'             => $end_date_visual,
				'backgroundColor' => '#0856c9',
				'borderColor'     => '#0856c9',
				'classNames'      => ['sdc-holiday-stripe'],
				'extendedProps'   => array( 'type' => 'holiday' )
			);
		}

		$results_events = $wpdb->get_results( "SELECT * FROM $this->table_events", ARRAY_A );
		foreach( $results_events as $row ) {
			$end_date_visual = date('Y-m-d', strtotime($row['end_date'] . ' +1 day'));
			$events[] = array(
				'id'              => 'event-' . $row['id'],
				'title'           => '',
				'start'           => $row['start_date'],
				'end'             => $end_date_visual,
				'backgroundColor' => '#16a34a',
				'borderColor'     => '#16a34a',
				'classNames'      => ['sdc-event-stripe'],
				'extendedProps'   => array( 'type' => 'event' )
			);
		}

		return json_encode( $events );
	}

	private function render_lock_screen() {
		ob_start();
		?>
		<div id="sdc-lock-screen" class="sdc-lock-screen" aria-live="polite">
			<div class="sdc-lock-card">
				<div class="sdc-lock-icon" aria-hidden="true">🔒</div>
				<h2 class="sdc-lock-title" id="sdc-lock-title">Kalendarz is locked</h2>
				<p class="sdc-lock-sub" id="sdc-lock-sub">Enter your PIN to open it.</p>

				<form id="sdc-lock-form" class="sdc-lock-form" autocomplete="off">
					<input type="password" id="sdc-pin" class="sdc-pin-input" inputmode="numeric" pattern="[0-9]*"
						maxlength="8" autocomplete="off" placeholder="PIN" aria-label="PIN">
					<input type="password" id="sdc-pin-confirm" class="sdc-pin-input" inputmode="numeric" pattern="[0-9]*"
						maxlength="8" autocomplete="off" placeholder="Repeat PIN" aria-label="Repeat PIN" style="display:none;">
					<button type="submit" id="sdc-lock-submit" class="sdc-lock-btn">Unlock</button>
				</form>

				<div id="sdc-lock-msg" class="sdc-lock-msg" role="alert"></div>

				<div class="sdc-lock-links">
					<a href="#" id="sdc-forgot-pin">Forgot PIN?</a>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div style="max-width:480px; margin:60px auto; text-align:center; padding:40px; background:#f9f9f9; border:1px solid #ddd; border-radius:12px;">'
				. '<h2 style="margin-bottom:12px;">🔒 Private Calendar</h2>'
				. '<p style="color:#555;">This calendar is only visible to logged-in users.</p>'
				. '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" style="display:inline-block; margin-top:16px; padding:10px 24px; background:#0856c9; color:#fff; text-decoration:none; border-radius:6px;">Log In</a>'
				. '</div>';
		}

		if ( ! $this->can_use_calendar() ) {
			return '<div style="max-width:480px; margin:60px auto; text-align:center; padding:40px; background:#f9f9f9; border:1px solid #ddd; border-radius:12px;">'
				. '<h2 style="margin-bottom:12px;">🔒 Private Calendar</h2>'
				. '<p style="color:#555;">Details of this calendar are only visible to me. Thanks for visiting!</p>'
				. '</div>';
		}

		ob_start();
		echo $this->render_lock_screen();
		?>

		<div id="sdc-app" style="display:none;">

		<div class="sdc-lock-bar">
			<button type="button" id="sdc-sol-open" class="sdc-lock-now sdc-sol-open" title="Talk to Sol">💬 Sol</button>
			<button type="button" id="sdc-lock-now" class="sdc-lock-now" title="Lock now">🔒 Lock</button>
		</div>

		<div id="sdc-filter-bar-container"></div>

		<div id="sdc-calendar"></div>

		<?php if ( $this->can_use_calendar() ) : ?>
		<div id="sdc-export-container" style="max-width:400px; margin:30px auto; background:#f0f0f1; padding:15px; border-radius:8px; border:1px solid #ddd; text-align:center;">
			<div style="font-weight:bold; margin-bottom:8px; color:#333;">📊 Export Report</div>
			<div style="display:flex; gap:5px; justify-content:center;">
				<input type="month" id="sdc-export-month" style="padding:5px;" value="<?php echo date('Y-m'); ?>">
				<button id="sdc-export-btn" class="button" style="background:#0856c9; color:white; border:none;">Download CSV</button>
			</div>
		</div>
		<?php endif; ?>

		<div id="sdc-modal" class="sdc-modal" style="display:none;">
			<div class="sdc-modal-content">
				<div class="sdc-modal-topbar">
					<h3 id="sdc-modal-date-title">Date</h3>
					<div class="sdc-modal-actions">
            <button type="button" id="sdc-btn-prev-day" class="button sdc-nav-btn">Previous</button>
            <button type="button" id="sdc-btn-next-day" class="button sdc-nav-btn">Next</button>

						<?php if ( $this->can_use_calendar() ) : ?>
							<button type="button" id="sdc-btn-switch-to-edit-top" class="button button-primary">Edit</button>
						<?php endif; ?>
						<button type="button" class="button sdc-close-btn">Close</button>
						<button type="button" class="button sdc-lock-now-modal" title="Lock now">🔒 Lock</button>
					</div>
				</div>
				<div id="sdc-loading" style="display:none;">Loading data...</div>

				<?php if ( $this->can_use_calendar() ) : ?>
				<div class="sdc-tabs">
					<button class="sdc-tab-btn active" data-tab="content" id="sdc-tab-btn-content">Day Content</button>
					<button class="sdc-tab-btn" data-tab="holidays" id="sdc-tab-btn-holidays">Holidays</button>
					<button class="sdc-tab-btn" data-tab="events" id="sdc-tab-btn-events">Events</button>
				</div>
				<?php endif; ?>

				<div id="sdc-tab-content-area" class="sdc-tab-pane active">
					<div id="sdc-view-mode" style="display:none;">

						<div id="sdc-holiday-display-area"></div>
					<div id="sdc-event-display-area"></div>

						<div id="sdc-view-image-wrapper" style="display:none; margin-bottom: 20px;">
							<div id="sdc-view-image-container"></div>
							<div id="sdc-view-image-caption"></div>
						</div>

						<div class="sdc-view-section" style="background:#f9f9f9; padding:10px; border-radius:4px;">
							<div style="display:flex; justify-content:space-between;">
								<div><strong>Habits:</strong> <span id="view_habits" style="font-size:1.1rem;"></span></div>
								<div id="view_weight_container" style="font-weight:bold; color:#0856c9;"></div>
							</div>
						</div>

						<div class="sdc-view-section"><strong>📝 Daily Text:</strong> <div id="view_daily_text"></div></div>
						<div class="sdc-view-section"><strong>⭐ Highlights:</strong> <div id="view_highlights"></div></div>
						<div class="sdc-view-section"><strong>🗣️ Talking Head:</strong> <div id="view_talking_head"></div></div>
						<div class="sdc-view-section"><strong>🎧 Podcasts & Docs:</strong> <div id="view_podcasts"></div></div>
						<div class="sdc-view-section"><strong>📖 Books & Audiobooks:</strong> <div id="view_books"></div></div>
						<div class="sdc-view-section"><strong>🎬 Films:</strong> <div id="view_films"></div></div>
						<div class="sdc-view-section"><strong>📺 Series:</strong> <div id="view_series"></div></div>
						<div class="sdc-view-section"><strong>🌳 Blue Tree Lessons:</strong> <div id="view_lessons"></div></div>
						<div class="sdc-view-section"><strong>✍️ Posts & Enchiridion:</strong> <div id="view_posts_entries"></div></div>
					</div>

					<?php if ( $this->can_use_calendar() ) : ?>
					<div id="sdc-edit-mode" style="display:none;">
						<div id="sdc-draft-banner" class="sdc-draft-banner" style="display:none;">
							<span>↩️ <strong>Restored:</strong> the text you were writing when Kalendarz locked<span id="sdc-draft-when"></span>.
							Click <em>Save Content</em> to keep it.</span>
							<button type="button" id="sdc-draft-discard" class="button">Discard it</button>
						</div>
						<form id="sdc-content-form">
							<input type="hidden" id="sdc_date_field" name="sdc_date_field" value="">

							<div class="sdc-form-group" style="background:#f0f0f1; padding:10px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
								<div>
									<span style="font-weight:bold; margin-right:10px;">Habits:</span>
									<label><input type="checkbox" id="sdc_ykw" name="sdc_ykw" value="1"> 🤫 YKW</label> &nbsp;
									<label><input type="checkbox" id="sdc_sport" name="sdc_sport" value="1"> 🚴 SPORT</label> &nbsp;
									<label><input type="checkbox" id="sdc_shower" name="sdc_shower" value="1"> 🚿 SHOWER</label> &nbsp;
									<label title="Read today – even a few pages counts"><input type="checkbox" id="sdc_reading" name="sdc_reading" value="1"> 👓 READING</label>
									<span id="sdc-reading-streak" class="sdc-streak"></span>
								</div>
								<div>
									<label for="sdc_weight" style="font-weight:bold;">Weight (kg):</label>
									<input type="number" id="sdc_weight" name="sdc_weight" step="0.1" style="width:80px;" placeholder="0.0">
								</div>
							</div>

							<div class="sdc-form-group">
								<label>Image:</label>
								<div style="display:flex; gap:10px;">
									<input type="url" id="sdc_image_url" name="sdc_image_url" class="widefat" placeholder="https://..." style="flex-grow:1;">
									<input type="hidden" id="sdc_image_caption" name="sdc_image_caption" value="">
									<button type="button" id="sdc-upload-btn" class="button">Select Image</button>
								</div>
							</div>

							<?php
							$fields = [
								'sdc_daily_text' => '📝 Daily Text',
								'sdc_highlights' => '⭐ Highlights',
								'sdc_talking_head' => '🗣️ Talking Head',
								'sdc_podcasts' => '🎧 Podcasts & Documentaries'
							];
							foreach($fields as $id => $label): ?>
								<div class="sdc-form-group">
									<label for="<?php echo $id; ?>"><?php echo $label; ?>:</label>
									<textarea id="<?php echo $id; ?>" name="<?php echo $id; ?>" rows="2" class="widefat sdc-editor"></textarea>
								</div>
							<?php endforeach; ?>

							<div class="sdc-form-group">
								<label>📖 Books & Audiobooks:</label>
								<div id="sdc-books-rows"></div>
								<button type="button" id="sdc-add-book-btn" class="button sdc-add-row-btn">+ Add Book</button>
							</div>

							<div class="sdc-form-group">
								<label>🎬 Films:</label>
								<div id="sdc-films-rows"></div>
								<button type="button" id="sdc-add-film-btn" class="button sdc-add-row-btn">+ Add Film</button>
							</div>

							<div class="sdc-form-group">
								<label>📺 Series:</label>
								<div id="sdc-series-rows"></div>
								<button type="button" id="sdc-add-series-btn" class="button sdc-add-row-btn">+ Add Series</button>
							</div>

							<?php
							$fields2 = [
								'sdc_lessons' => '🌳 Lessons on The Blue Tree',
								'sdc_posts_entries' => '✍️ Posts and Enchiridion Entries'
							];
							foreach($fields2 as $id => $label): ?>
								<div class="sdc-form-group">
									<label for="<?php echo $id; ?>"><?php echo $label; ?>:</label>
									<textarea id="<?php echo $id; ?>" name="<?php echo $id; ?>" rows="2" class="widefat sdc-editor"></textarea>
								</div>
							<?php endforeach; ?>

							<button type="submit" id="sdc-save-btn" class="button button-primary">Save Content</button>
							<button type="button" id="sdc-cancel-edit-btn" class="button">Cancel</button>
							<span id="sdc-form-feedback"></span>
						</form>
					</div>
					<?php endif; ?>
				</div>

				<?php if ( $this->can_use_calendar() ) : ?>
				<div id="sdc-tab-events-area" class="sdc-tab-pane" style="display:none;">
					<h4 id="sdc-active-events-title">Active Events:</h4>
					<ul id="sdc-event-list" style="margin-bottom:20px; border-bottom:1px solid #ddd; padding-bottom:15px; padding-left:0; list-style-type:none;"></ul>

					<div id="sdc-add-event-wrapper">
						<h4>Add New Event</h4>
						<form id="sdc-event-form">
							<div class="sdc-form-group">
								<label>Event Description (e.g. Concert):</label>
								<input type="text" id="sdc_event_title" required class="widefat">
							</div>
							<div class="sdc-form-group">
								<label>Event Image (Optional):</label>
								<div style="display:flex; gap:10px;">
									<input type="url" id="sdc_event_image" name="sdc_event_image" class="widefat" placeholder="https://...">
									<button type="button" id="sdc-event-upload-btn" class="button">Select Image</button>
								</div>
							</div>
							<div style="display:flex; gap:10px;">
								<div class="sdc-form-group" style="flex:1">
									<label>From:</label>
									<input type="date" id="sdc_event_start" required class="widefat">
								</div>
								<div class="sdc-form-group" style="flex:1">
									<label>To (Inclusive):</label>
									<input type="date" id="sdc_event_end" required class="widefat">
								</div>
							</div>
							<button type="submit" class="button button-primary" style="background-color: #16a34a; border-color: #16a34a;">Add Event</button>
						</form>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( $this->can_use_calendar() ) : ?>
				<div id="sdc-tab-holidays-area" class="sdc-tab-pane" style="display:none;">
					<h4 id="sdc-active-holidays-title">Active Holidays:</h4>
					<ul id="sdc-holiday-list" style="margin-bottom:20px; border-bottom:1px solid #ddd; padding-bottom:15px; padding-left:0; list-style-type:none;"></ul>

					<div id="sdc-add-holiday-wrapper">
						<h4>Add New Holiday</h4>
						<form id="sdc-holiday-form">
							<div class="sdc-form-group">
								<label>Holiday Description (e.g. Rome):</label>
								<input type="text" id="sdc_holiday_title" required class="widefat">
							</div>
							<div class="sdc-form-group">
								<label>Holiday Image (Optional):</label>
								<div style="display:flex; gap:10px;">
									<input type="url" id="sdc_holiday_image" name="sdc_holiday_image" class="widefat" placeholder="https://...">
									<button type="button" id="sdc-holiday-upload-btn" class="button">Select Image</button>
								</div>
							</div>
							<div style="display:flex; gap:10px;">
								<div class="sdc-form-group" style="flex:1">
									<label>From:</label>
									<input type="date" id="sdc_holiday_start" required class="widefat">
								</div>
								<div class="sdc-form-group" style="flex:1">
									<label>To (Inclusive):</label>
									<input type="date" id="sdc_holiday_end" required class="widefat">
								</div>
							</div>
							<button type="submit" class="button button-primary" style="background-color: #0856c9; border-color: #0856c9;">Add Holiday</button>
						</form>
					</div>
				</div>
				<?php endif; ?>

			</div>
		</div>

		</div><!-- /#sdc-app -->
		<?php
		return ob_get_clean();
	}

	// --- AJAX HANDLERS ---

	public function ajax_get_content() {
		$this->require_unlocked();
		global $wpdb;
		$date = sanitize_text_field( $_POST['date'] );

		$content = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->table_content WHERE event_date = %s LIMIT 1", $date ), ARRAY_A );

		$holidays = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $this->table_holidays WHERE start_date <= %s AND end_date >= %s",
			$date, $date
		), ARRAY_A );

		$events = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $this->table_events WHERE start_date <= %s AND end_date >= %s",
			$date, $date
		), ARRAY_A );

		// Books: active (non-completed) for edit mode
		$books_active = $wpdb->get_results(
			"SELECT * FROM $this->table_books WHERE status != 'completed' ORDER BY start_date ASC",
			ARRAY_A
		);

		// Books: visible on this date for view mode
		$books_view = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $this->table_books WHERE start_date <= %s AND (completion_date IS NULL OR completion_date >= %s) ORDER BY start_date ASC",
			$date, $date
		), ARRAY_A );

		// Films for this specific date
		$films = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $this->table_films WHERE event_date = %s ORDER BY id ASC",
			$date
		), ARRAY_A );

		// Series: active (non-completed) for edit mode
		$series_active = $wpdb->get_results(
			"SELECT * FROM $this->table_series WHERE status != 'completed' ORDER BY start_date ASC",
			ARRAY_A
		);

		// Series: visible on this date for view mode
		$series_view = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $this->table_series WHERE start_date <= %s AND (completion_date IS NULL OR completion_date >= %s) ORDER BY start_date ASC",
			$date, $date
		), ARRAY_A );

		wp_send_json_success( array(
			'has_content' => (bool)$content,
			'data' => $content,
			'holidays' => $holidays,
			'events' => $events,
			'books_active' => $books_active ? $books_active : array(),
			'books_view' => $books_view ? $books_view : array(),
			'films' => $films ? $films : array(),
			'series_active' => $series_active ? $series_active : array(),
			'series_view' => $series_view ? $series_view : array()
		));
	}

	public function ajax_save_content() {
		$this->require_unlocked();
		global $wpdb;

		$event_date = sanitize_text_field( $_POST['sdc_date_field'] );
		if ( empty( $event_date ) ) wp_send_json_error( 'Missing date error.' );

		// Preserve legacy book/film columns from existing row
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT books, films, films_rating FROM $this->table_content WHERE event_date = %s",
			$event_date
		), ARRAY_A );

		$data = array(
			'event_date'   => $event_date,
			'image_url'    => esc_url_raw( $_POST['sdc_image_url'] ),
			'image_caption'=> sanitize_textarea_field( wp_unslash($_POST['sdc_image_caption']) ),
			'ykw'          => isset($_POST['sdc_ykw']) ? 1 : 0,
			'sport'        => isset($_POST['sdc_sport']) ? 1 : 0,
			'shower'       => isset($_POST['sdc_shower']) ? 1 : 0,
			'reading'      => isset($_POST['sdc_reading']) ? 1 : 0,
			'weight'       => ( isset($_POST['sdc_weight']) && $_POST['sdc_weight'] !== '' ) ? floatval($_POST['sdc_weight']) : null,
			'highlights'   => wp_kses_post( wp_unslash($_POST['sdc_highlights']) ),
			'daily_text'   => wp_kses_post( wp_unslash($_POST['sdc_daily_text']) ),
			'talking_head' => wp_kses_post( wp_unslash($_POST['sdc_talking_head']) ),
			'podcasts'     => wp_kses_post( wp_unslash($_POST['sdc_podcasts']) ),
			'books'        => $existing ? $existing['books'] : '',
			'films'        => $existing ? $existing['films'] : '',
			'films_rating' => $existing ? $existing['films_rating'] : null,
			'lessons'      => wp_kses_post( wp_unslash($_POST['sdc_lessons']) ),
			'posts_entries'=> wp_kses_post( wp_unslash($_POST['sdc_posts_entries']) ),
		);

		$result = $wpdb->replace( $this->table_content, $data );
		if ( false === $result ) wp_send_json_error( 'Database error.' );

		// Process books
		if ( isset($_POST['books_data']) ) {
			$books_data = json_decode( wp_unslash($_POST['books_data']), true );
			if ( is_array($books_data) ) {
				foreach ( $books_data as $book ) {
					$title = sanitize_text_field( $book['title'] );
					if ( empty($title) ) continue;

					$status = in_array( $book['status'], array('start','in_progress','completed') ) ? $book['status'] : 'start';
					$rating = ( isset($book['rating']) && $book['rating'] !== '' ) ? intval($book['rating']) : null;
					$book_id = ! empty($book['id']) ? intval($book['id']) : 0;

					if ( $book_id > 0 ) {
						$old = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM $this->table_books WHERE id = %d", $book_id ) );
						$update = array( 'title' => $title, 'status' => $status, 'rating' => $rating );
						if ( $status === 'completed' && $old && $old->status !== 'completed' ) {
							$update['completion_date'] = $event_date;
						}
						$wpdb->update( $this->table_books, $update, array( 'id' => $book_id ) );
					} else {
						$wpdb->insert( $this->table_books, array(
							'title' => $title,
							'status' => $status,
							'rating' => $rating,
							'start_date' => $event_date,
							'completion_date' => ( $status === 'completed' ) ? $event_date : null
						));
					}
				}
			}
		}

		// Process deleted books
		if ( isset($_POST['deleted_books']) ) {
			$deleted = json_decode( wp_unslash($_POST['deleted_books']), true );
			if ( is_array($deleted) ) {
				foreach ( $deleted as $id ) {
					$wpdb->delete( $this->table_books, array( 'id' => intval($id) ) );
				}
			}
		}

		// Process films - replace all for this date
		$wpdb->delete( $this->table_films, array( 'event_date' => $event_date ) );
		if ( isset($_POST['films_data']) ) {
			$films_data = json_decode( wp_unslash($_POST['films_data']), true );
			if ( is_array($films_data) ) {
				foreach ( $films_data as $film ) {
					$title = sanitize_text_field( $film['title'] );
					if ( empty($title) ) continue;
					$rating = ( isset($film['rating']) && $film['rating'] !== '' ) ? intval($film['rating']) : null;
					$wpdb->insert( $this->table_films, array(
						'event_date' => $event_date,
						'title' => $title,
						'rating' => $rating
					));
				}
			}
		}

		// Process series
		if ( isset($_POST['series_data']) ) {
			$series_data = json_decode( wp_unslash($_POST['series_data']), true );
			if ( is_array($series_data) ) {
				foreach ( $series_data as $s ) {
					$title = sanitize_text_field( $s['title'] );
					if ( empty($title) ) continue;

					$status = in_array( $s['status'], array('start','in_progress','completed') ) ? $s['status'] : 'start';
					$rating = ( isset($s['rating']) && $s['rating'] !== '' ) ? intval($s['rating']) : null;
					$series_id = ! empty($s['id']) ? intval($s['id']) : 0;

					if ( $series_id > 0 ) {
						$old = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM $this->table_series WHERE id = %d", $series_id ) );
						$update = array( 'title' => $title, 'status' => $status, 'rating' => $rating );
						if ( $status === 'completed' && $old && $old->status !== 'completed' ) {
							$update['completion_date'] = $event_date;
						}
						$wpdb->update( $this->table_series, $update, array( 'id' => $series_id ) );
					} else {
						$wpdb->insert( $this->table_series, array(
							'title' => $title,
							'status' => $status,
							'rating' => $rating,
							'start_date' => $event_date,
							'completion_date' => ( $status === 'completed' ) ? $event_date : null
						));
					}
				}
			}
		}

		// Process deleted series
		if ( isset($_POST['deleted_series']) ) {
			$deleted = json_decode( wp_unslash($_POST['deleted_series']), true );
			if ( is_array($deleted) ) {
				foreach ( $deleted as $id ) {
					$wpdb->delete( $this->table_series, array( 'id' => intval($id) ) );
				}
			}
		}

		// The rescued draft for this day has now been saved for real.
		$draft = get_user_meta( get_current_user_id(), self::META_DRAFT, true );
		if ( is_array( $draft ) && isset( $draft['date'] ) && $draft['date'] === $event_date ) {
			delete_user_meta( get_current_user_id(), self::META_DRAFT );
		}

		wp_send_json_success( 'Saved successfully!' );
	}

	public function ajax_add_holiday() {
		$this->require_unlocked();
		global $wpdb;

		$title = sanitize_text_field( wp_unslash($_POST['title']) );
		$image = esc_url_raw( $_POST['image'] );
		$start = sanitize_text_field( $_POST['start'] );
		$end   = sanitize_text_field( $_POST['end'] );

		if(empty($title) || empty($start) || empty($end)) wp_send_json_error('Missing fields');

		$wpdb->insert( $this->table_holidays, array('title'=>$title, 'image_url'=>$image, 'start_date'=>$start, 'end_date'=>$end) );
		wp_send_json_success( 'Holiday Added' );
	}

	public function ajax_delete_holiday() {
		$this->require_unlocked();
		global $wpdb;
		$id = intval( $_POST['id'] );
		$wpdb->delete( $this->table_holidays, array('id'=>$id) );
		wp_send_json_success( 'Deleted' );
	}

	public function ajax_download_report() {
		$this->require_unlocked();

		global $wpdb;
		$month = sanitize_text_field( $_POST['month'] );

		if(empty($month)) wp_send_json_error( 'No month selected' );

		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $this->table_content WHERE event_date LIKE %s ORDER BY event_date ASC", $month . '%' ),
			ARRAY_A
		);

		// Also get books/films/series for the month range
		$month_start = $month . '-01';
		$month_end = date( 'Y-m-t', strtotime( $month_start ) );

		$month_books = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $this->table_books WHERE start_date <= %s AND (completion_date IS NULL OR completion_date >= %s)",
			$month_end, $month_start
		), ARRAY_A );

		$month_films = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $this->table_films WHERE event_date >= %s AND event_date <= %s ORDER BY event_date, id",
			$month_start, $month_end
		), ARRAY_A );

		$month_series = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $this->table_series WHERE start_date <= %s AND (completion_date IS NULL OR completion_date >= %s)",
			$month_end, $month_start
		), ARRAY_A );

		// Build per-date lookups
		$films_by_date = array();
		if ( $month_films ) {
			foreach ( $month_films as $f ) {
				$films_by_date[ $f['event_date'] ][] = $f;
			}
		}

		if( empty($results) && empty($month_films) && empty($month_books) && empty($month_series) ) {
			wp_send_json_error( 'No data found for this month' );
		}

		// Collect all dates in the month that have any data
		$all_dates = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				$all_dates[ $row['event_date'] ] = $row;
			}
		}

		// Add dates from films that don't have content rows
		foreach ( array_keys( $films_by_date ) as $d ) {
			if ( ! isset( $all_dates[ $d ] ) ) {
				$all_dates[ $d ] = null;
			}
		}
		ksort( $all_dates );

		$header = [
			'Date', 'YKW', 'Sport', 'Shower', 'Reading', 'Weight',
			'Highlights', 'Daily Text', 'Talking Head', 'Podcasts',
			'Books', 'Films', 'Series', 'Lessons', 'Posts'
		];

		ob_clean();
		$fp = fopen( 'php://temp', 'r+' );

		fputs( $fp, "\xEF\xBB\xBF" );
		fputcsv( $fp, $header );

		foreach( $all_dates as $date => $row ) {
			// Books active on this date
			$books_str = '';
			if ( $month_books ) {
				$date_books = array();
				foreach ( $month_books as $b ) {
					if ( $b['start_date'] <= $date && ( $b['completion_date'] === null || $b['completion_date'] >= $date ) ) {
						$st = 'In Progress';
						if ( $b['start_date'] === $date ) $st = 'Started';
						if ( $b['completion_date'] === $date ) $st = 'Completed';
						$entry = $b['title'] . ' (' . $st;
						if ( $b['completion_date'] === $date && $b['rating'] !== null ) {
							$entry .= ', ' . $b['rating'] . '/6';
						}
						$entry .= ')';
						$date_books[] = $entry;
					}
				}
				$books_str = implode( '; ', $date_books );
			}

			// Films for this date
			$films_str = '';
			if ( isset( $films_by_date[ $date ] ) ) {
				$film_entries = array();
				foreach ( $films_by_date[ $date ] as $f ) {
					$entry = $f['title'];
					if ( $f['rating'] !== null ) $entry .= ' (' . $f['rating'] . '/6)';
					$film_entries[] = $entry;
				}
				$films_str = implode( '; ', $film_entries );
			}

			// Series active on this date
			$series_str = '';
			if ( $month_series ) {
				$date_series = array();
				foreach ( $month_series as $s ) {
					if ( $s['start_date'] <= $date && ( $s['completion_date'] === null || $s['completion_date'] >= $date ) ) {
						$st = 'In Progress';
						if ( $s['start_date'] === $date ) $st = 'Started';
						if ( $s['completion_date'] === $date ) $st = 'Completed';
						$entry = $s['title'] . ' (' . $st;
						if ( $s['completion_date'] === $date && $s['rating'] !== null ) {
							$entry .= ', ' . $s['rating'] . '/6';
						}
						$entry .= ')';
						$date_series[] = $entry;
					}
				}
				$series_str = implode( '; ', $date_series );
			}

			// Legacy book/film data fallback
			if ( empty( $books_str ) && $row && ! empty( $row['books'] ) ) {
				$books_str = strip_tags( $row['books'] );
			}
			if ( empty( $films_str ) && $row && ! empty( $row['films'] ) ) {
				$films_str = strip_tags( $row['films'] );
				if ( $row['films_rating'] ) $films_str .= ' (' . $row['films_rating'] . '/6)';
			}

			$line = [
				$date,
				$row ? ($row['ykw'] ? '1' : '0') : '0',
				$row ? ($row['sport'] ? '1' : '0') : '0',
				$row ? ($row['shower'] ? '1' : '0') : '0',
				$row ? ( ! empty( $row['reading'] ) ? '1' : '0' ) : '0',
				$row ? $row['weight'] : '',
				$row ? strip_tags( $row['highlights'] ) : '',
				$row ? strip_tags( $row['daily_text'] ) : '',
				$row ? strip_tags( $row['talking_head'] ) : '',
				$row ? strip_tags( $row['podcasts'] ) : '',
				$books_str,
				$films_str,
				$series_str,
				$row ? strip_tags( $row['lessons'] ) : '',
				$row ? strip_tags( $row['posts_entries'] ) : ''
			];
			fputcsv( $fp, $line );
		}

		rewind( $fp );
		$csv_data = stream_get_contents( $fp );
		fclose( $fp );

		wp_send_json_success( $csv_data );
	}

	public function ajax_add_event() {
		$this->require_unlocked();
		global $wpdb;

		$title = sanitize_text_field( wp_unslash($_POST['title']) );
		$image = esc_url_raw( $_POST['image'] );
		$start = sanitize_text_field( $_POST['start'] );
		$end   = sanitize_text_field( $_POST['end'] );

		if(empty($title) || empty($start) || empty($end)) wp_send_json_error('Missing fields');

		$wpdb->insert( $this->table_events, array('title'=>$title, 'image_url'=>$image, 'start_date'=>$start, 'end_date'=>$end) );
		wp_send_json_success( 'Event Added' );
	}

	public function ajax_delete_event() {
		$this->require_unlocked();
		global $wpdb;
		$id = intval( $_POST['id'] );
		$wpdb->delete( $this->table_events, array('id'=>$id) );
		wp_send_json_success( 'Deleted' );
	}
}

new SimpleDailyCalendar();
