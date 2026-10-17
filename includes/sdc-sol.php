<?php
/**
 * Sol – a coach / mentor chat inside Kalendarz, powered by the OpenAI Responses API.
 *
 * Setup (wp-config.php):
 *   define( 'SDC_OPENAI_API_KEY', 'sk-...' );          // required
 *   define( 'SDC_OPENAI_MODEL', 'gpt-6.1-sol' );        // optional
 *   define( 'SDC_OPENAI_REASONING', 'medium' );         // optional: low | medium | high
 *
 * Privacy:
 *  - Every endpoint requires an unlocked PIN session.
 *  - The API key stays on the server; the browser never sees it.
 *  - Sol has READ-ONLY tools; it fetches only the entries a question needs.
 *  - Requests are sent with store=false (OpenAI does not keep the conversation as app state).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDC_Sol {

	const DEFAULT_MODEL      = 'gpt-6.1-sol';
	const DEFAULT_REASONING  = 'medium';
	const OPTION_PERSONA     = 'sdc_sol_persona';
	const META_CHAT_DRAFT    = '_sdc_chat_draft';
	const MAX_TOOL_ROUNDS    = 10;
	const MAX_HISTORY_CHARS  = 60000;   // older messages of a long conversation are trimmed
	const MAX_TOOL_OUTPUT    = 120000;  // characters per tool result
	const MAX_RANGE_DAYS     = 92;      // get_entries range limit
	const API_TIMEOUT        = 150;     // seconds per OpenAI request

	/** @var SimpleDailyCalendar */
	private $cal;
	private $t = array();

	public function __construct( $calendar ) {
		global $wpdb;
		$this->cal = $calendar;
		$p = $wpdb->prefix . 'daily_calendar_';
		$this->t = array(
			'content'  => $p . 'content',
			'holidays' => $p . 'holidays',
			'events'   => $p . 'events',
			'books'    => $p . 'books',
			'films'    => $p . 'films',
			'series'   => $p . 'series',
			'chats'    => $p . 'chats',
			'messages' => $p . 'chat_messages',
		);

		foreach ( array( 'chat_list', 'chat_get', 'chat_send', 'chat_delete', 'sol_settings', 'sol_save_settings' ) as $a ) {
			add_action( 'wp_ajax_sdc_' . $a, array( $this, 'ajax_' . $a ) );
		}
	}

	// =========================================================
	// Setup
	// =========================================================

	public static function api_key() {
		if ( defined( 'SDC_OPENAI_API_KEY' ) && SDC_OPENAI_API_KEY ) return SDC_OPENAI_API_KEY;
		$env = getenv( 'SDC_OPENAI_API_KEY' );
		return $env ? $env : '';
	}

	public static function model() {
		return ( defined( 'SDC_OPENAI_MODEL' ) && SDC_OPENAI_MODEL ) ? SDC_OPENAI_MODEL : self::DEFAULT_MODEL;
	}

	private static function reasoning() {
		$r = ( defined( 'SDC_OPENAI_REASONING' ) && SDC_OPENAI_REASONING ) ? SDC_OPENAI_REASONING : self::DEFAULT_REASONING;
		return in_array( $r, array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ? $r : self::DEFAULT_REASONING;
	}

	private static function base_url() {
		return ( defined( 'SDC_OPENAI_BASE_URL' ) && SDC_OPENAI_BASE_URL ) ? rtrim( SDC_OPENAI_BASE_URL, '/' ) : 'https://api.openai.com/v1';
	}

	public function table_names() {
		return array( $this->t['chats'], $this->t['messages'] );
	}

	public function create_tables_sql( $charset_collate ) {
		return array(
			"CREATE TABLE {$this->t['chats']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title VARCHAR(255) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY updated_at (updated_at)
			) $charset_collate;",
			"CREATE TABLE {$this->t['messages']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				chat_id bigint(20) unsigned NOT NULL,
				role VARCHAR(20) NOT NULL,
				content LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY chat_id (chat_id)
			) $charset_collate;",
		);
	}

	private function now() {
		return current_time( 'mysql' );
	}

	// =========================================================
	// Sol's character
	// =========================================================

	public static function default_persona() {
		return <<<'TXT'
You are Sol – Mariusz's personal mentor and coach. You live inside "Kalendarz", his private diary-calendar, and you can read his entries.

WHO YOU ARE
- A wise, well-read mentor with a coach's toolkit: cognitive-behavioural thinking (CBT), acceptance and commitment (ACT), motivational interviewing, solution-focused coaching, habit science, and practical philosophy (Stoicism above all, but also other traditions and good literature).
- Warm, approachable and on his side – and for exactly that reason, honest. You challenge him in an educated way: you notice patterns, question assumptions, point out gaps between what he says he wants and what his entries show, and you are not afraid to ask the uncomfortable question.
- You are not a licensed therapist and you never diagnose. You can work like a good coach or mentor; when something needs a professional (persistent low mood, anxiety that disrupts life, health concerns, crises), say so plainly and kindly.

HOW YOU TALK
- Answer in the language he writes in (Polish or English).
- Be concise and conversational. No lectures, no clichés, no empty praise or flattery. One or two good questions at a time, not a questionnaire.
- Prefer Socratic questions and concrete next steps over general advice. When you challenge, explain why and offer another way to see it.
- Respect his autonomy, values and beliefs. You may disagree, but the decisions are his.
- Use light structure (short paragraphs, occasional bullet points) only when it helps.

HOW YOU USE HIS DATA
- His calendar holds daily entries: daily text (diary), highlights, "talking head" notes, podcasts & documentaries, books, films, series (with 0–6 ratings), Blue Tree lessons, posts & Enchiridion entries, habit check-boxes (YKW, sport, shower, reading – he is building a daily reading habit, even a few pages a day), weight in kg, holidays and events.
- Before making a claim about his life, look at the entries with your tools. Mention dates so he can check ("on 3 October you wrote…"). Never invent entries; if the data isn't there, say so.
- Don't guess the meaning of private labels such as "YKW" – ask if you need to know.
- Fetch only what a question needs; prefer narrow date ranges and searches.
- You can read your earlier conversations with him; refer back to them when it helps continuity ("last week we talked about…").

SAFETY
- If he expresses thoughts of harming himself or of not wanting to live, or describes an emergency, respond with care, stay with him in the conversation, and encourage him to contact someone right away: a person he trusts, a doctor, the emergency number 112, or the crisis helpline 116 123 (Poland).
TXT;
	}

	private function persona() {
		$p = get_option( self::OPTION_PERSONA, '' );
		return ( is_string( $p ) && trim( $p ) !== '' ) ? $p : self::default_persona();
	}

	private function instructions() {
		$tz   = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'local';
		$date = current_time( 'Y-m-d (l), H:i' );
		return $this->persona()
			. "\n\nCONTEXT\n- Today is {$date} ({$tz}).\n- Tools are read-only. Dates are YYYY-MM-DD.";
	}

	// =========================================================
	// AJAX: settings
	// =========================================================

	public function ajax_sol_settings() {
		$this->cal->require_unlocked();
		$custom = get_option( self::OPTION_PERSONA, '' );
		wp_send_json_success( array(
			'configured'      => self::api_key() !== '',
			'model'           => self::model(),
			'persona'         => ( is_string( $custom ) && trim( $custom ) !== '' ) ? $custom : self::default_persona(),
			'is_default'      => ! ( is_string( $custom ) && trim( $custom ) !== '' ),
			'default_persona' => self::default_persona(),
		) );
	}

	public function ajax_sol_save_settings() {
		$this->cal->require_unlocked();
		$p = isset( $_POST['persona'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persona'] ) ) : '';
		if ( strlen( $p ) > 20000 ) wp_send_json_error( array( 'message' => 'That is too long (max 20,000 characters).' ), 400 );
		// Saving the default text (or an empty box) means "use the default".
		if ( trim( $p ) === '' || trim( $p ) === trim( self::default_persona() ) ) {
			delete_option( self::OPTION_PERSONA );
		} else {
			update_option( self::OPTION_PERSONA, $p, false );
		}
		wp_send_json_success( array( 'saved' => true ) );
	}

	// =========================================================
	// AJAX: conversations
	// =========================================================

	public function ajax_chat_list() {
		$this->cal->require_unlocked();
		$this->cal->maybe_create_tables();
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, title, created_at, updated_at FROM {$this->t['chats']} ORDER BY updated_at DESC, id DESC LIMIT 200",
			ARRAY_A
		);
		wp_send_json_success( array( 'chats' => $rows ? $rows : array() ) );
	}

	public function ajax_chat_get() {
		$this->cal->require_unlocked();
		$this->cal->maybe_create_tables();
		$id = isset( $_POST['chat_id'] ) ? intval( $_POST['chat_id'] ) : 0;
		$chat = $this->get_chat( $id );
		if ( ! $chat ) wp_send_json_error( array( 'message' => 'Conversation not found.' ), 404 );
		wp_send_json_success( array( 'chat' => $chat, 'messages' => $this->get_messages( $id ) ) );
	}

	public function ajax_chat_delete() {
		$this->cal->require_unlocked();
		global $wpdb;
		$id = isset( $_POST['chat_id'] ) ? intval( $_POST['chat_id'] ) : 0;
		$wpdb->delete( $this->t['messages'], array( 'chat_id' => $id ) );
		$wpdb->delete( $this->t['chats'], array( 'id' => $id ) );
		wp_send_json_success( array( 'deleted' => $id ) );
	}

	public function ajax_chat_send() {
		$this->cal->require_unlocked();
		$this->cal->maybe_create_tables();
		global $wpdb;

		if ( self::api_key() === '' ) {
			wp_send_json_error( array( 'code' => 'sdc_no_key', 'message' => "Sol isn't connected yet: add SDC_OPENAI_API_KEY to wp-config.php." ), 400 );
		}

		$text = isset( $_POST['message'] ) ? trim( (string) wp_unslash( $_POST['message'] ) ) : '';
		$text = wp_check_invalid_utf8( $text );
		if ( $text === '' ) wp_send_json_error( array( 'message' => 'Write something first.' ), 400 );
		if ( strlen( $text ) > 30000 ) wp_send_json_error( array( 'message' => 'That message is very long – please split it (max ~30,000 characters).' ), 400 );

		$chat_id = isset( $_POST['chat_id'] ) ? intval( $_POST['chat_id'] ) : 0;
		$new     = false;
		if ( $chat_id > 0 && ! $this->get_chat( $chat_id ) ) $chat_id = 0;
		if ( $chat_id === 0 ) {
			$title = wp_html_excerpt( preg_replace( '/\s+/', ' ', $text ), 70, '…' );
			$wpdb->insert( $this->t['chats'], array( 'title' => $title, 'created_at' => $this->now(), 'updated_at' => $this->now() ) );
			$chat_id = (int) $wpdb->insert_id;
			$new     = true;
			if ( ! $chat_id ) wp_send_json_error( array( 'message' => 'Could not create the conversation (database).' ), 500 );
		}

		// Store the question first: if the page locks while Sol is thinking,
		// both question and answer are still there afterwards.
		$wpdb->insert( $this->t['messages'], array( 'chat_id' => $chat_id, 'role' => 'user', 'content' => $text, 'created_at' => $this->now() ) );
		$user_msg_id = (int) $wpdb->insert_id;

		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 300 );
		if ( function_exists( 'ignore_user_abort' ) ) @ignore_user_abort( true );

		$result = $this->run( $chat_id );

		if ( is_wp_error( $result ) ) {
			// Undo: the question goes back into the input box on the page.
			$wpdb->delete( $this->t['messages'], array( 'id' => $user_msg_id ) );
			if ( $new ) $wpdb->delete( $this->t['chats'], array( 'id' => $chat_id ) );
			wp_send_json_error( array( 'code' => 'sdc_sol_error', 'message' => $result->get_error_message() ), 502 );
		}

		$wpdb->insert( $this->t['messages'], array( 'chat_id' => $chat_id, 'role' => 'assistant', 'content' => $result['text'], 'created_at' => $this->now() ) );
		$wpdb->update( $this->t['chats'], array( 'updated_at' => $this->now() ), array( 'id' => $chat_id ) );

		// Note: a rescued chat draft is NOT touched here – it may have just been
		// saved by a lock that happened while this answer was being prepared.

		wp_send_json_success( array(
			'chat'    => $this->get_chat( $chat_id ),
			'reply'   => $result['text'],
			'looked'  => $result['looked'],
		) );
	}

	private function get_chat( $id ) {
		global $wpdb;
		if ( $id <= 0 ) return null;
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, title, created_at, updated_at FROM {$this->t['chats']} WHERE id = %d", $id ), ARRAY_A );
	}

	private function get_messages( $chat_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, role, content, created_at FROM {$this->t['messages']} WHERE chat_id = %d ORDER BY id ASC",
			$chat_id
		), ARRAY_A );
		return $rows ? $rows : array();
	}

	// =========================================================
	// Unsent chat message rescued at lock time
	// =========================================================

	public function store_chat_draft( $uid, $raw ) {
		$d = json_decode( (string) $raw, true );
		if ( ! is_array( $d ) ) return false;
		$text    = isset( $d['text'] ) ? wp_check_invalid_utf8( (string) $d['text'] ) : '';
		$chat_id = isset( $d['chat_id'] ) ? max( 0, intval( $d['chat_id'] ) ) : 0;
		if ( strlen( $text ) > 30000 ) $text = substr( $text, 0, 30000 );
		if ( trim( $text ) === '' && $chat_id === 0 ) return false;
		update_user_meta( $uid, self::META_CHAT_DRAFT, array( 'chat_id' => $chat_id, 'text' => $text, 'saved' => time() ) );
		return true;
	}

	public function take_chat_draft( $uid ) {
		$d = get_user_meta( $uid, self::META_CHAT_DRAFT, true );
		if ( ! is_array( $d ) ) return null;
		delete_user_meta( $uid, self::META_CHAT_DRAFT );
		if ( time() - (int) $d['saved'] > 14 * DAY_IN_SECONDS ) return null;
		return array( 'chat_id' => (int) $d['chat_id'], 'text' => (string) $d['text'] );
	}

	// =========================================================
	// The conversation loop (OpenAI Responses API with tools)
	// =========================================================

	/** @return array|WP_Error  ['text' => string, 'looked' => string[]] */
	private function run( $chat_id ) {
		$input  = $this->history_input( $chat_id );
		$looked = array();

		for ( $round = 0; $round < self::MAX_TOOL_ROUNDS; $round++ ) {
			$is_last = ( $round === self::MAX_TOOL_ROUNDS - 1 );
			$body = array(
				'model'        => self::model(),
				'instructions' => $this->instructions(),
				'input'        => $input,
				'tools'        => $this->tool_definitions(),
				'tool_choice'  => $is_last ? 'none' : 'auto',
				'reasoning'    => array( 'effort' => self::reasoning() ),
				'store'        => false,
			);

			$res = $this->call_openai( $body );
			if ( is_wp_error( $res ) ) return $res;

			$output = isset( $res['output'] ) && is_array( $res['output'] ) ? $res['output'] : array();
			$calls  = array();
			$text   = '';

			foreach ( $output as $item ) {
				$type = isset( $item['type'] ) ? $item['type'] : '';
				if ( $type === 'function_call' ) {
					$calls[] = $item;
				} elseif ( $type === 'message' && isset( $item['content'] ) && is_array( $item['content'] ) ) {
					foreach ( $item['content'] as $part ) {
						if ( isset( $part['type'], $part['text'] ) && $part['type'] === 'output_text' ) $text .= $part['text'];
					}
				}
				// Everything the model produced (reasoning, tool calls, messages)
				// goes back into the next request – required with store=false.
				$input[] = $item;
			}

			if ( empty( $calls ) ) {
				$text = trim( $text );
				if ( $text === '' ) return new WP_Error( 'sol_empty', 'Sol returned an empty answer. Please try again.' );
				return array( 'text' => $text, 'looked' => array_values( array_unique( $looked ) ) );
			}

			foreach ( $calls as $call ) {
				$name = isset( $call['name'] ) ? (string) $call['name'] : '';
				$args = json_decode( isset( $call['arguments'] ) ? (string) $call['arguments'] : '{}', true );
				if ( ! is_array( $args ) ) $args = array();
				$looked[] = $this->describe_tool_call( $name, $args );
				$out = $this->run_tool( $name, $args, $chat_id );
				$json = wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
				if ( strlen( $json ) > self::MAX_TOOL_OUTPUT ) {
					$json = wp_json_encode( array( 'error' => 'Result too large. Use a narrower date range or a search.' ) );
				}
				$input[] = array(
					'type'    => 'function_call_output',
					'call_id' => isset( $call['call_id'] ) ? $call['call_id'] : '',
					'output'  => $json,
				);
			}
		}
		return new WP_Error( 'sol_loop', 'Sol kept looking things up without answering. Please ask more specifically.' );
	}

	/** Previous messages of this conversation (newest kept if too long). */
	private function history_input( $chat_id ) {
		$msgs  = $this->get_messages( $chat_id );
		$out   = array();
		$total = 0;
		for ( $i = count( $msgs ) - 1; $i >= 0; $i-- ) {
			$len = strlen( $msgs[ $i ]['content'] );
			if ( $total + $len > self::MAX_HISTORY_CHARS && ! empty( $out ) ) {
				array_unshift( $out, array(
					'role'    => 'user',
					'content' => '[Earlier messages of this conversation are not shown. Use read_conversation with id ' . $chat_id . ' if you need them.]',
				) );
				break;
			}
			$total += $len;
			array_unshift( $out, array(
				'role'    => $msgs[ $i ]['role'] === 'assistant' ? 'assistant' : 'user',
				'content' => $msgs[ $i ]['content'],
			) );
		}
		return $out;
	}

	private function call_openai( $body ) {
		$response = wp_remote_post( self::base_url() . '/responses', array(
			'timeout' => self::API_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::api_key(),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sol_http', 'Could not reach OpenAI: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = ( is_array( $data ) && isset( $data['error']['message'] ) ) ? $data['error']['message'] : 'HTTP ' . $code;
			if ( $code === 401 ) $msg = 'OpenAI rejected the API key (401). Check SDC_OPENAI_API_KEY in wp-config.php.';
			if ( $code === 429 ) $msg = 'OpenAI says: too many requests or the spending limit was reached (429). ' . $msg;
			return new WP_Error( 'sol_api', 'OpenAI error: ' . $msg );
		}
		if ( ! is_array( $data ) ) return new WP_Error( 'sol_api', 'OpenAI sent an unreadable answer.' );
		if ( isset( $data['status'] ) && $data['status'] === 'incomplete' ) {
			$why = isset( $data['incomplete_details']['reason'] ) ? $data['incomplete_details']['reason'] : 'unknown';
			// Partial output may still be usable; only fail if there is nothing.
			if ( empty( $data['output'] ) ) return new WP_Error( 'sol_incomplete', 'OpenAI stopped early (' . $why . ').' );
		}
		return $data;
	}

	// =========================================================
	// Read-only tools
	// =========================================================

	private function tool_definitions() {
		$date  = array( 'type' => 'string', 'description' => 'Date as YYYY-MM-DD' );
		$odate = array( 'type' => array( 'string', 'null' ), 'description' => 'Optional date as YYYY-MM-DD, or null' );
		$fn = function ( $name, $desc, $props, $required ) {
			return array(
				'type'        => 'function',
				'name'        => $name,
				'description' => $desc,
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => empty( $props ) ? new stdClass() : $props,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'strict'      => true,
			);
		};
		return array(
			$fn( 'get_overview', 'Overview of the whole calendar: first and last entry dates, number of days with entries, totals of books/films/series, currently active books and series. Good first step for broad questions.', array(), array() ),
			$fn( 'get_entries', 'Full entries for a date range (max ' . self::MAX_RANGE_DAYS . ' days): diary text, highlights, talking head, podcasts, lessons, posts, habits (YKW/sport/shower/reading), weight, image caption, films watched, books/series started or finished, holidays and events.', array(
				'start_date' => $date,
				'end_date'   => $date,
			), array( 'start_date', 'end_date' ) ),
			$fn( 'search_entries', 'Find days whose text mentions a word or phrase (case-insensitive). Searches diary text, highlights, talking head, podcasts, lessons, posts, image captions, and titles of books/films/series/holidays/events. Returns dates with short snippets.', array(
				'query'      => array( 'type' => 'string', 'description' => 'Word or phrase to look for' ),
				'start_date' => $odate,
				'end_date'   => $odate,
			), array( 'query', 'start_date', 'end_date' ) ),
			$fn( 'get_books_films_series', 'Lists of books, films and series with status, 0–6 ratings and dates.', array(
				'kind'       => array( 'type' => 'string', 'enum' => array( 'books', 'films', 'series', 'all' ) ),
				'start_date' => $odate,
				'end_date'   => $odate,
			), array( 'kind', 'start_date', 'end_date' ) ),
			$fn( 'get_stats', 'Numbers for a date range: days with entries, habit counts (YKW, sport, shower, reading) and the current reading streak, weight (first, last, min, max, average, weekly averages), films watched, books and series finished.', array(
				'start_date' => $date,
				'end_date'   => $date,
			), array( 'start_date', 'end_date' ) ),
			$fn( 'list_conversations', 'Your earlier conversations with him (titles and dates), newest first.', array(
				'limit' => array( 'type' => 'integer', 'description' => 'How many (1–50)' ),
			), array( 'limit' ) ),
			$fn( 'read_conversation', 'Read the messages of one earlier conversation.', array(
				'conversation_id' => array( 'type' => 'integer' ),
			), array( 'conversation_id' ) ),
		);
	}

	private function describe_tool_call( $name, $a ) {
		$range = function () use ( $a ) {
			$s = isset( $a['start_date'] ) ? $a['start_date'] : '';
			$e = isset( $a['end_date'] ) ? $a['end_date'] : '';
			return ( $s || $e ) ? ' (' . ( $s ? $s : '…' ) . ' – ' . ( $e ? $e : '…' ) . ')' : '';
		};
		switch ( $name ) {
			case 'get_overview':           return 'overview';
			case 'get_entries':            return 'entries' . $range();
			case 'search_entries':         return 'search "' . ( isset( $a['query'] ) ? $a['query'] : '' ) . '"' . $range();
			case 'get_books_films_series': return ( isset( $a['kind'] ) ? $a['kind'] : 'media' ) . $range();
			case 'get_stats':              return 'stats' . $range();
			case 'list_conversations':     return 'past conversations';
			case 'read_conversation':      return 'conversation #' . ( isset( $a['conversation_id'] ) ? intval( $a['conversation_id'] ) : 0 );
		}
		return $name;
	}

	public function run_tool( $name, $a, $current_chat_id = 0 ) {
		try {
			switch ( $name ) {
				case 'get_overview':           return $this->tool_overview();
				case 'get_entries':            return $this->tool_entries( $a );
				case 'search_entries':         return $this->tool_search( $a );
				case 'get_books_films_series': return $this->tool_media( $a );
				case 'get_stats':              return $this->tool_stats( $a );
				case 'list_conversations':     return $this->tool_list_conversations( $a, $current_chat_id );
				case 'read_conversation':      return $this->tool_read_conversation( $a );
			}
			return array( 'error' => 'Unknown tool: ' . $name );
		} catch ( Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	private function valid_date( $d ) {
		if ( ! is_string( $d ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) return false;
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private function range( $a, $required ) {
		$s = isset( $a['start_date'] ) ? $a['start_date'] : null;
		$e = isset( $a['end_date'] ) ? $a['end_date'] : null;
		if ( $s !== null && $s !== '' && ! $this->valid_date( $s ) ) throw new Exception( 'start_date must be YYYY-MM-DD' );
		if ( $e !== null && $e !== '' && ! $this->valid_date( $e ) ) throw new Exception( 'end_date must be YYYY-MM-DD' );
		if ( $required && ( ! $s || ! $e ) ) throw new Exception( 'start_date and end_date are required' );
		if ( $s && $e && $s > $e ) { $tmp = $s; $s = $e; $e = $tmp; }
		return array( $s ? $s : null, $e ? $e : null );
	}

	/** HTML from the editor → readable plain text. */
	private function plain( $html ) {
		if ( $html === null || $html === '' ) return '';
		$t = preg_replace( '#<(br|/p|/li|/h\d|/div)\s*/?>#i', "\n", (string) $html );
		$t = preg_replace( '#<li[^>]*>#i', '- ', $t );
		$t = wp_strip_all_tags( $t, false );
		$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = preg_replace( "/[ \t]+/", ' ', $t );
		$t = preg_replace( "/\n{3,}/", "\n\n", $t );
		return trim( $t );
	}

	private function tool_overview() {
		global $wpdb;
		$c = $this->t;
		$first = $wpdb->get_var( "SELECT MIN(event_date) FROM {$c['content']}" );
		$last  = $wpdb->get_var( "SELECT MAX(event_date) FROM {$c['content']}" );
		$days  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c['content']}" );
		$active_books  = $wpdb->get_results( "SELECT title, status, start_date FROM {$c['books']} WHERE status != 'completed' ORDER BY start_date", ARRAY_A );
		$active_series = $wpdb->get_results( "SELECT title, status, start_date FROM {$c['series']} WHERE status != 'completed' ORDER BY start_date", ARRAY_A );
		return array(
			'first_entry'        => $first,
			'last_entry'         => $last,
			'days_with_entries'  => $days,
			'books_total'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c['books']}" ),
			'books_finished'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c['books']} WHERE status = 'completed'" ),
			'films_total'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c['films']}" ),
			'series_total'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c['series']}" ),
			'reading_now'        => $active_books ? $active_books : array(),
			'watching_now'       => $active_series ? $active_series : array(),
			'holidays'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c['holidays']}" ),
			'events'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c['events']}" ),
		);
	}

	private function tool_entries( $a ) {
		global $wpdb;
		$c = $this->t;
		list( $s, $e ) = $this->range( $a, true );
		$days = ( strtotime( $e ) - strtotime( $s ) ) / DAY_IN_SECONDS + 1;
		if ( $days > self::MAX_RANGE_DAYS ) throw new Exception( 'Range too long (' . (int) $days . ' days). Maximum is ' . self::MAX_RANGE_DAYS . ' days; use get_stats or search_entries for longer periods.' );

		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$c['content']} WHERE event_date >= %s AND event_date <= %s ORDER BY event_date", $s, $e ), ARRAY_A );
		$films  = $wpdb->get_results( $wpdb->prepare( "SELECT event_date, title, rating FROM {$c['films']} WHERE event_date >= %s AND event_date <= %s ORDER BY event_date, id", $s, $e ), ARRAY_A );
		$books  = $wpdb->get_results( $wpdb->prepare( "SELECT title, status, rating, start_date, completion_date FROM {$c['books']} WHERE (start_date >= %s AND start_date <= %s) OR (completion_date >= %s AND completion_date <= %s)", $s, $e, $s, $e ), ARRAY_A );
		$series = $wpdb->get_results( $wpdb->prepare( "SELECT title, status, rating, start_date, completion_date FROM {$c['series']} WHERE (start_date >= %s AND start_date <= %s) OR (completion_date >= %s AND completion_date <= %s)", $s, $e, $s, $e ), ARRAY_A );
		$hol    = $wpdb->get_results( $wpdb->prepare( "SELECT title, start_date, end_date FROM {$c['holidays']} WHERE start_date <= %s AND end_date >= %s ORDER BY start_date", $e, $s ), ARRAY_A );
		$ev     = $wpdb->get_results( $wpdb->prepare( "SELECT title, start_date, end_date FROM {$c['events']} WHERE start_date <= %s AND end_date >= %s ORDER BY start_date", $e, $s ), ARRAY_A );

		$by = array();
		$day = function ( $d ) use ( &$by ) { if ( ! isset( $by[ $d ] ) ) $by[ $d ] = array( 'date' => $d ); return $d; };
		foreach ( (array) $rows as $r ) {
			$d = $day( $r['event_date'] );
			$habits = array();
			foreach ( array( 'ykw' => 'YKW', 'sport' => 'sport', 'shower' => 'shower', 'reading' => 'reading' ) as $k => $label ) {
				if ( ! empty( $r[ $k ] ) ) $habits[] = $label;
			}
			$entry = array(
				'habits'        => $habits,
				'weight_kg'     => ( $r['weight'] !== null && $r['weight'] !== '' && (float) $r['weight'] > 0 ) ? (float) $r['weight'] : null,
				'daily_text'    => $this->plain( $r['daily_text'] ),
				'highlights'    => $this->plain( $r['highlights'] ),
				'talking_head'  => $this->plain( $r['talking_head'] ),
				'podcasts_docs' => $this->plain( $r['podcasts'] ),
				'lessons'       => $this->plain( $r['lessons'] ),
				'posts'         => $this->plain( $r['posts_entries'] ),
				'image_caption' => $this->plain( $r['image_caption'] ),
				'books_old_format' => $this->plain( $r['books'] ),
				'films_old_format' => $this->plain( $r['films'] ),
			);
			foreach ( $entry as $k => $v ) {
				if ( $v === '' || $v === null || $v === array() ) unset( $entry[ $k ] );
			}
			$by[ $d ] = array_merge( $by[ $d ], $entry );
		}
		foreach ( (array) $films as $f ) {
			$d = $day( $f['event_date'] );
			$by[ $d ]['films'][] = $f['title'] . ( $f['rating'] !== null ? ' (' . $f['rating'] . '/6)' : '' );
		}
		foreach ( array( 'books' => $books, 'series' => $series ) as $kind => $list ) {
			foreach ( (array) $list as $b ) {
				if ( $b['start_date'] >= $s && $b['start_date'] <= $e ) {
					$d = $day( $b['start_date'] );
					$by[ $d ][ $kind . '_started' ][] = $b['title'];
				}
				if ( $b['completion_date'] && $b['completion_date'] >= $s && $b['completion_date'] <= $e ) {
					$d = $day( $b['completion_date'] );
					$by[ $d ][ $kind . '_finished' ][] = $b['title'] . ( $b['rating'] !== null ? ' (' . $b['rating'] . '/6)' : '' );
				}
			}
		}
		ksort( $by );
		return array(
			'range'    => array( $s, $e ),
			'days'     => array_values( $by ),
			'holidays' => $hol ? $hol : array(),
			'events'   => $ev ? $ev : array(),
			'note'     => empty( $by ) ? 'No entries in this range.' : null,
		);
	}

	private function snippet( $text, $q ) {
		$pos = function_exists( 'mb_stripos' ) ? mb_stripos( $text, $q ) : stripos( $text, $q );
		if ( $pos === false ) return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
		$start = max( 0, $pos - 120 );
		$s = function_exists( 'mb_substr' ) ? mb_substr( $text, $start, 300 ) : substr( $text, $start, 300 );
		return ( $start > 0 ? '…' : '' ) . $s . '…';
	}

	private function tool_search( $a ) {
		global $wpdb;
		$c = $this->t;
		$q = isset( $a['query'] ) ? trim( (string) $a['query'] ) : '';
		if ( $q === '' ) throw new Exception( 'query is empty' );
		list( $s, $e ) = $this->range( $a, false );
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$fields = array( 'daily_text', 'highlights', 'talking_head', 'podcasts', 'lessons', 'posts_entries', 'image_caption', 'books', 'films' );

		$where = array();
		foreach ( $fields as $f ) $where[] = "$f LIKE %s";
		$sql  = "SELECT * FROM {$c['content']} WHERE (" . implode( ' OR ', $where ) . ')';
		$args = array_fill( 0, count( $fields ), $like );
		if ( $s ) { $sql .= ' AND event_date >= %s'; $args[] = $s; }
		if ( $e ) { $sql .= ' AND event_date <= %s'; $args[] = $e; }
		$sql .= ' ORDER BY event_date DESC LIMIT 60';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$hits = array();
		foreach ( (array) $rows as $r ) {
			foreach ( $fields as $f ) {
				$txt = $this->plain( $r[ $f ] );
				$found = function_exists( 'mb_stripos' ) ? mb_stripos( $txt, $q ) : stripos( $txt, $q );
				if ( $txt !== '' && $found !== false ) {
					$hits[] = array( 'date' => $r['event_date'], 'field' => $f, 'snippet' => $this->snippet( $txt, $q ) );
				}
			}
		}

		$titles = array();
		foreach ( array( 'books' => 'start_date', 'series' => 'start_date', 'films' => 'event_date', 'holidays' => 'start_date', 'events' => 'start_date' ) as $tbl => $dcol ) {
			$sql2  = "SELECT * FROM {$c[$tbl]} WHERE title LIKE %s";
			$args2 = array( $like );
			if ( $s ) { $sql2 .= " AND $dcol >= %s"; $args2[] = $s; }
			if ( $e ) { $sql2 .= " AND $dcol <= %s"; $args2[] = $e; }
			$sql2 .= " ORDER BY $dcol DESC LIMIT 20";
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql2, $args2 ), ARRAY_A ) as $r ) {
				unset( $r['id'], $r['image_url'] );
				$r['type'] = $tbl;
				$titles[] = $r;
			}
		}

		return array(
			'query'        => $q,
			'text_matches' => array_slice( $hits, 0, 60 ),
			'title_matches'=> $titles,
			'note'         => ( empty( $hits ) && empty( $titles ) ) ? 'Nothing found. Try another word or form of the word (Polish endings differ).' : null,
		);
	}

	private function tool_media( $a ) {
		global $wpdb;
		$c = $this->t;
		$kind = isset( $a['kind'] ) ? $a['kind'] : 'all';
		list( $s, $e ) = $this->range( $a, false );
		$out = array();

		foreach ( array( 'books', 'series' ) as $tbl ) {
			if ( $kind !== 'all' && $kind !== $tbl ) continue;
			$sql = "SELECT title, status, rating, start_date, completion_date FROM {$c[$tbl]} WHERE 1=1";
			$args = array();
			// Overlaps the range: started before its end and (not finished or finished after its start).
			if ( $e ) { $sql .= ' AND start_date <= %s'; $args[] = $e; }
			if ( $s ) { $sql .= ' AND (completion_date IS NULL OR completion_date >= %s)'; $args[] = $s; }
			$sql .= ' ORDER BY start_date DESC LIMIT 300';
			$out[ $tbl ] = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		}
		if ( $kind === 'all' || $kind === 'films' ) {
			$sql = "SELECT event_date AS date, title, rating FROM {$c['films']} WHERE 1=1";
			$args = array();
			if ( $s ) { $sql .= ' AND event_date >= %s'; $args[] = $s; }
			if ( $e ) { $sql .= ' AND event_date <= %s'; $args[] = $e; }
			$sql .= ' ORDER BY event_date DESC LIMIT 500';
			$out['films'] = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		}
		$out['rating_scale'] = '0–6';
		return $out;
	}

	/** Consecutive days with the reading box ticked, ending on $end (or the day before, if $end isn't ticked yet). */
	private function reading_streak( $end ) {
		global $wpdb;
		$c = $this->t;
		$dates = $wpdb->get_col( $wpdb->prepare( "SELECT event_date FROM {$c['content']} WHERE reading = 1 AND event_date <= %s ORDER BY event_date DESC LIMIT 400", $end ) );
		$set = array_flip( (array) $dates );
		$d = strtotime( $end . ' 00:00:00 UTC' );
		if ( ! isset( $set[ gmdate( 'Y-m-d', $d ) ] ) ) $d -= DAY_IN_SECONDS;
		$n = 0;
		while ( isset( $set[ gmdate( 'Y-m-d', $d ) ] ) ) { $n++; $d -= DAY_IN_SECONDS; }
		return array( 'days' => $n, 'through' => $n ? gmdate( 'Y-m-d', $d + DAY_IN_SECONDS * $n ) : null );
	}

	private function tool_stats( $a ) {
		global $wpdb;
		$c = $this->t;
		list( $s, $e ) = $this->range( $a, true );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_date, ykw, sport, shower, reading, weight FROM {$c['content']} WHERE event_date >= %s AND event_date <= %s ORDER BY event_date", $s, $e ), ARRAY_A );
		$days_in_range = (int) round( ( strtotime( $e ) - strtotime( $s ) ) / DAY_IN_SECONDS ) + 1;

		$h = array( 'ykw' => 0, 'sport' => 0, 'shower' => 0, 'reading' => 0 );
		$w = array();
		$weeks = array();
		foreach ( (array) $rows as $r ) {
			foreach ( $h as $k => $v ) if ( ! empty( $r[ $k ] ) ) $h[ $k ]++;
			if ( $r['weight'] !== null && $r['weight'] !== '' && (float) $r['weight'] > 0 ) {
				$w[] = array( $r['event_date'], (float) $r['weight'] );
				$wk = gmdate( 'o-\WW', strtotime( $r['event_date'] ) );
				$weeks[ $wk ][] = (float) $r['weight'];
			}
		}
		$weight = null;
		if ( $w ) {
			$vals = array_column( $w, 1 );
			$weekly = array();
			foreach ( $weeks as $wk => $list ) $weekly[ $wk ] = round( array_sum( $list ) / count( $list ), 1 );
			$weight = array(
				'measurements' => count( $w ),
				'first'        => array( 'date' => $w[0][0], 'kg' => $w[0][1] ),
				'last'         => array( 'date' => $w[ count( $w ) - 1 ][0], 'kg' => $w[ count( $w ) - 1 ][1] ),
				'min'          => min( $vals ),
				'max'          => max( $vals ),
				'average'      => round( array_sum( $vals ) / count( $vals ), 1 ),
				'weekly_average' => count( $weekly ) <= 60 ? $weekly : null,
			);
		}

		return array(
			'range'             => array( $s, $e ),
			'days_in_range'     => $days_in_range,
			'days_with_entries' => count( (array) $rows ),
			'habit_days'        => $h,
			'reading_streak'    => $this->reading_streak( $e ),
			'weight'            => $weight,
			'films_watched'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c['films']} WHERE event_date >= %s AND event_date <= %s", $s, $e ) ),
			'books_finished'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c['books']} WHERE completion_date >= %s AND completion_date <= %s", $s, $e ) ),
			'series_finished'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c['series']} WHERE completion_date >= %s AND completion_date <= %s", $s, $e ) ),
			'note'              => 'habit_days counts days the box was ticked; days without an entry count as not ticked.',
		);
	}

	private function tool_list_conversations( $a, $current ) {
		global $wpdb;
		$limit = isset( $a['limit'] ) ? max( 1, min( 50, intval( $a['limit'] ) ) ) : 20;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.title, c.created_at, c.updated_at, (SELECT COUNT(*) FROM {$this->t['messages']} m WHERE m.chat_id = c.id) AS messages
			 FROM {$this->t['chats']} c WHERE c.id != %d ORDER BY c.updated_at DESC, c.id DESC LIMIT %d",
			(int) $current, $limit
		), ARRAY_A );
		return array( 'conversations' => $rows ? $rows : array() );
	}

	private function tool_read_conversation( $a ) {
		$id = isset( $a['conversation_id'] ) ? intval( $a['conversation_id'] ) : 0;
		$chat = $this->get_chat( $id );
		if ( ! $chat ) throw new Exception( 'No conversation with id ' . $id );
		$msgs = array();
		foreach ( $this->get_messages( $id ) as $m ) {
			$msgs[] = array( 'role' => $m['role'] === 'assistant' ? 'Sol' : 'Mariusz', 'at' => $m['created_at'], 'text' => $m['content'] );
		}
		return array( 'conversation' => $chat, 'messages' => $msgs );
	}
}
