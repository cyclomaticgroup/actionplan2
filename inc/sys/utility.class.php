<?php

class Utility
{
    public static function loadtextdomain()
    {
        $domain = 'opentickets-community-edition';
        $locale = apply_filters('plugin_locale', get_locale(), $domain);

        // first load any custom language file defined in the site languages path
        load_textdomain($domain, WP_LANG_DIR . '/plugins/' . $domain . '/custom-' . $domain . '-' . $locale . '.mo');

        // load the translation after all plugins have been loaded. fixes the multilingual issues
        load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/langs/');
    }

    public static function memory_limit_problem()
    {
        if (empty(self::$memory_error)) return;

        $msg = str_replace(
            array(
                '%%PRODUCT%%',
            ),
            array(
                sprintf('<em><a href="%s" target="_blank">%s</a></em>', esc_attr(self::$o->product_url), force_balance_tags(self::$o->product_name)),
            ),
            self::$memory_error
        );

        ?>
        <div class="error errors">
            <p class="error">
                <u><strong><?php _e('Memory Requirement Problem', 'opentickets-community-edition') ?></strong></u><br/>
                <?php echo $msg ?>
            </p>
        </div>
        <?php
    }

    // get the current number of milliseconds during execution. used for 'since' in the reservation table, mostly
    public static function mille()
    {
        // get the current microtime
        $when = explode('.', microtime(true));
        return (int)end($when);
    }

    public static function memory_limit($force = false)
    {
        static $max = false;

        if ($force || $max === false) {
            $max = self::xb2b(ini_get('memory_limit'), true);
        }

        return $max;
    }

    public static function xb2b($raw, $fakeit = false)
    {
        $raw = strtolower($raw);
        preg_match_all('#^(\d+)(\w*)?$#', $raw, $matches, PREG_SET_ORDER);
        if (isset($matches[0])) {
            $out = $matches[0][1];
            $unit = $matches[0][2];
            switch ($unit) {
                case 'k':
                    $out *= 1024;
                    break;
                case 'm':
                    $out *= 1048576;
                    break;
                case 'g':
                    $out *= 1073741824;
                    break;
                default:
                    $def = "null";
                    echo $def;
            }
        } else {
            $out = $fakeit ? 32 * 1048576 : $raw;
        }

        return $out;
    }

    // get the color defaults
    public static function default_colors()
    {
        return array(
            // ticket selection ui
            'form_bg' => '#f4f4f4',
            'form_border' => '#888888',
            'form_action_bg' => '#888888',
            'form_helper' => '#757575',

            'good_msg_bg' => '#eeffee',
            'good_msg_border' => '#008800',
            'good_msg_text' => '#008800',

            'bad_msg_bg' => '#ffeeee',
            'bad_msg_border' => '#880000',
            'bad_msg_text' => '#880000',

            'remove_bg' => '#880000',
            'remove_border' => '#660000',
            'remove_text' => '#ffffff',

            // calendar defaults
            'calendar_item_bg' => '#f0f0f0',
            'calendar_item_border' => '#577483',
            'calendar_item_text' => '#577483',
            'calendar_item_bg_hover' => '#577483',
            'calendar_item_border_hover' => '#577483',
            'calendar_item_text_hover' => '#ffffff',

            'past_calendar_item_bg' => '#ffffff',
            'past_calendar_item_border' => '#bbbbbb',
            'past_calendar_item_text' => '#bbbbbb',
            'past_calendar_item_bg_hover' => '#ffffff',
            'past_calendar_item_border_hover' => '#bbbbbb',
            'past_calendar_item_text_hover' => '#bbbbbb',
        );
    }

    // fetch and compile the current settings for the frontend colors. make sure to apply known defaults
    public static function current_colors()
    {
        $options = qsot_options::instance();
        $colors = $options->{'qsot-event-frontend-colors'};
        $defaults = self::default_colors();

        return wp_parse_args($colors, $defaults);
    }


    function store_in_session($key, $value)
    {
        if (isset($_SESSION)) {
            $_SESSION[$key] = $value;
        }
    }

    function unset_session($key)
    {
        $_SESSION[$key] = ' ';
        unset($_SESSION[$key]);
    }

    function get_from_session($key)
    {
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        } else {
            return false;
        }
    }


    function csrfguard_generate_token($unique_form_name)
    {
        $token = random_bytes(64); // PHP 7, or via paragonie/random_compat
        store_in_session($unique_form_name, $token);
        return $token;
    }

    function csrfguard_validate_token($unique_form_name, $token_value)
    {
        $token = get_from_session($unique_form_name);
        if (!is_string($token_value)) {
            return false;

        }
        $result = hash_equals($token, $token_value);
        unset_session($unique_form_name);
        return $result;
    }

    function csrfguard_replace_forms($form_data_html)
    {
        $count = preg_match_all("/<form(.*?)>(.*?)<\\/form>/is", $form_data_html, $matches, PREG_SET_ORDER);
        echo $count;

        if (is_array($matches)) {
            foreach ($matches as $m) {
                if (strpos($m[1], "nocsrf") !== false) {
                    continue;
                }
                $name = "CSRFGuard_" . mt_rand(0, mt_getrandmax());
                $token = csrfguard_generate_token($name);
                $form_data_html = str_replace($m[0],
                    "<form{$m[1]}>
<input type='hidden' name='CSRFName' value='{$name}' />
<input type='hidden' name='CSRFToken' value='{$token}' />{$m[2]}</form>", $form_data_html);
            }
        }
        return $form_data_html;
    }

    function csrfguard_inject()
    {
        $data = ob_get_clean();
        $data = csrfguard_replace_forms($data);
        echo $data;
    }

    function csrfguard_start()
    {
        if (count($_POST)) {
            if (!isset($_POST['CSRFName']) or !isset($_POST['CSRFToken'])) {
                trigger_error("No CSRFName found, probable invalid request.", E_USER_ERROR);
            }
            $name = $_POST['CSRFName'];
            $token = $_POST['CSRFToken'];
            if (!csrfguard_validate_token($name, $token)) {
                throw new Exception("Invalid CSRF token.");
            }
        }
        ob_start();
        /* adding double quotes for "csrfguard_inject" to prevent:
              Notice: Use of undefined constant csrfguard_inject - assumed 'csrfguard_inject' */
        register_shutdown_function("csrfguard_inject");
    }

    // do magic
    public static function activation()
    {
        session_start(); //if you are copying this code, this line makes it work.
        csrfguard_start();
        self::load_plugins_and_modules();

        OpenTickets_Community_Launcher::otce_2_0_0_compatibility_check();

        do_action('qsot-activate');
        flush_rewrite_rules();

        ob_start();
        self::compile_frontend_styles();
        $out = ob_get_contents();
        ob_end_clean();
        if (defined('WP_DEBUG') && WP_DEBUG)
            file_put_contents('compile.log', $out);
    }

    public static function prepend_overtake_autoloader()
    {
        spl_autoload_register(array(__CLASS__, 'special_autoloader'), true, true);
    }

    public static function load_custom_emails($list)
    {
        do_action('qsot-load-includes', '', '#^.+\.email\.php$#i');
        return $list;
    }

    public static function only_search_parent_events($query) {
        if ( isset( $query['post_type'] ) ) {
            if ( ! isset( $query['post_type'] ) && (
                    ( is_array( $query['post_type'] ) && in_array( self::$o->core_post_type, $query['post_type'] ) ) ||
                    ( is_scalar( $query['post_type'] ) && $query['post_type'] == self::$o->core_post_type )
                ) ) {
                $query['post_parent'] = 0;
            }
        }
        return $query;
    }

    // when running the seating report, we need the report to know about our valid reservation states. add then here
    public function add_state_types_to_report( $list ) {
        // get a list of the valid states from our zoner
        $zoner = $this->get_zoner();
        $stati = $zoner->get_stati();

        // add each one to the list we are returning
        foreach ( $stati as $status )
            $list[ $status[0] ] = $status;

        return $list;
    }

    // fetch the object that is handling the registrations for this event_area type
    public function get_zoner() {
        return QSOT_General_Admission_Zoner::instance();
    }

    // register this area type after all plugins have loaded
    public function plugins_loaded() {
        // register this as an event area type
        do_action_ref_array( 'qsot-register-event-area-type', array( &$this ) );
    }

    // register the assets we may need in either the admin or the frontend, for this area_type
    public function register_assets() {
        // reusable data
        $url = QSOT::plugin_url();
        $version = QSOT::version();

        // register styles and scripts
        wp_register_style( 'qsot-gaea-event-frontend', $url . 'assets/css/frontend/event.css', array(), $version );
        wp_register_script( 'qsot-gaea-event-frontend', $url . 'assets/js/features/event-area/ui.js', array( 'qsot-tools' ), $version );
    }

    // get the admin templates that are needed based on type and args
    public function get_admin_templates( $list, $type, $args='' ) {
        switch ( $type ) {
            case 'ticket-selection':
                $list['general-admission'] = array();

                // create a list of the templates we need
                $needed_templates = array( 'info', 'actions-change', 'actions-add', 'inner-change', 'inner-add' );

                // add the needed templates to the output list
                foreach ( $needed_templates as $template )
                    $list['general-admission'][ $template ] = QSOT_Templates::maybe_include_template( 'admin/ticket-selection/general-admission/' . $template . '.php', $args );
                break;
            default:
                $def="null";echo $def;
        }

        return $list;
    }

    // determine if the supplied post could be of this area type
    public function post_is_this_type( $post ) {
        // if this is not an event area, then it cannot be
        if ( 'qsot-event-area' != $post->post_type )
            return false;

        $type = get_post_meta( $post->ID, '_qsot-event-area-type', true );
        // if the area_type is set, and it is not equal to this type, then bail
        if ( ! empty( $type ) && $type !== $this->slug )
            return false;

        // otherwise, it is
        return true;
    }

    // handle the saving of event areas of this type
    // registered during area_type registration. then called in inc/event-area/post-type.class.php save_post()
    public function save_post( $post_id) {
        // check the nonce for our settings. if not there or invalid, then bail
        if ( ! isset( $_POST['qsot-gaea-n'] ) || ! wp_verify_nonce( $_POST['qsot-gaea-n'], 'save-qsot-gaea-now' ) )
            return;

        // save all the data for this type
        update_post_meta( $post_id, '_pricing_options', isset( $_POST['gaea-ticket'] ) ? $_POST['gaea-ticket'] : '' );
        update_post_meta( $post_id, '_capacity', isset( $_POST['gaea-capacity'] ) ? $_POST['gaea-capacity'] : '' );
        update_post_meta( $post_id, '_thumbnail_id', isset( $_POST['gaea-img-id'] ) ? $_POST['gaea-img-id'] : '' );
    }

}