<?php

class PostUtility
{
    // when order_again is hit, items are discretely added to the new cart. during that process, sniff out any tickets, and add them to the cart a different way
    public function sniff_order_again_and_readd_to_cart( $passes_validation, $product_id, $quantity, $variation_id=0, $variations='', $cart_item_data=array() ) {
        // if the marker is not present, then pass through
        if ( ! isset( $cart_item_data['_order_again'] ) )
            return $passes_validation;

        unset( $cart_item_data['_order_again'] );
        // otherwise, attempt to add the ticket to the cart via our ticket selection logic, instead of the standard reorder way
        $res = apply_filters( 'qsot-order-again-add-to-cart-pre', null, $product_id, $quantity, $variation_id, $variations, $cart_item_data );

        // if another plugin has not done it's own logic here, then perform the default logic
        if ( null === $res ) {
            $res = apply_filters( 'qsot-zoner-reserve-current-user', false, $cart_item_data['_event_id'], $product_id, $quantity );
        }

        // if the results are a wp_error, then add that as a notice
        if ( is_wp_error( $res ) ) {
            foreach ( $res->get_error_codes() as $code )
                foreach ( $res->get_error_messages( $code ) as $msg )
                    wc_add_notice( $msg, 'error' );
        }

        return false;
    }

    // during page load of the edit event page, we need to load all the data about the child events. this will add the event_area data to the child event
    public function load_child_event_settings( $settings, $event ) {
        // if we know the event to set the data on, then...
        if ( is_object( $event ) && isset( $event->ID ) ) {
            // load the event area id that is currently set for this sub event
            $ea_id = get_post_meta( $event->ID, '_event_area_id', true);

            // add it to the list of data that is used on the frontend
            $settings['event-area'] = (int)$ea_id;

            // if we found an event_area, then also add the capacity to the data, for possible use
            if ( $ea_id )
                $settings['capacity'] = get_post_meta( $ea_id, '_capacity', true );
        }

        return $settings;
    }

    // during the editing of an order in the admin (new or existing), we may need to add/change ticket reservations. to do this, we need to have some js templates to help. this function aggregates them
    public function admin_ticket_selection_templates( $list, $exists, $order_id ) {
        // create a list of args to send to the loaded templates
        $args = array( 'list' => $list, 'exists' => $exists, 'order_id' => $order_id );

        // load the generic templates
        $list['dialog-shell'] = QSOT_Templates::maybe_include_template( 'admin/ticket-selection/dialog-shell.php', $args );
        $list['transition'] = QSOT_Templates::maybe_include_template( 'admin/ticket-selection/transition.php', $args );

        // aggregate all the templates from each of the known area_types
        foreach ( $this->area_types as &$area_type )
            $list = $area_type->get_admin_templates( $list, 'ticket-selection', $args );

        return $list;
    }

    // function to update the purchases on events that recently had tickets released
    public function delete_order_item_update_event_purchases( ) {
        // if there were events with removed tickets, then recalc the purchased tickets
        if ( ! empty( $this->event_ids_with_removed_tickets ) )
            foreach ( $this->event_ids_with_removed_tickets as $event_id => $_ )
                do_action( 'qsot-update-event-purchases', $event_id );
    }

    // load the event area information and attach it to the ticket information. used when rendering the ticket
    public function add_event_area_data( $current) {
        // skip this function if the ticket has not already been loaded, or if it is a wp error
        if ( ! is_object( $current ) || is_wp_error( $current ) )
            return $current;

        // also skip this function if the event info has not been loaded
        if ( ! isset( $current->event, $current->event->ID ) )
            return $current;

        // move the event area object to top level scope so we dont have to dig for it
        $current->event_area = apply_filters( 'qsot-event-area-for-event', false, $current->event );
        if ( isset( $current->event_area->area_type) && is_object( $current->event_area->area_type ) )
            $current = $current->event_area->area_type->compile_ticket( $current );

        return $current;
    }

    // any time that the total purchases for an event change, we need to update the cached purchase number in the datebase
    public function update_event_purchases( $event_id ) {
        // get the query tool used to calc the total
        $query = QSOT_Zoner_Query::instance();

        // get the list of stati that are considered completed purchases
        $stati = array( 'confirmed', 'occupied' );
        /* @TODO: get this list dynamically from all area_types */

        // get the total number of purchases for the event
        $total = $query->find( array( 'event_id' => $event_id, 'state' => $stati, 'fields' => 'total' ) );

        // update the value in the db
        update_post_meta( $event_id, '_purchases_ea', $total );
    }

    // during the saving of an event, auto recalc the purchases
    public function save_post_update_event_purchases( $post_id, $post ) {
        // if this post is not an event, then bail
        if ( 'qsot-event' !== $post->post_type )
            return;

        // update the event purchases list
        do_action( 'qsot-update-event-purchases', $post_id );
    }

    // figure out the event area type, based on the post
    public function event_area_type_from_event_area( $post ) {
        // if there are no event area types registered, then bail
        if ( empty( $this->area_types ) )
            return new WP_Error( 'no_types', __( 'There are no registered event area types.', 'opentickets-community-edition' ) );

        // see if the meta value is set, and valid
        $current = get_post_meta( $post->ID, '_qsot-event-area-type', true );

        // if it is set and valid, then use that
        if ( isset( $current ) && is_string( $current ) && ! empty( $current ) && isset( $this->area_types[ $current ] ) )
            return $this->area_types[ $current ];

        // otherwise, cycle through the find type list, and find the first matching type
        foreach ( $this->find_order as $slug ) {
            if ( $this->area_types[ $slug ]->post_is_this_type( $post ) ) {
                update_post_meta( $post->ID, '_qsot-event-area-type', $slug );
                return $this->area_types[ $slug ];
            }
        }

        // if no match was found, then just use the type with the highest priority (least specific)
        $current = end( $this->find_order );
        return $this->area_types[ $current ];
    }

    // add the values for the custom columns we have
    public function add_custom_event_area_column_values( $column_name, $post_id ) {
        switch ( $column_name ) {
            case 'area_type':
                // get the area_type slug of the post
                $name = get_post_meta( $post_id, '_qsot-event-area-type', true );

                // if there is a registered area_type with that slug, then use the proper name from that area type instead
                if ( is_scalar( $name ) && '' !== $name && isset( $this->area_types[ $name ] ) )
                    $name = $this->area_types[ $name ]->get_name();
                else
                    $name = sprintf( __( '[%s]', 'opentickets-community-edition' ), $name );

                echo force_balance_tags( $name );
                break;
            default:
                $def="null";echo $def;
        }
    }

    // enqueue the needed admin assets on the edit event area page
    public function enqueue_admin_assets_event_area( $exists, $post_id ) {
        wp_enqueue_media();
        wp_enqueue_script( 'qsot-event-area-admin' );
        wp_enqueue_style( 'select2' );

        // setup the js settings for our js
        wp_localize_script( 'qsot-event-area-admin', '_qsot_event_area_admin', array(
            'nonce' => wp_create_nonce( 'do-qsot-ajax' ),
        ) );

        // do the same for each registered area type
        foreach ( $this->area_types as $area_type )
            $area_type->enqueue_admin_assets( 'qsot-event-area', $exists, $post_id );
    }

    // get the event area based on the event
    public function get_event_area_type_for_event( $event_id ) {
        static $cache = array();
        // normalize the event_id
        if ( is_object( $event_id ) && isset( $event_id->ID ) )
            $event_id = $event_id->ID;

        // if the event id is not an id, then bail
        if ( ! is_numeric( $event_id ) || empty( $event_id ) )
            return new WP_Error( 'invalid_id', __( 'The event id you supplied is invalid.', 'opentickets-community-edition' ) );

        // if there was a cached version already stored, then use it
        if ( isset( $cache[ $event_id ] ) )
            return $this->area_types[ $cache[ $event_id ] ];

        // get the event area from the event
        $event_area_id = get_post_meta( $event_id, '_event_area_id', true );
        if ( empty( $event_area_id ) )
            return new WP_Error( 'invalid_id', __( 'The event area id you supplied is invalid.', 'opentickets-community-edition' ) );

        // get the event area raw post
        $event_area = get_post( $event_area_id );

        // get the result
        $result = is_object( $event_area ) && isset( $event_area->post_type ) ? $this->event_area_type_from_event_area( $event_area ) : null;

        // if there was a result, then cache it
        if ( is_object( $result ) )
            $cache[ $event_id ] = $result->get_slug();

        return $result;
    }

    // register the assets we might need for this post type
    public function register_assets() {
        // reuseable data
        $url = QSOT::plugin_url();
        $version = QSOT::version();

        // register some scripts
        wp_register_script( 'qsot-event-area-admin', $url . 'assets/js/admin/event-area-admin.js', array( 'qsot-admin-tools' ), $version );
        wp_register_script( 'qsot-event-event-area-settings', $url . 'assets/js/admin/event-area/event-settings.js', array( 'qsot-event-ui' ), $version );
        wp_register_script( 'qsot-admin-ticket-selection', $url . 'assets/js/admin/order/ticket-selection.js', array( 'qsot-admin-tools', 'jquery-ui-dialog', 'qsot-frontend-calendar' ), $version );

        // register all the area type assets
        foreach ( $this->area_types as $area_type )
            $area_type->register_assets();
    }
}