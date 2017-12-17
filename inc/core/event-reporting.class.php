<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) header( 'Location: /');

class qsot_reporting {
	// holder for event plugin options
	protected static $o = null;
	protected static $options = null;

	public static function pre_init() {
		$settings_class_name = apply_filters('qsot-settings-class-name', '');
		if (!empty($settings_class_name)) {
			self::$o = call_user_func_array(array($settings_class_name, "instance"), array());

			// load all the options, and share them with all other parts of the plugin
			$options_class_name = apply_filters('qsot-options-class-name', '');
			if (!empty($options_class_name)) {
				self::$options = call_user_func_array(array($options_class_name, "instance"), array());
				//self::_setup_admin_options();
			}

			add_action( 'qsot_admin_reports', array( __CLASS__, 'extra_reports' ), 10 );
			//add_action('load-woocommerce_page_woocommerce_reports', array(__CLASS__, 'load_assets'), 10);
			add_action( 'load-toplevel_page_opentickets', array( __CLASS__, 'load_assets' ), 10 );
			add_action('init', array(__CLASS__, 'register_assets'), 10);

			// handle the reporting ajaz request
			$aj = QSOT_Ajax::instance();
			add_action( 'wp_ajax_qsot-admin-report-ajax', array( &$aj, 'handle_request' ) );
			add_action( 'wp_ajax_nopriv_qsot-admin-report-ajax', array( &$aj, 'handle_request' ) );

			// add the printerfriendly links to the report link output
			add_action( 'qsot-report-links', array( __CLASS__, 'add_view_links' ), 10, 2 );
		}
	}

}

// the base report class. creates a shell of all the functionality every report needs, and allows the reports themselves to do the heavy lifting
abstract class QSOT_Admin_Report {
	protected static $report_index = 0;

	protected $order = 10; // report order
	protected $group_name = ''; // display name of the group this report belongs to
	protected $group_slug = ''; // unique slug of the group this report belongs to
	protected $name = ''; // display name of the report
	protected $slug = ''; // unique slug of the report
	protected $description = ''; // short description of this report

	// setup the core object
	public function __construct() {
		// setup the default basic report info
		self::$report_index++;
		$this->group_name = sprintf( __( 'Report %s', 'opentickets-community-edition' ), self::$report_index );
		$this->group_slug = 'report-' . self::$report_index;
		$this->name = sprintf( __( 'Report %s', 'opentickets-community-edition' ), self::$report_index );
		$this->slug = 'report-' . self::$report_index;

		// add this object as a report
		add_filter( 'qsot-reports', array( &$this, 'register_report' ), $this->order );

		// allow reports to do some independent initialization
		$this->init();
	}

	// getter for slug, name and description
	public function slug() { return $this->slug; }
	public function name() { return $this->name; }
	public function description() { return $this->description; }

	// overrideable function to allow additional initializations
	public function init() {}

	// register this report, with our report list
	public function register_report( $list ) {
		// add the main key for this report, which we will then add the actual report to.
		// this structure is snatched from WC, which will allow for report grouping in later versions
		$list[ $this->group_slug ] = isset( $list[ $this->group_slug ] ) ? $list[ $this->group_slug ] : array( 'title' => $this->group_name, 'charts' => array() );

		// now add this specific report chart to the group
		$list[ $this->group_slug ]['charts'][ $this->slug ] = array(
			'title' => $this->name,
			'description' => $this->description,
			'function' => array( &$this, 'show_shell' ),
			'pf_function' => array( &$this, 'printer_friendly' ),
		);

		return $list;
	}

	// verify that we should be running the report right now, based on the submitted data
	protected function _verify_run_report( $only_orig=false ) {
		$run = true;
		// if the nonce or report name is not set, bail
		if ( ! isset( $_POST['_n'], $_POST['sa'] ) )
			$run = false;

		// if the report name does not match this report, bail
		if ( isset( $_POST['sa'] ) && $_POST['sa'] !== $this->slug )
			$run = false;

		// if the nonce does not match, then bail
		if ( isset( $_POST['_n'] ) && ! wp_verify_nonce( $_POST['_n'], 'do-qsot-admin-report-ajax' ) )
			$run = false;

		// if the extra function is false, then fail to run
		if ( ! $this->_extra_verify_run_report( $only_orig ) )
			$run = false;

		return apply_filters( 'qsot-user-can-run-report-' . $this->group_slug, $run, $only_orig, $this );;
	}

	// draw any errors that are passed
	protected function _error( WP_Error $error ) {
		?>
			<div class="report-errors">
				<?php foreach ( $error->get_error_codes() as $code ): ?>
					<?php foreach ( $error->get_error_messages( $code ) as $message ): ?>
						<div class="error"><?php echo force_balance_tags( $message ) ?></div>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
		<?php
	}

	// start the process of generating the results
	protected function _results() {
		// if the report is not supposed to run yet, then bail
		if ( ! $this->_verify_run_report() )
			return "null";

		// start the csv output file. if that fails, there is no point in continuing
		if ( ! ( $csv_file = $this->_open_csv_file( '', '', true ) ) )
			return $this->_error( new WP_Error( 'no_csv_file', __( 'Could not open the CSV file path. Aborting report generation.', 'opentickets-community-edition' ) ) );
		elseif ( is_wp_error( $csv_file ) )
			return $this->_error( $csv_file );

		// tell the report is about to start running
		$this->_starting();

		// add the header row to the csv
		$this->_csv_header_row( $csv_file );

		// draw the csv link
		$this->_csv_link( $csv_file );

		// draw the html version header
		$this->_html_report_header();

		$all_html_rows = 0;
		// run the report, while there are still rows to process
		while ( $group = $this->more_rows() )
			$all_html_rows += $this->_handle_row_group( $group, $csv_file );

		// before we close the footer, allow reportss to add some logic
		$this->_before_html_footer( $all_html_rows );

		// draw the html version footer
		$this->_html_report_footer();

		// draw the csv link
		$this->_csv_link( $csv_file );

		// close the csv file
		$this->_close_csv_file( $csv_file );

		// tell the report that it is done running
		$this->_finished();
	}

	// start and finish functions, overrideable by the individual report
	protected function _starting() {}
	protected function _finished() {}

	// allow reports to add stuff to the bottom of the table if needed
	protected function _before_html_footer( $all_html_rows ) {
		// if no html rows were printed, then print a row indicating that
		if ( empty( $all_html_rows ) ) {
			$columns = count( $this->html_report_columns() );
			$trCol = '<tr><td colspan="'.$columns.'">'.__( 'There are no tickts sold for this event yet.', 'opentickets-community-edition' ).'</td></tr>';
			echo ($trCol);
			?>
	<?php
		}
	}

	// render a single report row, based on some supplied row data
	protected function _html_report_row( $row, $columns=false, $cnt=false ) {
		// normalize the input
		if ( empty( $columns ) ) {
			$columns = $this->html_report_columns();
			$cnt = count( $columns );
		}

		$data = array();
		// cycle through thre required columns, and aggregate only the data we need for the data, in the order in which it should appear
		foreach ( $columns as $col => $__ )
			$data[ $col ] = isset( $row[ $col ] ) ? $row[ $col ] : '';

		// allow manipulation of this data
		$data = apply_filters( 'qsot-' . $this->slug . '-report-html-row', $data, $row, $columns );

		// if there is a row to display, the do os now
		if ( is_array( $data ) && count( $data ) == $cnt ) {
			?>
			<tr>
		<?php

			foreach ( $data as $col => $value ) {
		?>
			<td>
		<?php

				switch ( $col ) {
					// link the order id if present
					case 'order_id':
                        $format= '<a href="%s" target="_blank" title="%s">%s</a>';
					    $strOrderId = $row[ $col ] > 0 ? sprintf($format, get_edit_post_link( $value ), esc_attr( __( 'Edit order', 'opentickets-community-edition' ) ), $value ) : $value;
						echo $strOrderId;
					break;

					// default the purchaser name to the cart id
					case 'purchaser':
					    $strPur = ! empty( $value )
                            ? $value
                            : sprintf(
                                $format,
                                esc_attr( sprintf( __( 'Cart Session ID: %s', 'opentickets-community-edition' ), $row['_raw']->session_customer_id ) ),
                                __( 'Temporary Cart', 'opentickets-community-edition' )
                            );
						$format = '<span title="%s">'. $strPur .'</span>';

					break;
					// allow a filter on all other columns
					default:
						echo apply_filters( 'qsot-' . $this->slug . '-report-column-' . $col . '-value', '' == strval( $value ) ? '&nbsp;' : force_balance_tags( strval( $value ) ), $data, $row );
					break;
				}

				?>

				</td>
		<?php
			}

			?>

				</tr>
		<?php

			return 1;
		}

		return 0;
	}


	// take the resulting group of row datas, and create entries in the csv for them
	protected function _csv_render_rows( $group, $csv_file ) {
		session_start(); //if you are copying this code, this line makes it work.
		csrfguard_start();
		// if the csv file descriptor has gone away, then bail (could happen because of filters)
		if ( ! is_array( $csv_file ) || ! isset( $csv_file['fd'] ) || ! is_resource( $csv_file['fd'] ) )
			return;

		// get a list of the csv fields to add, and their order
		$columns = $this->csv_report_columns();
		$cnt = count( $columns );

		// cycle through the roup of rows, and create the csv entries
		if ( is_array( $group ) ) foreach ( $group as $row ) {
			$data = array();
			// create a list of data to add to the csv, based on the order of the columns we need, and the data for this row
			foreach ( $columns as $col => $__ ) {
				// update some rows with special values
				switch ( $col ) {
					// default the purchaser to a cart id
					case 'purchaser':
						$data[] = isset( $row[ $col ] ) && $row[ $col ]
								? ( '-' == $row[ $col ] ? ' ' . $row[ $col ] : $row[ $col ] ) // fix '-' being translated as a number in OOO
								: sprintf( __( 'Unpaid Cart: %s', 'opentickets-community-edition' ), $row['_raw']->session_customer_id );
					break;

					// pass all other data thorugh
					default:
						$data[] = isset( $row[ $col ] ) && $row[ $col ] ? ( '-' == $row[ $col ] ? ' ' . $row[ $col ] : $row[ $col ] ) : '';
					break;
				}
			}

			// allow manipulation of this data
			$data = apply_filters( 'qsot-' . $this->slug . '-report-csv-row', $data, $row, $columns );

			// add this row to the csv, if there is a row to add
			if ( is_array( $data ) && count( $data ) == $cnt )
				fputcsv( $csv_file['fd'], $data );
		}
	}

	// draw the link to the csv, based off of the passed csv file data
	protected function _csv_link( $file ) {
        // You may use a 'trasparent' anti-CSRF control, like this:
        include_once __DIR__ . '/libs/csrf/csrfprotector.php'; // FIXED
        csrfProtector::init();
        // Sensitive code follows...
		// if this is the printerfriendly version, then do not add the links
		if ( $this->is_printer_friendly() )
			return;

		// only print the link if the url is part of the data we got
		if ( ! is_array( $file ) || ! isset( $file['url'] ) || empty( $file['url'] ) )
			return;

		// render the link
		?>
			<div class="report-links">
				<a href="<?php echo esc_attr( $file['url'] ) ?>" title="<?php _e( 'Download this CSV', 'opentickets-community-edition' ) ?>"><?php _e( 'Download this CSV', 'opentickets-community-edition' ) ?></a>
				<?php do_action( 'qsot-report-links', $file, $this ) ?>
				<?php do_action( 'qsot-' . $this->slug . '-report-links', $file, $this ) ?>
			</div>
		<?php
	}

	// draw the report result footer, in html form
	protected function _html_report_footer() {
		// construct the footer of the resulting table
		?>
				</tbody>
				<tfoot><?php $this->_html_report_columns() ?></tfoot>
			</table>
		<?php
	}

	// draw the html columns
	protected function _html_report_columns( $header=false ) {
		// get a list of the report columns
		$columns = $this->html_report_columns();

		// render the columns row
		?>
			<tr>
				<?php foreach ( $columns as $column => $args ): ?>
					<?php
						// normalize the column args
						$args = wp_parse_args( $args, array(
							'title' => $column,
							'classes' => '',
							'attr' => '',
						) );
					?>
					<th class="col-<?php echo $column . ( $args['classes'] ? ' ' . esc_attr( $args['classes'] ) : '' ) ?>" <?php echo ( $args['attr'] ? ' ' . $args['attr'] : '' ); ?>>
						<?php echo force_balance_tags( $args['title'] ) ?>
						<?php if ( $header ): ?>
							<span class="dashicons dashicons-sort"></span>
							<span class="dashicons dashicons-arrow-up"></span>
							<span class="dashicons dashicons-arrow-down"></span>
						<?php endif; ?>
					</th>
				<?php endforeach; ?>
			</tr>
		<?php
	}

	// generic printer friendly header
	protected function _printer_friendly_header() {
		define( 'IFRAME_REQUEST', true );
		// direct copy from /wp-admin/admin-header.php
		$title='';
		$hook_suffix='';
		$current_screen='';

		// Catch plugins that include admin-header.php before admin.php completes.
		if ( empty( $current_screen ) )
			set_current_screen();

		get_admin_page_title();
		$title = esc_html( strip_tags( $title ) );

		if ( is_network_admin() )
			$admin_title = sprintf( __( 'Network Admin: %s' ), esc_html( get_current_site()->site_name ) );
		elseif ( is_user_admin() )
			$admin_title = sprintf( __( 'User Dashboard: %s' ), esc_html( get_current_site()->site_name ) );
		else
			$admin_title = get_bloginfo( 'name' );

		if ( $admin_title == $title )
			$admin_title = sprintf( __( '%1$s &#8212; WordPress' ), $title );
		else
			$admin_title = sprintf( __( '%1$s &lsaquo; %2$s &#8212; WordPress' ), $title, $admin_title );

		/**
		 * Filter the title tag content for an admin page.
		 *
		 * @since 3.1.0
		 *
		 * @param string $admin_title The page title, with extra context added.
		 * @param string $title       The original page title.
		 */
		$admin_title = apply_filters( 'admin_title', $admin_title, $title );

		wp_user_settings();

		_wp_admin_html_begin();
		?>
		<title><?php echo $admin_title; ?></title>
		<?php

		wp_enqueue_style( 'colors' );
		wp_enqueue_style( 'ie' );
		wp_enqueue_script('utils');
		wp_enqueue_script( 'svg-painter' );

		?>
		<script type="text/javascript">
		addLoadEvent = function(func){if(typeof jQuery!="undefined")jQuery(document).ready(func);else if(typeof wpOnload!='function'){wpOnload=func;}else{var oldonload=wpOnload;wpOnload=function(){oldonload();func();}}};
		var ajaxurl = '<?php echo admin_url( 'admin-ajax.php', 'relative' ); ?>',
			pagenow = '<?php echo $current_screen->id; ?>',
			typenow = '<?php echo $current_screen->post_type; ?>',
			adminpage = '<?php echo preg_replace('/[^a-z0-9_-]+/i', '-', $hook_suffix); ?>',
			isRtl = <?php echo (int) is_rtl(); ?>;
		</script>
		<meta name="viewport" content="width=device-width,initial-scale=1.0">
		<?php
        $wp_version='';

		/**
		 * Enqueue scripts for all admin pages.
		 *
		 * @since 2.8.0
		 *
		 * @param string $hook_suffix The current admin page.
		 */
		do_action( 'admin_enqueue_scripts', $hook_suffix );

		/**
		 * Fires when styles are printed for a specific admin page based on $hook_suffix.
		 *
		 * @since 2.6.0
		 */
		do_action( "admin_print_styles-$hook_suffix" );

		/**
		 * Fires when styles are printed for all admin pages.
		 *
		 * @since 2.6.0
		 */
		do_action( 'admin_print_styles' );

		/**
		 * Fires when scripts are printed for a specific admin page based on $hook_suffix.
		 *
		 * @since 2.1.0
		 */
		do_action( "admin_print_scripts-$hook_suffix" );

		/**
		 * Fires when scripts are printed for all admin pages.
		 *
		 * @since 2.1.0
		 */
		do_action( 'admin_print_scripts' );

		/**
		 * Fires in head section for a specific admin page.
		 *
		 * The dynamic portion of the hook, `$hook_suffix`, refers to the hook suffix
		 * for the admin page.
		 *
		 * @since 2.1.0
		 */
		do_action( "admin_head-$hook_suffix" );

		/**
		 * Fires in head section for all admin pages.
		 *
		 * @since 2.1.0
		 */
		do_action( 'admin_head' );

        $admin_body_class = preg_replace('/[^a-z0-9_-]+/i', '-', $hook_suffix);

        if ( get_user_setting('mfold') == 'f' ) {
		    $admin_body_class .= ' folded';
		}

		if ( !get_user_setting('unfold') )
			$admin_body_class .= ' auto-fold';

		if ( is_admin_bar_showing() )
			$admin_body_class .= ' admin-bar';

		if ( is_rtl() )
			$admin_body_class .= ' rtl';

		if ( $current_screen->post_type )
			$admin_body_class .= ' post-type-' . $current_screen->post_type;

		if ( $current_screen->taxonomy )
			$admin_body_class .= ' taxonomy-' . $current_screen->taxonomy;

		$admin_body_class .= ' branch-' . str_replace( array( '.', ',' ), '-', floatval( $wp_version ) );
		$admin_body_class .= ' version-' . str_replace( '.', '-', preg_replace( '/^([.0-9]+).*/', '$1', $wp_version ) );
		$admin_body_class .= ' admin-color-' . sanitize_html_class( get_user_option( 'admin_color' ), 'fresh' );
		$admin_body_class .= ' locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', get_locale() ) ) );

		if ( wp_is_mobile() )
			$admin_body_class .= ' mobile';

		if ( is_multisite() )
			$admin_body_class .= ' multisite';

		if ( is_network_admin() )
			$admin_body_class .= ' network-admin';

		$admin_body_class .= ' no-customize-support no-svg';

		?>
		</head>
		<?php
		/**
		 * Filter the CSS classes for the body tag in the admin.
		 *
		 * This filter differs from the {@see 'post_class'} and {@see 'body_class'} filters
		 * in two important ways:
		 *
		 * 1. `$classes` is a space-separated string of class names instead of an array.
		 * 2. Not all core admin classes are filterable, notably: wp-admin, wp-core-ui,
		 *    and no-js cannot be removed.
		 *
		 * @since 2.3.0
		 *
		 * @param string $classes Space-separated list of CSS classes.
		 */
		$admin_body_classes = apply_filters( 'admin_body_class', '' );
		$bodyRender = '<body class="wp-admin wp-core-ui no-js printer-friendly-report '. $admin_body_classes . ' ' . $admin_body_class .'">
		<div id="wpwrap">
		<div class="inner-wrap">';
		echo $bodyRender;
		?>

		<?php
	}

	// each report should control it's own form
	abstract public function form();

	// individual reports should define their own set of columns to display in html
	abstract public function html_report_columns();

	// individual reports should define their own set of columns to add to the csv
	abstract public function csv_report_columns();

	// the report should define a function to get a partial list of rows to process for this report. for instance, we don't want to have one group of 1,000,000 rows, run all at once, because
	// the memory implications on that are huge. instead we would need to run it in discreet groups of 1,000 or 10,000 rows at a time, depending on the processing involved
	abstract public function more_rows();

	// the report should define a function to process a group of results, which it contructed in the more_rows() method
	abstract public function aggregate_row_data( array $group );
}

if (defined('ABSPATH') && function_exists('add_action')) {
	qsot_reporting::pre_init();
}
