<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) header( 'Location: /');

require_once $woocommerce->plugin_path() . '/includes/admin/class-wc-admin-settings.php';

class qsot_admin_settings extends WC_Admin_Settings {

	// setup the pages, by loading their classes and assets and such
	public static function get_settings_pages() {
        static $settings = array();
		// load the settings pages, if they are not already loaded
		if ( empty( $settings ) ) {
			// load the admin page assets from our plugin
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'load_admin_page_assets' ), 1000 );

			// load the woocommerce wysiwyg field js
			add_action( 'woocommerce_admin_field_wysiwyg', array( __CLASS__, 'field_wysiwyg' ) );

			// allow html in wysiwyg fields
			add_filter( 'woocommerce_admin_settings_sanitize_option', array( __CLASS__, 'save_field_wysiwyg' ), 10, 3 );

			// handle qtranslate LSB fields
			add_action( 'woocommerce_admin_field_qtranslate-lsb', array( __CLASS__, 'field_lsb' ) );

			$settings = array();

			// load the woocoomerce settings api
			include_once WC()->plugin_path() . '/includes/admin/settings/class-wc-settings-page.php';
			include_once QSOT::plugin_dir() . 'inc/sys/settings-page.abstract.php';

			// load the various settings pages
            $settings[] = include 'settings/general.php';
            $settings[] = include 'settings/frontend.php';
            $settings[] = include 'settings/dates.php';

			// allow adding of other pages if needed
            $settings = array_filter( array_values( apply_filters( 'qsot_get_settings_pages', $settings ) ) );
		}

		return $settings;
	}

	// load the admin page assets, depending on the page we are viewing
	public static function load_admin_page_assets( $hook ) {
		// if the current page is the settings page, then load our settings js
        $settings = apply_filters( 'qsot-get-menu-page-uri', array(), 'settings' );
		if ( isset( $settings[1] ) && $hook == $settings[1] ) {
			wp_enqueue_media();
			wp_enqueue_script( 'qsot-admin-settings' );
			wp_enqueue_style( 'qsot-admin-settings' );
		}
	}

	public static function save() {
		$current_tab='';

		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'qsot-settings' ) )
            trigger_error("Action failed. Please refresh the page and retry.", E_USER_NOTICE);

        // Trigger actions
		do_action( 'qsot_settings_save_' . $current_tab );
		do_action( 'qsot_update_options_' . $current_tab );
		do_action( 'qsot_update_options' );

		self::add_message( __( 'Your settings have been saved.','opentickets-community-edition' ) );
		self::check_download_folder_protection();

		do_action( 'qsot_settings_saved' );

		wp_safe_redirect( apply_filters( 'qsot-settings-save-redirect', add_query_arg( array( 'updated' => 1 ) ), $current_tab ) );
	}

	// handle the output of the qTranslate LSB fields on the settings page
	public static function field_lsb( ) {
		// if the qtranslate plugin is not active, then bail
		if ( ! defined( 'QTRANSLATE_DIR' ) )
			{return;}
		?><tr valign="top"><td colspan="2"><div id="<?php echo esc_attr( $args['id'] ) ?>"></div></td></tr><?php
	}

	// handle the output of wysiwyg fields on the settings pages
	public static function field_wysiwyg( $args ) {
		$args = wp_parse_args( $args, array(
			'id' => '',
			'title' => '',
			'default' => '',
			'class' => '',
		) );
		if ( empty( $args['id'] ) ) return;

		$args['title'] = ( empty( $args['title'] ) ) ? ucwords( implode( ' ', explode( '-', str_replace( '_', '-', $args['id'] ) ) ) ) : $args['title'];

		?><tr valign="top" class="woocommerce_wysiwyg">
			<th scope="row" class="titledesc">
				<?php echo force_balance_tags( $args['title'] ) ?>
			</th>
			<td class="forminp"><?php
				wp_editor(
					get_option( $args['id'], $args['default'] ),
					$args['id'],
					array(
						'quicktags' => false,
						'teeny' => true,
						'textarea_name' => $args['id'],
						'textarea_rows' => 2,
						'media_buttons' => false,
						'wpautop' => false,
						'editor_class' => $args['class'],
						'tinymce' => array( 'wp_autoresize_on' => '', 'paste_as_text' => true ),
					)   
				);
			?></td>
		</tr><?php
	}

	// when saving the wysiwyg field, we need to allow html
	public static function save_field_wysiwyg( $value, $option, $raw ) {
		// if this is not a wysiwyg field, then pass the value through
		if ( ! isset( $option['type'] ) || 'wysiwyg' !== $option['type'] )
			return $value;

		return wp_kses_post( trim( $raw ) );
	}

	/**
	 * Add a message
	 * @param string $text
	 */
	public static function add_message( $text ) {
        static $messages  = array();
        $messages[] = $text;
	}

	/**
	 * Add an error
	 * @param string $text
	 */
	public static function add_error( $text ) {
        static $errors  = array();
        $errors[] = $text;
	}

	/**
	 * Output messages + errors
	 */
	public static function show_messages() {
        static $errors  = array();
        static $messages  = array();
        if ( sizeof($errors ) > 0 ) {
			foreach ( $errors as $error )
				?>
				<div id="message" class="error fade"><p><strong><?php echo esc_html( $error ) ?></strong></p></div>
<?php
		} elseif ( sizeof( $messages ) > 0 ) {
			foreach ($messages as $message )
?>
				<div id="message" class="updated fade"><p><strong><?php esc_html( $message ) ?></strong></p></div>
<?php
		}
	}

	public static function output() {

		do_action( 'qsot_settings_start' );

		wp_enqueue_script( 'qsot_settings', WC()->plugin_url() . '/assets/js/admin/settings.min.js', array( 'jquery', 'jquery-ui-datepicker', 'jquery-ui-sortable', 'iris' ), WC()->version, true );

		wp_localize_script( 'woocommerce_settings', 'woocommerce_settings_params', array(
			'i18n_nav_warning' => __( 'The changes you made will be lost if you navigate away from this page.','opentickets-community-edition' )
		) );

		// Include settings pages
		//self::get_settings_pages();

		// Get current tab/section
		//$current_tab     = empty( $_GET['tab'] ) ? 'general' : sanitize_title( $_GET['tab'] );
		//$current_section = empty( $_REQUEST['section'] ) ? '' : sanitize_title( $_REQUEST['section'] );

		// Save settings if data has been posted
		//if ( ! empty( $_POST ) )
			//self::save();

		// Add any posted messages
		if ( ! empty( $_GET['wc_error'] ) )
			self::add_error( stripslashes( $_GET['wc_error'] ) );

		 if ( ! empty( $_GET['wc_message'] ) )
			self::add_message( stripslashes( $_GET['wc_message'] ) );

		self::show_messages();

		// Get tabs for the settings page
		apply_filters( 'qsot_settings_tabs_array', array() );

		include 'views/html-admin-settings.php';
	}
}
