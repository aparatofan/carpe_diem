<?php
/**
 * Plugin Name: Simple Daily Calendar with Popups
 * Description: Second Brain Tracker: Export Moved to Bottom (Version 5.2).
 * Version: 5.2
 * Author: AI Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class SimpleDailyCalendar {

	private $table_content;
	private $table_holidays;

	public function __construct() {
		global $wpdb;
		$this->table_content  = $wpdb->prefix . 'daily_calendar_content';
		$this->table_holidays = $wpdb->prefix . 'daily_calendar_holidays';

		register_activation_hook( __FILE__, array( $this, 'create_tables' ) );
		add_shortcode( 'daily_calendar', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_sdc_get_content', array( $this, 'ajax_get_content' ) );
		add_action( 'wp_ajax_sdc_save_content', array( $this, 'ajax_save_content' ) );
		add_action( 'wp_ajax_sdc_add_holiday', array( $this, 'ajax_add_holiday' ) );
		add_action( 'wp_ajax_sdc_delete_holiday', array( $this, 'ajax_delete_holiday' ) );
		add_action( 'wp_ajax_sdc_download_report', array( $this, 'ajax_download_report' ) );
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

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql1 );
		dbDelta( $sql2 );
	}

	public function enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'daily_calendar' ) ) {
			wp_enqueue_media();
			wp_enqueue_style( 'fullcalendar-css', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css', array(), '6.1.10' );
			wp_enqueue_script( 'fullcalendar-js', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js', array( 'jquery' ), '6.1.10', true );
			wp_enqueue_script( 'ckeditor-js', 'https://cdn.ckeditor.com/ckeditor5/41.2.0/classic/ckeditor.js', array(), '41.2.0', true );

			// Version 5.2
			wp_enqueue_style( 'sdc-style', plugin_dir_url( __FILE__ ) . 'assets/sdc-style.css', array(), '5.2' );
			wp_enqueue_script( 'sdc-script', plugin_dir_url( __FILE__ ) . 'assets/sdc-script.js', array( 'jquery', 'fullcalendar-js', 'ckeditor-js' ), '5.2', true );

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

		$results_content = $wpdb->get_results( "SELECT * FROM $this->table_content", ARRAY_A );
		foreach($results_content as $row) {
			$icons = '';
			if ( ! empty( $row['weight'] ) ) $icons .= '⚖️ ';
			if ( $row['shower'] == 1 ) $icons .= '🚿 ';
			if ( $row['sport'] == 1 )  $icons .= '🚴 ';
			if ( $row['ykw'] == 1 )    $icons .= '🤫 ';
			if ( ! empty( $row['highlights'] ) )    $icons .= '⭐ ';
			if ( ! empty( $row['daily_text'] ) )    $icons .= '📝 ';
			if ( ! empty( $row['talking_head'] ) )  $icons .= '🗣️ ';
			if ( ! empty( $row['podcasts'] ) )      $icons .= '🎧 ';
			if ( ! empty( $row['books'] ) )         $icons .= '📖 ';
			if ( ! empty( $row['films'] ) )         $icons .= '🎬 ';
			if ( ! empty( $row['lessons'] ) )       $icons .= '🌳 ';
			if ( ! empty( $row['posts_entries'] ) ) $icons .= '✍️ ';

			if(!empty($icons)) {
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
						'books' => !empty($row['books']),
						'films' => !empty($row['films']),
						'lessons' => !empty($row['lessons']),
						'posts_entries' => !empty($row['posts_entries'])
					)
				);
			}
		}

		$results_holidays = $wpdb->get_results( "SELECT * FROM $this->table_holidays", ARRAY_A );
		foreach($results_holidays as $row) {
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
	<div class="sdc-modal-header">
	<h3 id="sdc-modal-date-title">Date</h3>

	<div class="sdc-modal-header-actions">
		<?php if ( current_user_can( 'edit_posts' ) ) : ?>
			<button type="button" id="sdc-btn-switch-to-edit-top" class="button button-primary sdc-edit-top-btn">
				Edit Content
			</button>
		<?php endif; ?>

		<button type="button" class="button sdc-close-btn" aria-label="Close modal">
			Close
		</button>
	</div>
</div>

	<div id="sdc-loading" style="display:none;">Loading data...</div>
				
				<?php if ( current_user_can( 'edit_posts' ) ) : ?>
				<div class="sdc-tabs">
					<button class="sdc-tab-btn active" data-tab="content" id="sdc-tab-btn-content">Day Content</button>
					<button class="sdc-tab-btn" data-tab="holidays" id="sdc-tab-btn-holidays">Holidays</button>
				</div>
				<?php endif; ?>

				<div id="sdc-tab-content-area" class="sdc-tab-pane active">
					<div id="sdc-view-mode" style="display:none;">
						
						<div id="sdc-holiday-display-area"></div>

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
						<div class="sdc-view-section"><strong>📖 Books & Audio:</strong> <div id="view_books"></div></div>
						
						<div class="sdc-view-section">
							<strong>🎬 Films & Series:</strong> 
							<span id="view_films_rating" style="font-weight:bold; color:#0856c9; margin-left:10px;"></span>
							<div id="view_films"></div>
						</div>
						
						<div class="sdc-view-section"><strong>🌳 Blue Tree Lessons:</strong> <div id="view_lessons"></div></div>
						<div class="sdc-view-section"><strong>✍️ Posts & Enchiridion:</strong> <div id="view_posts_entries"></div></div>
						
						<?php if ( current_user_can( 'edit_posts' ) ) : ?>
							<button id="sdc-btn-switch-to-edit" class="button button-primary" style="margin-top:15px;">Edit Content</button>
						<?php endif; ?>
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
								'sdc_podcasts' => '🎧 Podcasts & Documentaries',
								'sdc_books' => '📖 Books and Audiobooks'
							];
							foreach($fields as $id => $label): ?>
								<div class="sdc-form-group">
									<label for="<?php echo $id; ?>"><?php echo $label; ?>:</label>
									<textarea id="<?php echo $id; ?>" name="<?php echo $id; ?>" rows="2" class="widefat sdc-editor"></textarea>
								</div>
							<?php endforeach; ?>

							<div class="sdc-form-group">
								<div style="display:flex; justify-content:space-between; align-items:center;">
									<label for="sdc_films">🎬 Films & Series:</label>
									<div style="font-size:0.9em;">
										Rating (0-6): 
										<select id="sdc_films_rating" name="sdc_films_rating" style="max-width:60px;">
											<option value="">-</option>
											<option value="0">0</option>
											<option value="1">1</option>
											<option value="2">2</option>
											<option value="3">3</option>
											<option value="4">4</option>
											<option value="5">5</option>
											<option value="6">6</option>
										</select>
									</div>
								</div>
								<textarea id="sdc_films" name="sdc_films" rows="2" class="widefat sdc-editor"></textarea>
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

		wp_send_json_success( array( 
			'has_content' => (bool)$content, 
			'data' => $content,
			'holidays' => $holidays
		));
	}

	public function ajax_save_content() {
		check_ajax_referer( 'sdc_nonce', 'security' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );
		global $wpdb;
		
		$data = array(
			'event_date'   => sanitize_text_field( $_POST['sdc_date_field'] ),
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
			'books'        => wp_kses_post( wp_unslash($_POST['sdc_books']) ),
			'films'        => wp_kses_post( wp_unslash($_POST['sdc_films']) ),
			'films_rating' => ( isset($_POST['sdc_films_rating']) && $_POST['sdc_films_rating'] !== '' ) ? intval($_POST['sdc_films_rating']) : null, 
			'lessons'      => wp_kses_post( wp_unslash($_POST['sdc_lessons']) ),
			'posts_entries'=> wp_kses_post( wp_unslash($_POST['sdc_posts_entries']) ),
		);

		if ( empty( $data['event_date'] ) ) wp_send_json_error( 'Missing date error.' );
		$result = $wpdb->replace( $this->table_content, $data );
		if ( false === $result ) wp_send_json_error( 'Database error.' );
		else wp_send_json_success( 'Saved successfully!' );
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

		if(empty($results)) wp_send_json_error( 'No data found for this month' );

		$header = [
			'Date', 'YKW', 'Sport', 'Shower', 'Weight', 
			'Highlights', 'Daily Text', 'Talking Head', 'Podcasts', 
			'Books', 'Films', 'Film Rating', 'Lessons', 'Posts'
		];

		ob_clean();
		$fp = fopen( 'php://temp', 'r+' );
		
		fputs( $fp, "\xEF\xBB\xBF" ); 
		fputcsv( $fp, $header );

		foreach($results as $row) {
			$line = [
				$row['event_date'],
				$row['ykw'] ? '1' : '0',
				$row['sport'] ? '1' : '0',
				$row['shower'] ? '1' : '0',
				$row['weight'],
				strip_tags( $row['highlights'] ),
				strip_tags( $row['daily_text'] ),
				strip_tags( $row['talking_head'] ),
				strip_tags( $row['podcasts'] ),
				strip_tags( $row['books'] ),
				strip_tags( $row['films'] ),
				$row['films_rating'],
				strip_tags( $row['lessons'] ),
				strip_tags( $row['posts_entries'] )
			];
			fputcsv( $fp, $line );
		}

		rewind( $fp );
		$csv_data = stream_get_contents( $fp );
		fclose( $fp );

		wp_send_json_success( $csv_data );
	}
}


new SimpleDailyCalendar();

