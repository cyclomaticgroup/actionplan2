<?php

class PostUtils
{
    // maybe prevent editing the quantity of tickets in the cart, based on settings
    public static function maybe_prevent_ticket_quantity_edit( $current, $cart_item=array() ) {
        // figure out the limit for this event
        $limit = isset( $cart_item['event_id'] ) ? apply_filters( 'qsot-event-ticket-purchase-limit', 0, $cart_item['event_id'] ) : 0;

        // there are two conditions when the quantity should not be editable:
        // 1) if the settings lock the user into keeping the quantity they initially selected
        // 2) if the purchase limit of the tickets is set to 1, meaning if it is in the cart, they are at the limit
        if ( 1 !== intval( $limit ) && 'no' == apply_filters( 'qsot-get-option-value', 'no', 'qsot-locked-reservations' ) )
            return $current;

        // check if this is a ticket. if not bail
        $product = wc_get_product( $cart_item['product_id'] );
        if ( ! is_object( $product ) || 'yes' != $product->ticket )
            return $current;

        // at this point, we need to restrict editing. so just return what to show in the column
        return '<div style="text-align:center;">' . $cart_item['quantity'] . '</div>';
    }

    public static function order_item_id_to_order_id($order_id, $order_item_id) {
        static $cache = array();

        if (!isset($cache["{$order_id}"])) {
            $wpdb='';
            $q = $wpdb->prepare('select order_id from '.$wpdb->prefix.'woocommerce_order_items where order_item_id = %d', $order_item_id);
            $cache["{$order_id}"] = (int)$wpdb->get_var($q);
        }

        return $cache["{$order_id}"];
    }

    public static function cascade_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (empty($html) || empty($post_thumbnail_id)) {
            $post = get_post($post_id);
            if (is_object($post) && isset($post->post_type) && $post->post_type == self::$o->core_post_type && !empty($post->post_parent) && $post->post_parent != $post->ID) {
                $html = get_the_post_thumbnail($post->post_parent, $size, $attr);
            }
        }

        return $html;
    }

    public static function template_include($template) {
        if (is_singular(self::$o->core_post_type)) {
            $post = get_post();
            $files = array(
                'single-'.self::$o->core_post_type.'.php',
            );
            if ($post->post_parent != 0) array_unshift($files, 'single-'.self::$o->core_post_type.'-child.php');

            $tmpl = apply_filters('qsot-locate-template', '', $files);
            if (!empty($tmpl)) $template = $tmpl;
        }

        return $template;
    }

    // always register our scripts and styles before using them. it is good practice for future proofing, but more importantly, it allows other plugins to use our js if needed.
    // for instance, if an external plugin wants to load something after our js, like a takeover js, they will have access to see our js before we actually use it, and will
    // actually be able to use it as a dependency to their js. if the js is not yet declared, you cannot use it as a dependency.
    public static function register_assets() {
        $calendar = qsot_frontend_calendar::load_calendar_language();
        // main event ui js. combines all the moving parts to make the date/time selection process more user friendly than other crappy event plugins
        wp_register_script('qsot-event-ui', self::$o->core_url.'assets/js/admin/event-ui.js', array('qsot-tools', $calendar), self::$o->version);
        // initialization js. initializes all the moving parts. called at the top of the edit event page
        wp_register_script('qsot-events-admin-edit-page', self::$o->core_url.'assets/js/admin/edit-page.js', array('qsot-event-ui', 'jquery-ui-datepicker'), self::$o->version);
        // general additional styles for the event ui interface
        wp_register_style('qsot-admin-styles', self::$o->core_url.'assets/css/admin/ui.css', array('qsot-jquery-ui'), self::$o->version);
        // ajax js
        wp_register_script('qsot-frontend-ajax', self::$o->core_url.'assets/js/utils/ajax.js', array('qsot-tools'), self::$o->version);
    }

    public static function load_frontend_assets() {
        if (is_singular(self::$o->core_post_type) && ($post = get_post()) && $post->post_parent != 0) {
            do_action('qsot-frontend-event-assets', $post);
        }
    }

    // save function for the parent events
    public static function save_event($post ) {
        if ( $post->post_type != self::$o->core_post_type ) return; // only run for our event post type
        if ( $post->post_parent != 0 ) return; // this is only for parent event posts

        // if there were settings for the sub events sent, then process those settings, on the next action
        // on next action because apparently recursive calls to save_post action causes the outermost loop to skip everything after the function that caused the recursion
        if ( isset( $_POST['_qsot_event_settings'] ) )
            add_action( 'wp_insert_post', array( __CLASS__, 'save_sub_events' ), 100, 3 );

        // if the 'show date' and 'show time' settings are present, update them as needed, on the next action
        // on next action because apparently recursive calls to save_post action causes the outermost loop to skip everything after the function that caused the recursion
        if ( isset( $_POST['qsot-event-title-settings'] ) && wp_verify_nonce( $_POST['qsot-event-title-settings'], 'qsot-event-title' ) )
            add_action( 'wp_insert_post', array( __CLASS__, 'save_event_title_settings' ), 100, 3 );
    }

    // on event update, record the old slug if needed
    public static function record_old_slug( $post_id, $post, $post_before ) {
        // Don't bother if it hasn't changed.
        if ( $post->post_name == $post_before->post_name ) {
            return;
        }

        // We're only concerned with published, non-hierarchical objects.
        if ( 'qsot-event' !== $post->post_type || ! ( 'publish' === $post->post_status || ( 'attachment' === get_post_type( $post ) && 'inherit' === $post->post_status ) ) ) {
            return;
        }

        $old_slugs = (array) get_post_meta( $post_id, '_wp_old_slug' );
        $parent = get_post( $post_before->post_parent );
        $old_slug = $parent->post_name . '/' . $post_before->post_name;
        $new_slug = $parent->post_name . '/' . $post->post_name;

        // If we haven't added this old slug before, add it now.
        if ( ! empty( $post_before->post_name ) && ! in_array( $old_slug, $old_slugs ) ) {
            add_post_meta( $post_id, '_wp_old_slug', $old_slug );
        }

        // If the new slug was used previously, delete it from the list.
        if ( in_array( $new_slug, $old_slugs ) ) {
            delete_post_meta( $post_id, '_wp_old_slug', $new_slug );
        }
    }

    // handle the saving of sub events, when a parent event is saved in the admin
    public static function save_sub_events( $post_id ) {
        remove_action( 'wp_insert_post', array( __CLASS__, 'save_sub_events' ), 100 );
        $data = $_POST;

        unset( $data['_qsot_event_settings']['count'] );
        // expand the json data

        foreach ( $data['_qsot_event_settings'] as $ind => $item )
            $data['_qsot_event_settings'][ $ind ] = json_decode( stripslashes( $item ) );

        // patch to prevent simple fields from overwriting child event field data
        if ( isset( $_POST['simple_fields_nonce'] ) ) {
            $_POST['_simple_fields_nonce'] = $_POST['simple_fields_nonce'];
            unset( $_POST['simple_fields_nonce'] );
        }

        // actually save the sub-events
        do_action( 'qsot-save-sub-events', $post_id, $data, current_user_can( 'publish_posts' ) );

        // restore simple fields save patch to original state
        if ( isset( $_POST['_simple_fields_nonce'] ) ) {
            $_POST['simple_fields_nonce'] = $_POST['_simple_fields_nonce'];
            unset( $_POST['_simple_fields_nonce'] );
        }
    }

    public static function permalink_settings_page() {
        self::_maybe_save_permalink_settings();
        $wp_settings_sections='';

        $wp_settings_sections['permalink']['opentickets-permalink'] = array(
            'id' => 'opentickets-permalink',
            'title' => __('Event permalink base','opentickets-community-edition'),
            'callback' => array(
                __CLASS__,
                'add_permalinks_settings_page_settings',
            ),
        );
    }

    protected static function _maybe_save_permalink_settings() {
        if ( isset( $_POST['qsot_event_permalinks_settings'] ) && wp_verify_nonce( $_POST['qsot_event_permalinks_settings'], 'qsot-permalink-settings' ) ) {
            update_option( 'qsot_event_permalink_slug', $_POST['qsot_event_permalink_slug'] );
        }
    }

    // work around for non-page hierarchical post type 'default permalink' bug i found - loushou
    // https://core.trac.wordpress.org/ticket/29615
    public static function qsot_event_link($permalink, $post, $leavename, $sample) {
        $post_type = get_post_type_object($post->post_type);

        if (!$post_type->hierarchical) return $permalink;

        // copied and slightly modified to actually work with WP_Query() from wp-includes/link-template.php @ get_post_permalink()
        $wp_rewrite='';

        $post_link = $wp_rewrite->get_extra_permastruct($post->post_type);
        $draft_or_pending = isset($post->post_status) && in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ) );
        $slug = get_page_uri($post->ID);

        if ( !empty($post_link) && ( !$draft_or_pending || $sample ) ) {
            if ( ! $leavename )
                $post_link = str_replace("%$post->post_type%", $slug, $post_link);
            $post_link = home_url( user_trailingslashit($post_link) );
        } else {
            if ( $post_type->query_var && ( isset($post->post_status) && !$draft_or_pending ) )
                $post_link = add_query_arg($post_type->query_var, $slug, '');
            else
                $post_link = add_query_arg(array('post_type' => $post->post_type, 'p' => $post->ID), '');
            $post_link = home_url($post_link);
        }

        return $post_link;
    }

    // figure out the timestamp of when to stop selling tickets for a given event, based on the event settings and the global settings. then determine if we are past that cut off or not
    public static function check_event_sale_time( $event_id ) {
        // grab the value stored on the event
        $formula = get_post_meta( $event_id, '_stop_sales_before_show', true );

        // if there is no value stored on the event, or it is zero, then try to load the setting on the parent event, or the global setting
        if ( empty( $formula ) ) {
            // get the event post
            $post = get_post( $event_id );
            $parent_id = $post->post_parent;

            // try to load it from the parent event
            if ( $parent_id )
                $formula = get_post_meta( $parent_id, '_stop_sales_before_show', true );

            // if we still have no formula, then load the global setting
            if ( empty( $formula ) )
                $formula = apply_filters( 'qsot-get-option-value', '', 'qsot-stop-sales-before-show' );
        }

        // grab the start time of the event, so that we can use it in the timestamp calc
        $start = get_post_meta( $event_id, '_start', true );

        // determine if now() + time offset, is still less than the beginning of the show
        $stime = strtotime( $start );
        $offset = strtotime( $formula, 0 );
        $time = time();

        // are we past it or not?
        return $time < $stime - $offset;
    }

    // check to see if we are past the hardstop date for sales on this event
    public static function check_event_sale_time_hard_stop( $current, $event_id ) {
        // only do this check if the value is true currently
        if ( ! $current )
            return $current;

        // get the hardstop time
        $hard_stop = get_post_meta( $event_id, '_stop_sales_hard_stop', true );

        // if the individual event does not have a hardstop set, check the parent
        if ( empty( $hard_stop ) ) {
            // get the event post
            $post = get_post( $event_id );
            $parent_id = $post->post_parent;

            // try to load it from the parent event
            if ( $parent_id )
                $hard_stop = get_post_meta( $parent_id, '_stop_sales_hard_stop', true );
        }

        // if there is still no hardstop, then bail
        if ( empty( $hard_stop ) )
            return $current;

        // get the stop time
        $stop_time = strtotime( $hard_stop );
        if ( false == $stop_time )
            return $current;

        // get the current time
        $time = current_time('timestamp');

        // determine if the current time is still before the hardstop
        return $time < $stop_time;
    }

    // on the frontend, lets show the parent events in category and tag pages
    public static function events_in_categories_and_tags( $q ) {
        // if the option to show the parent envets on the homepage is not checked, then do not modify the query with this function
        if ( 'yes' !== self::$options->{'qsot-events-on-homepage'} )
            return $q;

        // alias the query vars to a shorter variable name (not required)
        $v = $q->query_vars;

        // do not make any changes to the query, if a specific POST
        // has been requested
        if ( ( isset( $v['name'] ) && ! empty( $v['name'] ) ) || ( isset( $v['p'] ) && ! empty( $v['p'] ) ) )
            return $q;

        // do not make any changes to the query, if a specific PAGE
        // has been requested
        if ( ( isset( $v['pagename'] ) && ! empty( $v['pagename'] ) ) || ( isset( $v['page_id'] ) && ! empty( $v['page_id'] ) ) )
            return $q;

        // when not in the admin, and processing the main page query
        if ( ! is_admin() && $q->is_main_query() ) {
            // if the list of post types was not supplied, and this is the homepage, then create one that uses 'post' and 'qsot-event' (event post type)
            if ( ( is_home() || is_front_page() ) && ( ! isset( $v['post_type'] ) || empty( $v['post_type'] ) ) ) {
                // make sure that the home page generic queries add events the list of post types to display
                $v['post_type'] = array( 'post', 'qsot-event' );

                // only show parent events. this has the unfortunate side effect of limiting other post types to parents only too... but this should only conflict with very very few plugins, and nothing core WP
                $v['post_parent'] = isset( $v['post_parent'] ) && ! empty( $v['post_parent'] ) ? $v['post_parent'] : '';
                // if the post type list is set and 'post' is the only type specified, then add the event post type to the list of possible post types to query for
            } else if ( isset( $v['post_type'] ) && ( $types = array_filter( (array)$v['post_type'] ) ) && 1 == count( $types ) && in_array( 'post', $types ) ) {
                $v['post_type'] = $types;
                $v['post_type'][] = 'qsot-event';
            }
        }

        // reassign the query vars back to the long name
        $q->query_vars = $q->query = $v;

        return $q;
    }

    // insert the event synopsis into the post content of the child events, so it is displayed on the individual event pages, when the synopsis options are turned on
    public static function the_content( $content ) {
        // if this is not a single event page, then bail now
        if ( ! is_singular( self::$o->core_post_type ) )
            return $content;

        // get the event post
        $post = get_post();

        // if the post has a password, then require it
        if ( post_password_required( $post ) )
            return $content;

        // if this is a child event post, then ...
        if ( ( $event = get_post() ) && is_object( $event ) && $event->post_type == self::$o->core_post_type && $event->post_parent != 0 ) {
            // if we are supposed to show the synopsis, then add it
            if ( self::$options->{'qsot-single-synopsis'} && 'no' != self::$options->{'qsot-single-synopsis'} ) {
                // emulate that the 'current post' is actually the parent post, so that we can run the the_content filters, without an infinite recursion loop

                $p = clone $post;
                $post = get_post( $event->post_parent );
                setup_postdata( $post );

                // get the parent post content, and pass it through the appropriate filters for texturization
                $content = apply_filters( 'the_content', get_the_content() );

                // restore the original post
                $post = $p;
                setup_postdata( $post );
            }

            // inform other classes and plugins of our new content
            $content = apply_filters( 'qsot-event-the-content', $content, $event );
        }

        return $content;
    }

    // determine a language equivalent for describing the number of remaining tickets
    public static function get_availability_text( $current, $available, $event_id=null ) {
        // normalize the args
        if ( null === $event_id && self::$o->core_post_type == get_post_type() )
            $event_id = get_the_ID();
        if ( null === $event_id )
            return $current;
        $available = max( 0, (int)$available );

        // get the capacity and calculate the ratio of remaining tickets
        $capacity = max( 0, (int)get_post_meta( $event_id, self::$o->{'meta_key.capacity'}, true ) );
        $percent = 100 * ( ( $capacity ) ? $available / $capacity : 1 );
        // always_reserve is the number of tickets kept as a buffer, usually reserved for staff, but occassionally used as an overflow buffer for high selling events
        $adjust = 100 * ( ( $capacity ) ? self::$o->always_reserve / $capacity : 0.5 );

        // if the event is sold
        if ( $percent <= apply_filters( 'qsot-availability-threshold-sold-out', $adjust ) )
            $current = __( 'sold-out', 'opentickets-community-edition' );
        // if the event is less than 30% available, then it is low availability
        else if ( $percent < apply_filters( 'qsot-availability-threshold-low', 30 ) )
            $current = __( 'low', 'opentickets-community-edition' );
        // if the event is less than 65% available but more than 29%, then it is low availability
        else if ( $percent < apply_filters( 'qsot-availability-threshold-low', 65 ) )
            $current = __( 'medium', 'opentickets-community-edition' );
        // otherwise, the number of sold tickets so far is inconsequential, so the availability is 'high'
        else
            $current = __( 'high', 'opentickets-community-edition' );

        return $current;
    }

    // figure out the current ticket purchasing limit for this event
    public static function event_ticket_purchasing_limit( $current, $event_id ) {
        // first, check the specific event, and see if there are settings specificly limiting it's purchase limit. if there is one, then use it
        $elimit = intval( get_post_meta( $event_id, self::$o->{'meta_key.purchase_limit'}, true ) );
        if ( $elimit > 0 )
            return $elimit;
        // if the value is negative, then this event specifically has no limit
        else if ( $elimit < 0 )
            return 0;

        // next check the parent event. if there is a limit there, then use it
        $event = get_post( $event_id );
        if ( is_object( $event ) && isset( $event->post_parent ) && $event->post_parent > 0 ) {
            $elimit = intval( get_post_meta( $event->post_parent, self::$o->{'meta_key.purchase_limit'}, true ) );
            if ( $elimit > 0 )
                return $elimit;
            // if the value is negative, then this event specifically, and all child events, are supposed to have no limit
            else if ( $elimit < 0 )
                return $elimit;
        }

        // as a last ditch effort, try to find the global setting and use it
        $elimit = apply_filters( 'qsot-get-option-value', 0, 'qsot-event-purchase-limit' );
        if ( $elimit > 0 )
            return $elimit;

        return $current;
    }

    public static function add_meta($event) {
        if (is_object($event) && isset($event->ID, $event->post_type) && $event->post_type == self::$o->core_post_type) {
            $km = self::$o->meta_key;
            $m = array();
            $meta = get_post_meta($event->ID);
            foreach ($meta as $k => $v) {
                if (($pos = array_search($k, $km)) !== false) $k = $pos;
                $m[$k] = maybe_unserialize(array_shift($v));
            }

            // get the proper capacity from the event_area
            if ( isset( $m['_event_area_id'] ) && intval( $m['_event_area_id'] ) > 0 ) {
                $m['_event_area_id'] = intval( $m['_event_area_id'] );
                $m['capacity'] = get_post_meta( $m['_event_area_id'], '_capacity', true );
            } else {
                $m['_event_area_id'] = 0;
            }

            $m = wp_parse_args($m, array('purchases' => 0, 'capacity' => 0));
            $m['available'] = apply_filters( 'qsot-get-availability', 0, $event->ID );
            $m['availability'] = apply_filters( 'qsot-get-availability-text', __( 'available', 'opentickets-community-edition' ), $m['available'], $event->ID );
            $m = apply_filters('qsot-event-meta', $m, $event, $meta);
            if (isset($m['_event_area_obj'], $m['_event_area_obj']->ticket, $m['_event_area_obj']->ticket->id))
                $m['reserved'] = apply_filters('qsot-zoner-owns', 0, $event, $m['_event_area_obj']->ticket->id, self::$o->{'z.states.r'});
            else
                $m['reserved'] = 0;
            $event->meta = (object)$m;

            $image_id = get_post_thumbnail_id($event->ID);
            $image_id = empty($image_id) ? get_post_thumbnail_id($event->post_parent) : $image_id;
            $event->image_id = $image_id;
        }

        return $event;
    }

    // find an appropriate thumbnail based on the supplied info
    public static function cascade_thumbnail_id( $current, $object_id, $key, $single ) {
        // if we are not looking up the thumbnail_id, bail immediately
        if ( '_thumbnail_id' !== $key )
            return $current;

        // if the thumb was already found, bail now
        if ( $current )
            return $current;

        static $map = array();

        // if we have not looked up the post type for the supplied object_id yet, then look it up now
        if ( ! isset( $map[ $object_id . '' ] ) ) {
            $obj = get_post( $object_id );
            // if the post was loaded
            if ( is_object( $obj ) && ! is_wp_error( $obj ) && $obj->ID == $object_id )
                $map[$object_id.''] = $obj->post_type;
            // otherwise, cache something at least so we dont keep looking it up
            else
                $map[ $object_id . '' ] = '_unknown_post_type';
        }

        // if the supplied object is an event, and it is not a parent event, then...
        if ( $map[ $object_id . '' ] == self::$o->core_post_type && $key == '_thumbnail_id' && ( $parent_id = wp_get_post_parent_id( $object_id ) ) && $parent_id != $object_id ) {
            // prevent weird recursion
            remove_filter( 'get_post_metadata', array( __CLASS__, 'cascade_thumbnail_id' ), 10 );

            // lookup this event's thumb
            $this_value = get_post_meta( $object_id, $key, $single );

            // restore thumbnail cascade
            add_filter( 'get_post_metadata', array( __CLASS__, 'cascade_thumbnail_id' ), 10, 4 );

            // if we did not find a thumb for this specific event, try to lookup the parent event's thumb
            if ( empty( $this_value ) )
                $current = get_post_meta( $parent_id, $key, $single );
            else
                $current = $this_value;
        }

        return $current;
    }
}