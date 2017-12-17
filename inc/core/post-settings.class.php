<?php

class PostSetting
{
    // handle adjacent_post_link 'where' logic
    public static function adjacent_post_link_where( $where) {
        $post = get_post();
        // only make changes if we are talking about event posts
        if ( self::$o->core_post_type == $post->post_type ) {
            $wpdb='';

            // using start date as the sorter not the post_date
            $start = get_post_meta( $post->ID, '_start', true );
            $format = $wpdb->prepare( 'cast( qspm.meta_value as datetime ) $1 %s AND', $start );
            $where = preg_replace( '#p\.post_date ([^\s]+) .*?AND#', $format, $where );

            // only get child events, if viewing child events, and only get parent events, if viewing parent events
            if ( $post->post_parent ) {
                $where = preg_replace( '#(AND p.post_type = )#', 'AND p.post_parent != 0 \1', $where );
            } else {
                $where = preg_replace( '#(AND p.post_type = )#', 'AND p.post_parent = 0 \1', $where );
            }
        }

        return $where;
    }

    // handle adjacent_post_link 'join' logic
    public static function adjacent_post_link_join( $join) {
        $post = get_post();
        // only make changes if we are talking about event posts
        if ( self::$o->core_post_type == $post->post_type ) {
            $wpdb='';
            // using start date as the sorter not the post_date
            $join .= $wpdb->prepare( ' inner join ' . $wpdb->postmeta . ' as qspm on qspm.post_id = p.ID AND qspm.meta_key = %s', '_start' );
        }

        return $join;
    }

    // handle adjacent_post_link 'sort' logic
    public static function adjacent_post_link_sort( $orderby ) {
        $post = get_post();
        // only make changes if we are talking about event posts
        if ( self::$o->core_post_type == $post->post_type ) {
            $orderby = preg_replace( '#ORDER BY .*? ([^\s]+) LIMIT#', 'ORDER BY cast( qspm.meta_value as datetime ) \1 LIMIT', $orderby );
        }

        return $orderby;
    }

    // add the event name (and possibly date/time) to the order items in the cart
    public static function add_event_name_to_cart( $list, $item ) {
        // if we have an event to display the name of
        if ( isset( $item['event_id'] ) ) {
            // load the event
            $event = apply_filters( 'qsot-get-event', false, $item['event_id'] );

            // if the vent actually exists, then
            if ( is_object( $event ) ) {
                // add the event label to the list of meta data to display for this cart item
                $list[] = array(
                    'name' => __( 'Event', 'opentickets-community-edition' ),
                    'display' => sprintf( // add event->ID param so that date/time can be added appropriately
                        '<a href="%s" title="%s">%s</a>',
                        get_permalink( $event->ID ),
                        __( 'View this event', 'opentickets-community-edition' ),
                        apply_filters( 'the_title', $event->post_title, $event->ID )
                    ),
                );
            }
        }

        return $list;
    }

    public static function add_event_name_to_emails($item) {
        $format = '<br/><small><strong>';
        if (!isset($item['event_id']) || empty($item['event_id'])) return;
        $event = apply_filters('qsot-get-event', false, $item['event_id']);
        if (!is_object($event)) return;
        $strOpTc = sprintf(
            $format . __( 'Event', 'opentickets-community-edition' ) . '</strong>: <a class="event-link" href="%s" target="_blank" title="%s">%s</a></small>',
            get_permalink( $event->ID ),
            __('View this event','opentickets-community-edition'),
            apply_filters( 'the_title', $event->post_title, $event->ID )
        );
        echo $strOpTc;
    }

    public static function patch_menu() {
        $menu=''; $submenu='';

        foreach ($menu as $ind => $mitem) {
            if (isset($mitem[5]) && $mitem[5] == 'menu-posts-'.self::$o->core_post_type) {
                $key = $menu[$ind][2];
                $new_key = $menu[$ind][2] = add_query_arg(array('post_parent' => 0), $key);
                if (isset($submenu[$key])) {
                    $submenu[$new_key] = $submenu[$key];
                    unset($submenu[$key]);
                    foreach ($submenu[$new_key] as $sind => $sitem) {
                        if ($sitem[2] == $key) {
                            $submenu[$new_key][$sind][2] = $new_key;
                            break;
                        }
                    }
                }
                break;
            }
        }
    }

    public static function patch_menu_second_hack() {
        $parent_file='';

        if ($parent_file == 'edit.php?post_type='.self::$o->core_post_type) $parent_file = add_query_arg(array('post_parent' => 0), $parent_file);
    }

    // adjust timestamp for time offset
    protected static function _offset( $ts, $dir=1 ) { return $ts + ( $dir * get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ); }

    // determine the offset description string
    protected static function _offset_str() {
        // get the offset
        $offset = get_option( 'gmt_offset', 0 );

        // get the sign of the offset
        $sign = $offset < 0 ? '-' : '+';

        // get the suffix, either :00 or :30
        $offset = abs( $offset );
        $floored = floor( $offset );
        $suffix = $offset == $floored ? ':00' : ':30';

        return sprintf( '%s%02s%s', $sign, $offset, $suffix );
    }

    public static function intercept_event_list_page() {
        if (isset($_GET['post_type']) && $_GET['post_type'] == self::$o->core_post_type) {
            add_action('pre_get_posts', array(__CLASS__, 'add_post_parent_query_var'), 10, 1);
        }
    }

    public static function add_post_parent_query_var(&$q) {
        if (isset($_GET['post_parent'])) {
            $q->query_vars['post_parent'] = $_GET['post_parent'];
        }
    }

    public static function post_columns($columns) {
        if (isset($_GET['post_parent']) && $_GET['post_parent'] == 0) {
            add_action('manage_'.self::$o->core_post_type.'_posts_custom_column', array(__CLASS__, 'post_columns_contents'), 10, 2);
            $final = array();
            foreach ($columns as $col => $val) {
                $final[$col] = $val;
                if ($col == 'title') $final['child-event-count'] = __('Events','opentickets-community-edition');
            }
            $columns = $final;
        }

        return $columns;
    }

    public static function post_columns_contents($column, $post_id) {
        $wpdb='';

        switch ($column) {
            case 'child-event-count':
                $total = (int)$wpdb->get_var($wpdb->prepare('select count(id) from '.$wpdb->posts.' where post_parent = %d and post_type = %s', $post_id, self::$o->core_post_type));
                echo $total;
                break;
            default:
                $def="null";echo $def;
        }
    }

    public static function adjust_post_list_views($views) {
        $post_counts = self::_count_posts();
        $post_counts["0"] = isset($post_counts["0"]) && is_numeric($post_counts["0"]) ? $post_counts["0"] : 0;
        $current = isset($_GET['post_parent']) && $_GET['post_parent'] == 0 ? ' class="current"' : '';

        $new_views = array(
            'only-parents' => sprintf(
                '<a href="%s"'.$current.'>%s (%d)</a>',
                'edit.php?post_type='.self::$o->core_post_type.'&post_parent=0',
                __('Top Level Events','opentickets-community-edition'),
                $post_counts["0"]
            ),
        );

        foreach ($views as $slug => $view) {
            $new_views[$slug] = $current ? preg_replace('#(class="[^"]*)current([^"]*")#', '\1\2', $view) : $view;
        }

        return $new_views;
    }

    protected static function _count_posts() {
        $wpdb='';

        $return = array();
        $res = $wpdb->get_results($wpdb->prepare('select post_parent, count(post_type) as c from '.$wpdb->posts.' where post_type = %s group by post_parent', self::$o->core_post_type));
        foreach ($res as $row) $return["{$row->post_parent}"] = $row->c;

        return $return;
    }

    public static function enable_social_sharing($list) {
        $list[] = self::$o->core_post_type;
        return array_filter(array_unique($list));
    }

    // when doing a wp_query, we need to check if some of our special query args are present, and adjust the params accordingly
    public static function adjust_wp_query_vars( &$query ) {
        $qv = wp_parse_args( $query->query_vars, array( 'start_date_after' => '', 'start_date_before' => '' ) );
        // if either the start or end date is present, then ...
        if ( ! empty( $qv['start_date_after'] ) || ! empty( $qv['start_date_before'] ) ) {
            $query->query_vars['meta_query'] = isset( $query->query_vars['meta_query'] ) && is_array( $query->query_vars['meta_query'] ) ? $query->query_vars['meta_query'] : array( 'relation' => 'OR' );

            // if both the start and end dates are present, then add a meta query for between
            if ( ! empty( $qv['start_date_after'] ) && ! empty( $qv['start_date_before'] ) ) {
                $query->query_vars['meta_query'][] = array( 'key' => '_start', 'value' => array( $qv['start_date_after'], $qv['start_date_before'] ), 'compare' => 'BETWEEN', 'type' => 'DATETIME' );
                // otherwise, if only the start date is present, then add a rule for that
            } else if ( ! empty( $qv['start_date_after'] ) ) {
                $query->query_vars['meta_query'][] = array( 'key' => '_start', 'value' => $qv['start_date_after'], 'compare' => '>=', 'type' => 'DATETIME' );
                // otherwise, only the end rule can be present, so add a rule for that
            } else {
                $query->query_vars['meta_query'][] = array( 'key' => '_start', 'value' => $qv['start_date_before'], 'compare' => '<=', 'type' => 'DATETIME' );
            }
        }
    }

    public static function wp_query_orderby_meta_value_date($orderby, $query) {
        if (
            isset($query->query_vars['orderby'], $query->query_vars['meta_key'])
            && $query->query_vars['orderby'] == 'meta_value_date'
            && !empty($query->query_vars['meta_key'])
        ) {
            $order = strtolower(isset($query->query_vars['order']) ? $query->query_vars['order'] : 'asc');
            $order = in_array($order, array('asc', 'desc')) ? $order : 'asc';
            $orderby = 'cast(mt1.meta_value as datetime) '.$order;
        }
        return $orderby;
    }

    public static function events_query_where($where, $q) {
        $wpdb='';

        if (isset($q->query_vars['post_parent__not_in']) && !empty($q->query_vars['post_parent__not_in'])) {
            $ppni = $q->query_vars['post_parent__not_in'];
            if (is_string($ppni)) $ppni = preg_split('#\s*,\s*', $ppni);
            if (is_array($ppni)) {
                $where .= ' AND ('.$wpdb->posts.'.post_parent not in ('.implode(',', array_map('absint', $ppni)).') )';
            }
        }

        if (isset($q->query_vars['post_parent__in']) && !empty($q->query_vars['post_parent__in'])) {
            $ppi = $q->query_vars['post_parent__in'];
            if (is_string($ppi)) $ppi = preg_split('#\s*,\s*', $ppi);
            if (is_array($ppi)) {
                $where .= ' AND ('.$wpdb->posts.'.post_parent in ('.implode(',', array_map('absint', $ppi)).') )';
            }
        }

        if (isset($q->query_vars['post_parent__not']) && $q->query_vars['post_parent__not'] !== '') {
            $ppn = $q->query_vars['post_parent__not'];
            if (is_scalar($ppn)) {
                $where .= $wpdb->prepare(' AND ('.$wpdb->posts.'.post_parent != %s) ', $ppn);
            }
        }

        return $where;
    }

    public static function events_query_fields($fields) {
        return $fields;
    }

    public static function events_query_orderby($orderby, $q) {

        if (isset($q->query_vars['special_order']) && strlen($q->query_vars['special_order'])) {
            //$orderby = preg_split('#\s*,\s*#', $orderby);
            $orderby = $q->query_vars['special_order'];
            //$orderby = implode(', ', $orderby);
        }

        return $orderby;
    }

    // add the event metadata to event type posts, preventing the need to call this 'meta addtion' code elsewhere
    public static function the_posts_add_meta( $posts) {
        foreach ( $posts as $i => $post ) {
            if ( $post->post_type == self::$o->core_post_type ) {
                $posts[ $i ] = apply_filters( 'qsot-event-add-meta', $post, $post->ID );
            }
        }

        return $posts;
    }

    public static function get_event($current, $event_id) {
        $event = get_post($event_id);

        if (is_object($event) && isset($event->post_type) && $event->post_type == self::$o->core_post_type) {
            $event->parent_post_title = get_the_title( $event->post_parent );
            $event = apply_filters('qsot-event-add-meta', $event, $event_id);
        } else {
            $event = $current;
        }

        return $event;
    }

    // determine the availability of an event
    public static function get_availability( $count=0, $event_id=0 ) {
        // normalize the event_id to anumber
        if ( is_object( $event_id ) )
            $event_id = $event_id->ID;

        // use the global post if the event_id is not supplied
        if ( ! is_numeric( $event_id ) || $event_id <= 0 )
            $event_id = isset( $post ) && is_object( $post ) ? $post->ID : $event_id;

        // bail if the event id does not exist
        if ( ! is_numeric( $event_id ) || $event_id <= 0 )
            return $count;

        $ea_id = intval( get_post_meta( $event_id, '_event_area_id', true ) );
        $capacity = intval( get_post_meta( $ea_id, '_capacity', true ) );
        // fetch the total number of reservations for this event
        $purchases = intval( get_post_meta( $event_id, '_purchases_ea', true ) );

        return $capacity - $purchases;
    }
}