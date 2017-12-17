<?php

class PostSettings
{
    // enqueue the needed admin assets on the edit event page
    public function enqueue_admin_assets_event( $exists, $post_id ) {
        wp_enqueue_script( 'qsot-event-event-area-settings' );

        // do the same for each registered area type
        foreach ( $this->area_types as $area_type )
            $area_type->enqueue_admin_assets( 'qsot-event', $exists, $post_id );
    }

    // enqueue the frontend assets we need
    public function enqueue_assets( $post ) {
        // figure out the event area type of this event
        $event_area = apply_filters( 'qsot-event-area-for-event', false, $post );
        $area_type = is_object( $event_area ) && ! is_wp_error( $event_area ) ? $this->event_area_type_from_event_area( $event_area ) : false;

        // if there is a valid area_type, then load it's frontend assets
        if ( is_object( $area_type ) ) {
            $event = apply_filters( 'qsot-get-event', $post, $post );
            $event->event_area = isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', null, $event );
            $area_type->enqueue_assets( $event );
        }
    }

    // add the column to the event area posts list page
    public function add_custom_event_area_columns( $columns ) {
        $new_columns = array();
        // add the new column after the title column
        foreach ( $columns as $key => $val ) {
            $new_columns[ $key ] = $val;
            if ( 'title' == $key )
                $new_columns['area_type'] = __( 'Area Type', 'opentickets-community-edition' );
        }

        return $new_columns;
    }

    // get the event capacity of the specified event or event area 'qsot-get-event-capacity'
    public function get_event_capacity( $capacity, $event, $type='total' ) {
        // normalize the event
        $event = ! ( $event instanceof WP_Post ) ? get_post( $event ) : $event;

        // find the event area, because that is where the capacity is actually stored
        $event_area = 'qsot-event-area' == $event->post_type
            ? apply_filters( 'qsot-get-event-area', $event, $event )
            : ( is_object( $event ) && isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', null, $event ) );

        // if there is an event area, then use it to find the capacity
        if ( ( $event_area instanceof WP_Post ) && is_object( $event_area->area_type ) && ! is_wp_error( $event_area->area_type ) ) {
            $capacity = $event_area->area_type->get_capacity( $event_area, $type );
        }

        return $capacity;
    }

    // draw the event ticket selection UI
    public function draw_event_area( $content, $event ) {
        remove_filter( 'qsot-event-the-content', array( &$this, 'draw_event_area' ), 1000 );
        // get the event area
        $event_area = isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', false, $event->ID );

        // if there is no event area, then bail
        if ( ! is_object( $event_area ) || is_wp_error( $event_area ) )
            return $content;

        // get the event area type
        $event_area->area_type = $area_type = isset( $event_area->area_type ) && is_object( $event_area->area_type ) ? $event_area->area_type : $this->event_area_type_from_event_area( $event_area );

        // get the output of the UI
        $ui = $area_type->render_ui( $event, $event_area );

        add_filter( 'qsot-event-the-content', array( &$this, 'draw_event_area' ), 1000, 2 );
        // put the UI in the appropriate location, depending on our settings
        if ( 'above' == apply_filters( 'qsot-get-option-value', 'below', 'qsot-synopsis-position' ) )
            return $content . $ui;
        else
            return $ui . $content;
    }

    // draw the featured image for the event area, based on the event area types
    public function draw_event_area_image( $event, $area, $reserved) {
        // make sure we have the event area type handy
        if ( ! is_object( $area ) || ! isset( $area->area_type ) || ! is_object( $area->area_type ) )
            $area = apply_filters( 'qsot-event-area-for-event', $area, $event->ID );

        // if we still do not have the event area type handy, then bail
        if ( ! is_object( $area ) || ! isset( $area->area_type ) || ! is_object( $area->area_type ) )
            return;

        // otherwise, draw the event area image
        $area->area_type->draw_event_area_image( $area, $event, $reserved );
    }

    // get the textual representation of how many tickets are left
    public function get_availability_words( $words, $capacity, $available ) {
        // find out the remaining percentage of tickets
        $percent = $capacity > 0 ? $available / $capacity : 0;

        // figure out the appropriate words to use
        switch ( true ) {
            case $percent < .02: $words = __( 'Sold-out', 'opentickets-community-edition' ); break;
            case $percent < .15: $words = __( 'Low', 'opentickets-community-edition' ); break;
            case $percent < .35: $words = __( 'Medium', 'opentickets-community-edition' ); break;
            default: $words = __( 'High', 'opentickets-community-edition' ); break;
        }

        return $words;
    }

    // get the event area based on the event
    public function get_event_area_for_event( $event_id ) {
        // normalize the event_id
        if ( is_object( $event_id ) && isset( $event_id->ID ) )
            $event_id = $event_id->ID;

        // if the event id is not an id, then bail
        if ( ! is_numeric( $event_id ) || empty( $event_id ) )
            return new WP_Error( 'invalid_id', __( 'The event id you supplied is invalid.', 'opentickets-community-edition' ) );

        // get the event area from the event
        $event_area_id = get_post_meta( $event_id, '_event_area_id', true );
        if ( empty( $event_area_id ) )
            return new WP_Error( 'invalid_id', __( 'The event area id you supplied is invalid.', 'opentickets-community-edition' ) );

        return apply_filters( 'qsot-get-event-area', false, $event_area_id );
    }

    // load an event area based on the id
    public function get_event_area( $current, $event_area_id ) {
        // get the event area object
        $event_area = get_post( $event_area_id );
        if ( ! ( $event_area instanceof WP_Post ) )
            return $current;

        // add the meta
        $event_area->meta = get_post_meta( $event_area->ID );
        foreach ( $event_area->meta as $k => $v )
            $event_area->meta[ $k ] = current( $v );

        // add the area type
        $event_area->area_type = $this->event_area_type_from_event_area( $event_area );

        return $event_area;
    }

    // function to obtain a list of all the registered event area types
    public function get_event_area_types( $desc_order=false ) {
        // return a list of event_types ordered by priority, either asc (default) or desc (param)
        return ! $desc_order ? $this->area_types : array_reverse( $this->area_types );
    }

    // sort items by $obj->priority()
    public function uasort_priority( $a, $b ) { return $a->get_priority() - $b->get_priority(); }

    // sort items by $obj->find_priority()
    public function uasort_find_priority( $a, $b ) {
        $A = $a->get_find_priority();
        $B = $b->get_find_priority();
        return ( $A !== $B ) ? $A - $B : $a->get_priority() - $b->get_priority();
    }

    // handle the save post action
    public function save_post( $post_id, $post, $updated=false ) {
        // figure out the submitted event area type
        $event_area_type = isset( $_POST['qsot-event-area-type'] ) ? $_POST['qsot-event-area-type'] : null;

        // if the event type is empty, then bail
        if ( empty( $event_area_type ) )
            return;

        // if the selected type is not a valid type, then bail
        if ( ! isset( $this->area_types[ $event_area_type ] ) )
            return;

        // save the event area type
        update_post_meta( $post_id, '_qsot-event-area-type', $event_area_type );

        // run the post type save
        $this->area_types[ $event_area_type ]->save_post( $post_id, $post, $updated );
    }

    // during cart loading from session, we need to make sure we load all preserved keys
    public function load_item_data( $current, $values) {
        // get a list of all the preserved keys from our event area types, and add it to the list of keys that need to be loaded
        foreach ( apply_filters( 'qsot-ticket-item-meta-keys', array() ) as $k )
            if ( isset( $values[ $k ] ) )
                $current[ $k ] = $values[ $k ];

        // store a backup copy of the quantity, so that if it changes we have something to compare it to later
        $current['_starting_quantity'] = $current['quantity'];

        return $current;
    }

    // add to the list of item data that needs to be preserved
    public function add_item_meta( $item_id, $values ) {
        // get a list of keys that need to be preserved from our event area types, and add each to the list of keys that needs to be saved in the order items when making an item from a cart item
        foreach ( apply_filters( 'qsot-ticket-item-meta-keys', array() ) as $k ) {
            if ( ! isset( $values[ $k ] ) )
                continue;
            wc_update_order_item_meta( $item_id, '_' . $k, $values[ $k ] );
        }
    }

    // add to the list of meta that needs to be hidden when displaying order items
    public function hide_item_meta( $list ) {
        $list[] = '_event_id';
        return array_filter( array_unique( apply_filters( 'qsot-ticket-item-hidden-meta-keys', $list ) ) );
    }

    // on the edit order screen, for each ticket order item, add the 'view' version of the ticket information
    public function before_view_item_meta( $item_id, $item, $product ) {
        self::_draw_item_ticket_info( $item_id, $item, $product);
    }

    // on the edit order screen, for each ticket order item, add the 'edit' version of the ticket information
    public function before_edit_item_meta( $item_id, $item, $product ) {
        self::_draw_item_ticket_info( $item_id, $item, $product);
    }

    // when creating a new order, we need to update the related ticket rows with the new order id
    public function update_order_id_and_status( $order_id ) {
        // load the order
        $order = wc_get_order( $order_id );

        // cycle through the order items, and update all the ticket items to confirmed
        foreach ( $order->get_items() as $item_id => $item ) {
            $item = QSOT_WC3()->order_item( $item );
            // only do this for order items that are tickets
            if ( apply_filters( 'qsot-item-is-ticket', false, $item ) )
            {
                // get the event, area_type and zoner for this item
                $event = get_post( $item['event_id'] );
                $event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
                $area_type = is_object( $event_area ) ? $event_area->area_type : null;

                // if any of the data is missing, the skip this item
                if ( ! is_object( $event ) || ! is_object( $event_area ) || ! is_object( $area_type ) )
                    continue;

                // have the event_area determine how to update the order item info in the ticket table
                //$result = $area_type->confirm_tickets( $item, $item_id, $order, $event, $event_area );
                //$result = $this->_update_order_id( $order, $item, $item_id, $event, $event_area, $area_type );
                $result_status = $area_type->confirm_tickets( $item, $item_id, $order, $event, $event_area );

                // notify externals of the change
                //do_action( 'qsot-updated-order-id', $order, $item, $item_id, $result );
                do_action( 'qsot-confirmed-ticket', $order, $item, $item_id, $result_status );
            }
        }
    }

    // when the order status changes, change make sure to update the ticket purchase count
    public function order_status_changed( $order_id, $new_status ) {
        // if the status is a status that should have it's count, counted, then do so
        if ( in_array( $new_status, apply_filters( 'qsot-zoner-confirmed-statuses', array( 'on-hold', 'processing', 'completed' ) ) ) ) {
            // load the order
            $order = wc_get_order( $order_id );

            // container for all the event ids that need an update
            $updates = array();

            // cycle through the order items, and update all the ticket items to confirmed
            foreach ( $order->get_items() as $item_id => $item ) {
                $item = QSOT_WC3()->order_item( $item );
                // only do this for order items that are tickets
                if ( ! apply_filters( 'qsot-item-is-ticket', false, $item ) )
                    continue;

                // tally this ticket's amount, grouping by event_id
                $updates[ $item['event_id'] ] = 1;
            }

            // update the counts for all events that had tickets purchased
            foreach ( $updates as $event_id => $_ )
                do_action( 'qsot-update-event-purchases', $event_id );
        }
    }

    // fix the problem where ppl click order again
    public function adjust_order_again_items( $meta, $item ) {
        // if the original item is not for an event, then bail now
        if ( ! isset( $item['event_id'] ) )
            return $meta;

        // mark the meta as being an order_again item
        $meta['_order_again'] = true;

        // cycle through the old meta of the original item, and copy any relevant meta to the new item's meta
        if ( isset( $item['item_meta'] ) ) foreach ( $item['item_meta'] as $key => $values ) {
            if ( in_array( $key, apply_filters( 'qsot-order-ticket-again-meta-keys', array( '_event_id' ) ) ) ) {
                $meta[ $key ] = current( $values );
            }
        }

        return $meta;
    }

    // when resuming an order, we need to disassociate all order_item_ids from previous records, because the order items are about to get removed and recreated by core WC.
    // this means we will not be able to properly update the order item id associations, because the original order item id will be gone
    public function on_resume_order_disassociate( $order_id ) {
        // start a basic zoner to do our bidding
        $zoner = QSOT_General_Admission_Zoner::instance();

        $args = array(
            'event_id' => false,
            'ticket_type_id' => false,
            'quantity' => '',
            'customer_id' => '',
            'order_id' => $order_id,
            'order_item_id' => '',
            'state' => '*',
            'where__extra' => '',
        );
        // find all rows that are associated with the order
        $rows = $zoner->find( $args );

        // udpate each row to not be associated with the order_item_id it previously was
        if ( is_array( $rows ) ) foreach ( $rows as $row ) {
            $zoner->update(
                false,
                array(
                    'order_id' => $order_id,
                    'order_item_id' => $row->order_item_id,
                    'state' => $row->state,
                ),
                array(
                    'order_item_id' => 0,
                )
            );
        }
    }

    // filter to maybe hide the current meta box by default
    public function maybe_hide_meta_box_by_default( $classes=array() ) {
        static $area_type = false, $screen = false;
        if ( false === $area_type )
            $post='';
        $area_type = $this->event_area_type_from_event_area( $post );
        // if there is no area_type then bail
        if ( ! is_object( $area_type ) || is_wp_error( $area_type ) )
            return $classes;

        // figure out the screen of the current metabox
        if ( false === $screen ) {
            $screen = get_current_screen();
            if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
                $screen = null;
                return $classes;
            }
            $screen = $screen->id;
        }
        if ( empty( $screen ) )
            return $classes;

        // based on the screen, find the  of the metabox
        $action = current_filter();
        $id = str_replace( 'postbox_classes_' . $screen . '_', '', $action );
        if ( $id == $action )
            return $classes;

        // if this metabox is not used by the current area type, then hide it by default
        if ( ! $area_type->uses_meta_box( $id, $screen ) )
            $classes[] = 'hide-if-js';

        // add a class indicator for each area_type to this metabox, so that it can be easily hidden or shown with js
        foreach ( $this->area_types as $type ) {
            if ( $type->uses_meta_box( $id, $screen ) )
                $classes[] = 'for-' . $type->get_slug();
            else
                $classes[] = 'not-for-' . $type->get_slug();
        }

        return $classes;
    }

    // allow registration of an event area type
    public function register_event_area_type( &$type_object ) {
        // make sure that the submitted type uses the base class
        if ( ! ( $type_object instanceof QSOT_Base_Event_Area_Type ) )
            throw new InvalidEventTypeException( __( 'The supplied event type does not use the QSOT_Base_Event_Type parent class.', 'opentickets-community-edition' ), 12100 );

        // figure out the slug and display name of the submitted event type
        $slug = $type_object->get_slug();

        // add the event area type to the list
        $this->area_types[ $slug ] = $type_object;

        // determine the 'fidn order' for use when searching for the appropriate type
        // default area type should have the highest find_priority
        uasort( $this->area_types, array( &$this, 'uasort_find_priority' ) );
        $this->find_order = array_keys( $this->area_types );

        // sort the list by priority
        uasort( $this->area_types, array( &$this, 'uasort_priority' ) );
    }

    // allow an event area type to be unregistered
    public function deregister_event_area_type( $type ) {
        $slug = '';
        // figure out the slug
        if ( is_string( $type ) )
            $slug = $type;
        elseif ( is_object( $type ) && $type instanceof QSOT_Base_Event_Area_type )
            $slug = $type->get_slug();

        // if there was no slug found, bail
        if ( empty( $slug ) )
            return;

        // if the slug does not coorespond with a registered area type, bail
        if ( ! isset( $this->area_types[ $slug ] ) )
            return;

        unset( $this->area_types[ $slug ] );
    }

}