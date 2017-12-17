<?php

class EventSettings
{
    // add the extra view links to the top and bottom of the report results tables. for now this is just printerfriendly links
    public static function add_view_links($report ) {
        // if this is the printerfriendly version, then dont add the links
        if ( $report->is_printer_friendly() )
            return;

        // construct the printer friendly url
        $url = $report->printer_friendly_url();

        // add the printer-friendly link
        $format = '<a href="%s" title="%s" target="_blank">%s</a>';
        echo sprintf(
            $format,
            $url,
            __( 'View a printer-friendly version of this report.', 'opentickets-community-edition' ),
            __( 'Printer-Friendly Report', 'opentickets-community-edition' )
        );
    }

    public static function extra_reports($reports) {
        $event_reports = (array)apply_filters('qsot-reports', array());
        foreach ($event_reports as $slug => $settings) {
            if (!isset($settings['charts']) || empty($settings['charts'])) continue;
            $name = isset($settings['title']) ? $settings['title'] : $slug;
            $slug = sanitize_title_with_dashes($slug);
            $reports[$slug] = array(
                'title' => $name,
                'charts' => $settings['charts'],
            );
        }

        return $reports;
    }

    // generic ajax processing function, which should be overridden by reports that use ajax
    protected function _process_ajax() {}

    // validate and pass on the ajax requests for this report
    public function handle_ajax() {
        $max = defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '256M';
        $max_val = QSOT::xb2b( $max );
        if ( $max_val < 268435456 )
            $max = '256M';
        // memory limit fix for plugins that mess with the memory limit
        ini_set( 'memory_limit', $max );
        if ( isset( $_COOKIE, $_COOKIE['otdebug'] ) && 'opentickets' == $_COOKIE['otdebug'] ) {
            error_reporting( E_ALL );
            ini_set( 'html_errors', 1 );
        }
        // if the current user does not have permissions to run the report, then bail
        //if ( ! current_user_can( 'view_woocommerce_reports' ) )
        //return $this->_error( new WP_Error( 'no_permission', __( 'You do not have permission to use this report.', 'opentickets-community-edition' ) ) );

        // if the ajax request does not validate, then bail
        //if ( ! $this->_verify_run_report( true ) )
        //return $this->_error( new WP_Error( 'no_permission', __( 'You do not have permission to use this report.', 'opentickets-community-edition' ) ) );

        // pass the request on to the processing function
        $this->_process_ajax();
    }

    // determine if this request is a printerfriendly request
    public function is_printer_friendly() {
        return isset( $_GET['pf'] ) && 1 == $_GET['pf'];
    }

    // construct a printer friendly url for this report
    public function printer_friendly_url() {
        return add_query_arg(
            array( 'pf' => 1, 'tab' => $this->slug, '_n' => wp_create_nonce( 'do-qsot-admin-report-ajax' ) ),
            admin_url( apply_filters( 'qsot-get-menu-page-uri', '', 'main', true ) )
        );
    }

    // handle the subgroup of rows, while running the report. return the number of rows we generated
    protected function _handle_row_group( $group, $csv_file ) {
        // gather all the information that is used to create both csv and html versions of the report, for the found rows
        $data = $this->aggregate_row_data( $group );

        // add this group of results to the csv report
        $this->_csv_render_rows( $data, $csv_file );

        // render the html table rows for this group
        $all_html_rows = $this->_html_report_rows( $data );

        // clean up the memory
        $this->_clean_memory();

        return $all_html_rows;
    }

    // render the group of resulting data as rows for our output table
    protected function _html_report_rows( $group ) {
        $total = 0;
        // get our list of html columns
        $columns = $this->html_report_columns();
        $cnt = count( $columns );

        // cycle through the group of resulting rows, and draw the table row for each
        if ( is_array( $group ) ) foreach ( $group as $row ) {
            $total =+ $this->_html_report_row( $row, $columns, $cnt );
        }

        return $total;
    }

    // generate a filename for the csv for this report
    protected function _csv_filename( $id='', $id_prefix='' ) {
        return 'report-' . $this->slug . ( $id ? '-' . $id_prefix . $id : '' ) . '-' . wp_create_nonce( 'run-report-' . json_encode( $_REQUEST ) ) . '.csv';
    }

    // add the header row to the csv
    protected function _csv_header_row( $file ) {
        $columns = $this->csv_report_columns();
        fputcsv( $file['fd'], array_values( $columns ) );
    }

    // start the csv file
    protected function _open_csv_file( $id='', $id_prefix='', $skip_headers=false ) {
        // get the csv file path. make it if it does not exist yet
        $csv_path = $this->_csv_path();

        // if we could not find or create the csv file path, then bail now
        if ( is_wp_error( $csv_path ) )
            return $csv_path;

        // determine the file path and url
        $basename = $this->_csv_filename( $id, $id_prefix );
        $file = array(
            'path' => $csv_path['path'] . $basename,
            'url' => $csv_path['url'] . $basename,
            'fd' => null,
            'id' => $id,
        );

        // attempt to create a new csv file for this report. if that is successful, then add the column headers and return all the file info now
        if ( $file['fd'] = fopen( $file['path'], 'w+' ) ) {
            if ( ! $skip_headers )
                $this->_csv_header_row( $file );
            return $file;
        }

        // otherwise, bail with an error
        return new WP_Error( 'file_permissions', sprintf( __( 'Could not open the file [%s] for writing. Please verify the file permissions allow writing.', 'opentickets-community-edition' ), $file['path'] ) );
    }

    // close the csv file
    protected function _close_csv_file( $file ) {
        // only try to close open files
        if ( is_array( $file ) && isset( $file['fd'] ) && is_resource( $file['fd'] ) )
            fclose( $file['fd'] );
    }

    // find or create teh csv report file path, and return the path and url of it
    protected function _csv_path() {
        // get all the informaiton about the uploads dir
        $u = wp_upload_dir();
        $u['baseurl'] = trailingslashit( $u['baseurl'] );
        $u['basedir'] = trailingslashit( $u['basedir'] );

        // see if the report cache path already exists. if so, use it in a response now
        if  ( file_exists( $u['basedir'] . 'report-cache/' ) && is_dir( $u['basedir'] . 'report-cache/' ) && is_writable( $u['basedir'] . 'report-cache/' ) )
            return array(
                'path' => $u['basedir'] . 'report-cache/',
                'url' => $u['baseurl'] . 'report-cache/',
            );
        // if the dir exists, but is not writable, then bail with an appropriate error
        elseif (  file_exists( $u['basedir'] . 'report-cache/' ) && is_dir( $u['basedir'] . 'report-cache/' ) && ! is_writable( $u['basedir'] . 'report-cache/' ) )
            return new WP_Error(
                'file_permissions',
                sprintf( __( 'The report cache directory [%s] is not writable. Please update the file permissions to allow writing.', 'opentickets-community-edition' ), $u['basedir'] . 'report-cache/' )
            );
        // if the file exists, but is not a directory, then bail with an appropriate error
        elseif (  file_exists( $u['basedir'] . 'report-cache' ) && ! is_dir( $u['basedir'] . 'report-cache' ) )
            return new WP_Error( 'wrong_file_type', sprintf( __( 'Please remove (or move) the file [%s] and run the report again.', 'opentickets-community-edition' ), $u['basedir'] . 'report-cache' ) );
        // the file does not exist, and we cannot create it
        elseif ( ! file_exists( $u['basedir'] . 'report-cache/' ) && ! is_writable( $u['basedir'] ) )
            return new WP_Error( 'file_permissions', __( 'Could not create a new directory inside your uploads folder. Update the file permissions to allow writing.', 'opentickets-community-edition' ) );

        // at the point the file does not exist, and we have write permissions to create it. do so now. if that fails, error out
        if ( ! mkdir( $u['basedir'] . 'report-cache/', 0777, true ) )
            return new WP_Error( 'file_permissions', __( 'Could not create a new directory inside your uploads folder. Update the file permissions to allow writing.', 'opentickets-community-edition' ) );

        return array(
            'path' => $u['basedir'] . 'report-cache/',
            'url' => $u['baseurl'] . 'report-cache/',
        );
    }

    // draw the report result header, in html form
    protected function _html_report_header( $use_sorter=true ) {
        $sorter = $use_sorter ? 'use-tablesorter' : '';
        // construct the header of the resulting table
        $headerTable = '<table class="widefat '.$sorter.'" cellspacing="0">
				<thead>'.$this->_html_report_columns( true ).'</thead>
				<tbody>';
        echo ($headerTable);
        ?>

        <?php
    }

    // draw the printer friendly footer
    protected function _printer_friendly_footer() {
        ?>
        </div>
        </div>
        </body>
        </html>
        <?php
    }

    // draw the complete printer friendly version
    public function printer_friendly() {
        // if the current user does not have permissions to run the report, then bail
        if ( ! current_user_can( 'view_woocommerce_reports' ) )
            return $this->_error( new WP_Error( 'no_permission', __( 'You do not have permission to use this report.', 'opentickets-community-edition' ) ) );

        // draw the results
        $this->_printer_friendly_header();
        echo $results = $this->_results();
        $this->_printer_friendly_footer();
    }

    // get the order item meta data
    protected function _order_item_meta_from_oiid_list( $oiids ) {
        $wpdb='';
        $rows = array();
        // grab all the meta for the matched order items, if any
        if ( ! empty( $oiids ) ) {
            $meta = $wpdb->get_results( 'select * from ' . $wpdb->prefix . 'woocommerce_order_itemmeta where order_item_id in (' . implode( ',', array_keys( $oiids ) ) . ')' );

            // index all the meta by the order_item_id
            foreach ( $meta as $row ) {
                if ( ! isset( $rows[ $row->order_item_id ] ) )
                    $rows[ $row->order_item_id ] = (object)array( 'order_item_id' => $row->order_item_id, 'order_id' => $oiids[ $row->order_item_id ], $row->meta_key => $row->meta_value );
                else
                    $rows[ $row->order_item_id ]->{ $row->meta_key } = $row->meta_value;
            }
        }

        return $rows;
    }

    // format a number to a specific number of decimals
    public function format_number( $number, $decimals=2 ) {
        $decimals = max( 0, $decimals );
        // create the sprintf format based on the decimals and the currency settings
        $frmt = $decimals ? '%01' . wc_get_price_decimal_separator() . $decimals . 'f' : '%d';

        return sprintf( $frmt, $number );
    }

    // fetch all order meta, indexed by order_id
    protected function _get_order_meta( $order_ids ) {
        // if there are no order_ids, then bail now
        if ( empty( $order_ids ) )
            return array();

        $wpdb='';
        // get all the post meta for all orders
        $all_meta = $wpdb->get_results( 'select * from ' . $wpdb->postmeta . ' where post_id in (' . implode( ',', $order_ids ) . ') order by meta_id desc' );

        $final = array();
        // organize all results by order_id => meta_key => meta_value
        foreach ( $all_meta as $row ) {
            // make sure we have a row for this order_id already
            $final[ $row->post_id ] = isset( $final[ $row->post_id ] ) ? $final[ $row->post_id ] : array();

            // update this meta key with it's value
            $final[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
        }

        return $final;
    }

    // because this can accumulate a lot of memory usage over time, we need to occassionally clear out our internal caches to compensate
    protected function _clean_memory() {
        $wpdb=''; $wp_object_cache='';
        // clear our the query cache, cause it can be huge
        $wpdb->flush();

        // clear out the wp_cache cache, if we are using the core wp method, which is an internal associative array
        if ( isset( $wp_object_cache->cache ) && is_array( $wp_object_cache->cache ) ) {
            unset( $wp_object_cache->cache );
            $wp_object_cache->cache = array();
        }
    }

    // register all the scripts and css that may be used on the basic reporting pages
    public static function register_assets() {
        // reuseable bits
        $url = QSOT::plugin_url();
        $version = QSOT::version();

        // register the js
        wp_register_script( 'qsot-report-ajax', $url . 'assets/js/admin/report/ajax.js', array( 'qsot-tools', 'jquery-ui-datepicker', 'tablesorter' ), $version );
    }

    // tell wordpress to load the assets we previously registered
    public static function load_assets() {
        wp_enqueue_style( 'select2' );
        wp_enqueue_script( 'qsot-report-ajax' );
        wp_localize_script( 'qsot-report-ajax', '_qsot_report_ajax', array(
            '_n' => wp_create_nonce( 'do-qsot-admin-report-ajax' ),
            'str' => array(
                'Loading...' => __( 'Loading...', 'opentickets-community-edition' ),
            ),
        ) );
    }

    // show the report page shell
    public function show_shell() {
        // if the current user does not have permissions to run the report, then bail
        if ( ! current_user_can( 'view_woocommerce_reports' ) )
            return $this->_error( new WP_Error( 'no_permission', __( 'You do not have permission to use this report.', 'opentickets-community-edition' ) ) );

        // draw the shell of the form, and allow the individual report to specify some fields
        ?>
        <div class="report-form" id="report-form"><?php $this->_form() ?></div>
        <div class="report-results" id="report-results"><?php echo $results = $this->_results() ?></div>
        <?php
    }

    // draw the actual form shell, and allow the individual report to control the fields
    protected function _form() {
        ?>
        <form method="post" action="<?php echo esc_attr( remove_query_arg( array( 'updated' ) ) ) ?>" class="qsot-ajax-form">
            <input type="hidden" name="_n" value="<?php echo esc_attr( wp_create_nonce( 'do-qsot-admin-report-ajax' ) ) ?>" />
            <input type="hidden" name="sa" value="<?php echo esc_attr( $this->slug ) ?>" />

            <?php $this->form() ?>
        </form>
        <?php
    }

    // get a very specific piece of order meta from the list of order meta, based on the list, a specific grouping name, and the order id
    protected function _order_meta( $all_meta, $key, $row) {
        // find the order_id from the row
        $order_id = $row->order_id;

        // get the meta for just this one order
        $meta = isset( $all_meta[ $order_id ] ) ? $all_meta[ $order_id ] : false;

        // either piece together specific groupings of meta, or return the exact meta value
        switch ( $key ) {
            default: return isset( $meta[ $key ] ) && '' !== $meta[ $key ] ? $meta[ $key ] : __( '(none)', 'opentickets-community-edition' ); break;

            // a display name for the purchaser
            case 'name':
                $names = array();
                // attempt to use the billing name
                if ( isset( $meta['_billing_first_name'] ) )
                    $names[] = $meta['_billing_first_name'];
                if ( isset( $meta['_billing_last_name'] ) )
                    $names[] = $meta['_billing_last_name'];

                // fall back on the cart identifier
                $names = trim( implode( ' ', $names ) );
                return ! empty( $names ) ? $names : __( '(no-name/guest)', 'opentickets-community-edition' );
                break;

            // the address for the purchaser
            case 'address':
                $addresses = array();
                if ( isset( $meta['_billing_address_1'] ) )
                    $addresses[] = $meta['_billing_address_1'];
                if ( isset( $meta['_billing_address_2'] ) )
                    $addresses[] = $meta['_billing_address_2'];

                $addresses = trim( implode( ' ', $addresses ) );
                return ! empty( $addresses ) ? $addresses : __( '(none)', 'opentickets-community-edition' );
                break;
        }
    }
}