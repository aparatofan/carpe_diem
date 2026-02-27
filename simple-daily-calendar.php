<?php
/**
 * Plugin Name: Simple Daily Calendar with Popups
 * Description: Second Brain Tracker: Books, Films & Series Tracking (Version 5.4).
 * Version: 5.4
 * Author: AI Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class SimpleDailyCalendar {

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
		add_shortcode( 'daily_calendar', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_sdc_get_content', array( $this, 'ajax_get_content' ) );
		add_action( 'wp_ajax_sdc_save_content', array( $this, 'ajax_save_content' ) );
		add_action( 'wp_ajax_sdc_add_holiday', array( $this, 'ajax_add_holiday' ) );
		add_action( 'wp_ajax_sdc_delete_holiday', array( $this, 'ajax_delete_holiday' ) );
		add_action( 'wp_ajax_sdc_download_report', array( $this, 'ajax_download_report' ) );
		add_action( 'wp_ajax_sdc_add_event', array( $this, 'ajax_add_event' ) );
		add_action( 'wp_ajax_sdc_delete_event', array( $this, 'ajax_delete_event' ) );
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
	}

	public function maybe_create_tables() {
		global $wpdb;
		$tables = array( $this->table_events, $this->table_books, $this->table_films, $this->table_series );
		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) != $table ) {
				$this->create_tables();
				break;
			}
		}
	}

	public function enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'daily_calendar' ) ) {
			wp_enqueue_media();
			wp_enqueue_style( 'fullcalendar-css', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css', array(), '6.1.10' );
			wp_enqueue_script( 'fullcalendar-js', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js', array( 'jquery' ), '6.1.10', true );
			wp_enqueue_script( 'ckeditor-js', 'https://cdn.ckeditor.com/ckeditor5/41.2.0/classic/ckeditor.js', array(), '41.2.0', true );

			// Version 5.4
			wp_enqueue_style( 'sdc-style', plugin_dir_url( __FILE__ ) . 'assets/sdc-style.css', array(), '5.4' );
			wp_enqueue_script( 'sdc-script', plugin_dir_url( __FILE__ ) . 'assets/sdc-script.js', array( 'jquery', 'fullcalendar-js', 'ckeditor-js' ), '5.4', true );

			wp_localize_script( 'sdc-script', 'sdcVars', array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'sdc_nonce' ),
				'can_edit'   => current_user_can( 'edit_posts' ),
				'events_json' => $this->get_all_events_json()
			) );
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

			if( ! empty( $icons ) ) {
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
						'posts_entries' => !empty($row['posts_entries'])
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

	public function render_shortcode() {
		ob_start();
		?>

		<div id="sdc-filter-bar-container"></div>

		<div id="sdc-calendar"></div>

		<?php if ( current_user_can( 'edit_posts' ) ) : ?>
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

						<?php if ( current_user_can( 'edit_posts' ) ) : ?>
							<button type="button" id="sdc-btn-switch-to-edit-top" class="button button-primary">Edit</button>
						<?php endif; ?>
						<button type="button" class="button sdc-close-btn">Close</button>
					</div>
				</div>
				<div id="sdc-loading" style="display:none;">Loading data...</div>

				<?php if ( current_user_can( 'edit_posts' ) ) : ?>
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

					<?php if ( current_user_can( 'edit_posts' ) ) : ?>
					<div id="sdc-edit-mode" style="display:none;">
						<form id="sdc-content-form">
							<input type="hidden" id="sdc_date_field" name="sdc_date_field" value="">

							<div class="sdc-form-group" style="background:#f0f0f1; padding:10px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;">
								<div>
									<span style="font-weight:bold; margin-right:10px;">Habits:</span>
									<label><input type="checkbox" id="sdc_ykw" name="sdc_ykw" value="1"> 🤫 YKW</label> &nbsp;
									<label><input type="checkbox" id="sdc_sport" name="sdc_sport" value="1"> 🚴 SPORT</label> &nbsp;
									<label><input type="checkbox" id="sdc_shower" name="sdc_shower" value="1"> 🚿 SHOWER</label>
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

				<?php if ( current_user_can( 'edit_posts' ) ) : ?>
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

				<?php if ( current_user_can( 'edit_posts' ) ) : ?>
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
		<?php
		return ob_get_clean();
	}

	// --- AJAX HANDLERS ---

	public function ajax_get_content() {
		check_ajax_referer( 'sdc_nonce', 'security' );
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
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );
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

		wp_send_json_success( 'Saved successfully!' );
	}

	public function ajax_add_holiday() {
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );
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
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );
		global $wpdb;
		$id = intval( $_POST['id'] );
		$wpdb->delete( $this->table_holidays, array('id'=>$id) );
		wp_send_json_success( 'Deleted' );
	}

	public function ajax_download_report() {
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );

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
			'Date', 'YKW', 'Sport', 'Shower', 'Weight',
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
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );
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
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );
		global $wpdb;
		$id = intval( $_POST['id'] );
		$wpdb->delete( $this->table_events, array('id'=>$id) );
		wp_send_json_success( 'Deleted' );
	}
}

new SimpleDailyCalendar();
