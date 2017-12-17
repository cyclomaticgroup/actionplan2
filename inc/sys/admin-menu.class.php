<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) header( 'Location: /');

class qsot_admin_menu {
	protected static $o = array();
	protected static $options = array();
	protected static $menu_slugs = array(
		'main' => 'opentickets',
		'settings' => 'opentickets-settings',
		'documentation' => 'opentickets-documentation',
		'videos' => 'opentickets-documentation',
	);
	protected static $menu_page_hooks = array(
		'main' => 'toplevel_page_opentickets',
		'settings' => 'opentickets_page_opentickets-settings',
	);
	protected static $menu_page_uri = '';

	// container for the reports page object
	protected static $reports = null;

	public static function pre_init() {
		$settings_class_name = apply_filters('qsot-settings-class-name', '');
		if (!empty($settings_class_name)) {
			self::$o = call_user_func_array(array($settings_class_name, "instance"), array());

			$options_class_name = apply_filters('qsot-options-class-name', '');
			if (!empty($options_class_name)) {
				self::$options = call_user_func_array(array($options_class_name, "instance"), array());
				self::_setup_admin_options();
			}

			self::$menu_page_uri = add_query_arg(array('page' => self::$menu_slugs['main']), 'admin.php');

			add_action('init', array(__CLASS__, 'register_assets'), 0);
			add_action('init', array(__CLASS__, 'register_post_types'), 1);

			add_action('qsot-activate', array(__CLASS__, 'on_activation'), 10);

			// allow some core woocommerce assets to be loaded on our pages
			add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'load_woocommerce_admin_assets' ), 10 );
			add_filter( 'woocommerce_reports_screen_ids', array( __CLASS__, 'load_woocommerce_admin_assets' ), 10 );
			// get the uri/hook/slug of our settings pages for use in asset enqueuing and such
			add_filter( 'qsot-get-menu-page-uri', array( __CLASS__, 'menu_page_uri' ), 10, 3 );
			add_filter( 'qsot-get-menu-slug', array( __CLASS__, 'menu_page_slug' ), 10, 2 );

			add_action('admin_menu', array(__CLASS__, 'create_menu_items'), 11);
			add_action('admin_menu', array(__CLASS__, 'repair_menu_order'), PHP_INT_MAX);
			add_action( 'admin_menu', array( __CLASS__, 'external_links' ), PHP_INT_MAX );
			//add_action('qsot_daily_stats', array(__CLASS__, 'daily_stats'), 1000);
			//add_action('activate_plugin', array(__CLASS__, 'incremental_stats'), 1000, 2);
			//add_action('deactivate_plugin', array(__CLASS__, 'incremental_stats'), 1000, 2);
			//add_action('switch_theme', array(__CLASS__, 'incremental_stats'), 1000, 2);

			// when saving settings, we could have updated the /qsot-event/ url slug... so we need to updating the permalinks on page refresh
			add_action( 'qsot-settings-save-redirect', array( __CLASS__, 'refresh_permalinks_on_save_uri' ), 10, 2 );
			add_action( 'admin_init', array( __CLASS__, 'refresh_permalinks_on_save_page_refresh' ), 1 );

			if (is_admin()) {
				self::_check_cron();
			}
		}
	}

	// register the assets needed by our plugin in the admin
	public static function register_assets() {
		// the js to handle the analytics nag
		wp_register_script( 'qsot-nag', self::$o->core_url . 'assets/js/admin/nag.js', array( 'qsot-tools' ), self::$o->version );

		// used on the various settings pages
		wp_register_script( 'qsot-admin-settings', self::$o->core_url . 'assets/js/admin/settings-page.js', array( 'qsot-tools', 'iris' ), self::$o->version );
		wp_register_style( 'qsot-admin-settings', self::$o->core_url . 'assets/css/admin/settings-page.css', array(), self::$o->version );
	}

	// fetch the page uri for a settings page in our plugin
	public static function menu_page_uri( $which='main', $omit_hook=false ) {
		$page_slug = isset( self::$menu_slugs['main'] ) ? self::$menu_slugs['main'] : '';
		// figure out the slug for the page
		if ( ! empty( $which ) && is_scalar( $which ) && isset( self::$menu_slugs[ $which ] ) )
			$page_slug = self::$menu_slugs[ $which ];

		// if we are just looking for the page uri, and not the uri and hook, then just return the uri now
		if ( $omit_hook )
			return add_query_arg( array( 'page' => $page_slug ), 'admin.php' );

		// otherwise return both
		return array(
			add_query_arg( array( 'page' => $page_slug ), 'admin.php' ),
			isset( self::$menu_page_hooks[ $which ] ) ? self::$menu_page_hooks[ $which ] : ''
		);
	}

	public static function repair_menu_order() {
		$menu='';

		$core = apply_filters('qsot-events-core-post-types', array());
		foreach ($core as $k => $v) {
			$core[$k]['__name'] = is_array($v['label_replacements']) && isset($v['label_replacements'], $v['label_replacements']['plural'])
				? $v['label_replacements']['plural']
				: ucwords(preg_replace('#[-_]+#', ' ', $k));
		}

		foreach ($menu as $ind => $m) {
			foreach ($core as $k => $v) {
				if (strpos($m[2], 'post_type='.$k) !== false && $m[0] === $v['__name']) {
					$pos = isset($v['args'], $v['args']['menu_position']) ? $v['args']['menu_position'] : false;
					if (!empty($pos) && $pos != $ind) {
						$menu["$pos"] = $m;
						unset($menu["$ind"]);
						break;
					}
				}
			}
		}
	}

	// register our custom menu items for our settings pages
	public static function create_menu_items() {
		// make the main menu item
		self::$menu_page_hooks['main'] = add_menu_page(
			self::$o->product_name,
			self::$o->product_name,
			'view_woocommerce_reports',
			self::$menu_slugs['main'],
			array( __CLASS__, 'ap_reports_page' ),
			false,
			21
		);

		// reports menu item
		self::$menu_page_hooks['main'] = add_submenu_page(
			self::$menu_slugs['main'],
			__( 'Reports', 'opentickets-community-edition' ),
			__( 'Reports', 'opentickets-community-edition' ),
			'view_woocommerce_reports',
			self::$menu_slugs['main'],
			array( __CLASS__, 'ap_reports_page' ),
			false,
			21
		);

		// settings menu item
		self::$menu_page_hooks['settings'] = add_submenu_page(
			self::$menu_slugs['main'],
			__( 'Settings', 'opentickets-community-edition' ),
			__( 'Settings', 'opentickets-community-edition' ),
			'manage_options',
			self::$menu_slugs['settings'],
			array( __CLASS__, 'ap_settings_page' )
		);

		// generic function to call some page load logic
		add_action( 'load-' . self::$menu_page_hooks['main'], array( __CLASS__, 'ap_reports_page_head' ) );
		add_action( 'load-' . self::$menu_page_hooks['settings'], array( __CLASS__, 'ap_settings_page_head' ) );
	}

	// get the reports page object
	protected static function _reports_page() {
		// if the page was already loaded, the return it
		if ( is_object( self::$reports ) )
			return self::$reports;

		// otherwise load it
		return self::$reports = require_once 'admin-reports.php' ;
	}

	// page load logic for the reports page
	public static function ap_reports_page_head() {
		$reports = self::_reports_page();
		$reports->on_load();
	}

	// draw the reports page
	public static function ap_reports_page() {
		$reports = self::_reports_page();
		$reports->output();
	}

	public static function vit($v) {
		$p = explode('.', preg_replace('#[^\d]+#', '.', preg_replace('#[a-z]#i', '', $v)));
		return sprintf('%03s%03s%03s', array_shift($p), array_shift($p), array_shift($p));
	}

	protected static function _register_post_type($slug, $pt) {
		$labels = array(
			'name' => '%plural%',
			'singular_name' => '%singular%',
			'add_new' => __('Add %singular%','opentickets-community-edition'),
			'add_new_item' => __('Add New %singular%','opentickets-community-edition'),
			'edit_item' => __('Edit %singular%','opentickets-community-edition'),
			'new_item' => __('New %singular%','opentickets-community-edition'),
			'all_items' => __('All %plural%','opentickets-community-edition'),
			'view_item' => __('View %singular%','opentickets-community-edition'),
			'search_items' => __('Search %plural%','opentickets-community-edition'),
			'not_found' =>  __('No %lplural% found','opentickets-community-edition'),
			'not_found_in_trash' => __('No %lplural% found in Trash','opentickets-community-edition'),
			'parent_item_colon' => '',
			'menu_name' => '%plural%'
		);

		$args = array(
			'public' => false,
			'show_ui' => true,
			'menu_position' => 22,
			'supports' => array(
				'title',
				'thumbnail',
			),
			'register_meta_box_cb' => false,
			'permalink_epmask' => EP_PAGES,
		);

		$sr = array();
		if (isset($pt['label_replacements'])) {
			foreach ($pt['label_replacements'] as $k => $v) {
				$sr['%'.$k.'%'] = $v;
				$sr['%l'.$k.'%'] = strtolower($v);
			}
		} else {
			$name = ucwords(preg_replace('#[-_]+#', ' ', $slug));
			$sr = array(
				'%plural%' => $name.'s',
				'%singular%' => $name,
				'%lplural%' => strtolower($name.'s'),
				'%lsingular%' => strtolower($name),
			);
		}
		
		foreach ($labels as $k => $v) $labels[$k] = str_replace(array_keys($sr), array_values($sr), $v);

		if (isset($pt['args']) && (is_string($pt['args']) || is_array($pt['args']))) $args = wp_parse_args($pt['args'], $args);

		$args['labels'] = $labels;
		// slightly different than normal. core WP does not tell the register_meta_box_cb()  the post type, which i think is wrong. it is not relevant here, but what if you
		// have a list of post types that are similar, or a dynamic list of post types of which you do not know all the information of. think of a situation where they were all 
		// so similar that the only difference in the metabox that we defined was the title of the metabox, the content of it was identical, but the title was dependent on the post type.
		// why should you create 3 different funcsx that declare the exact same metabox, with the exception of the title of the metabox, when it could easily be solved in a single
		// function if you know the post type. i think it is an oversight, and should be considered as a core change. despite that, my method adds that as a second param to the function,
		// assuming we can actually do it. otherwise the passed funcx is just passed through as is.

		register_post_type($slug, $args);
	}

	protected static function _setup_admin_options() {
		self::$options->def('qsot-allow-stats', 'no');
		self::$options->def( 'qsot-event-permalink-slug', self::$o->core_post_type );

		self::$options->add(array(
			'order' => 100,
			'type' => 'title',
			'title' => __('Global Settings','opentickets-community-edition'),
			'id' => 'heading-general-1',
		));

		self::$options->add(array(
			'order' => 101,
			'id' => 'qsot-allow-stats',
			'type' => 'checkbox',
			'title' => __( 'Allow Statistics', 'opentickets-community-edition' ),
			'desc' => __( 'Allow OpenTickets to gather information about your WordPress installation.', 'opentickets-community-edition' ),
			'desc_tip' => __( 'This information is strictly used to make this product better and more compatible with other plugins.', 'opentickets-community-edition' ),
			'default' => 'no',
		));

		self::$options->add(array(
			'order' => 103,
			'id' => 'qsot-event-permalink-slug',
			//'class' => 'i18n-multilingual', // cant do yet i dont think
			'type' => 'text',
			'title' => __( 'Event Link Slug', 'opentickets-community-edition' ),
			'desc' => __( 'The url slug that is prepended to the event name in the url. (ex: <code>http://example.com/<strong>event</strong>/my-event/</code>)', 'opentickets-community-edition' ),
			'desc_tip' => __( 'This is the segment of the url that preceeds the event name.', 'opentickets-community-edition' ),
			'default' => self::$options->{'qsot-event-permalink-slug'},
		));

		self::$options->add(array(
			'order' => 199,
			'type' => 'sectionend',
			'id' => 'heading-general-1',
		));
	}

	public static function on_activation() {
		self::register_post_types();
	}
}

if (defined('ABSPATH') && function_exists('add_action')) {
	qsot_admin_menu::pre_init();
}
