<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Temporary_Login_Links_Premium
 * @subpackage Temporary_Login_Links_Premium/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for
 * the public-facing side of the site.
 *
 * @package    Temporary_Login_Links_Premium
 * @subpackage Temporary_Login_Links_Premium/public
 * @author     Your Name <email@example.com>
 */
class TLP_Public {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * The links instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      TLP_Links    $links    The links instance.
     */
    private $links;

    /**
     * The security instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      TLP_Security    $security    The security instance.
     */
    private $security;


    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    string    $plugin_name       The name of the plugin.
     * @param    string    $version           The version of this plugin.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        
        // Initialize the links and security instances
        $this->links = new TLP_Links();
        $this->security = new TLP_Security();
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
        // Only enqueue on login page with temp_login parameter
        if ($this->is_temporary_login_page()) {
            wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/tlp-public.css', array(), $this->version, 'all');
        }
    }

    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        // Only enqueue on login page with temp_login parameter
        if ($this->is_temporary_login_page()) {
            wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/tlp-public.js', array('jquery'), $this->version, false);
        }
    }

    /**
     * Check if current page is a temporary login page.
     *
     * @since    1.0.0
     * @return   bool    True if this is a temporary login page.
     */
    private function is_temporary_login_page() {
        global $pagenow;
        
        return $pagenow === 'wp-login.php' && isset($_GET['temp_login']);
    }

    /**
     * Register hooks for the public-facing functionality.
     *
     * @since    1.0.0
     */
    public function register_hooks() {
        // Intercept login page for temporary login links
        add_action('login_init', array($this, 'process_temporary_login'));
        
        // Load branded login page template
        add_action('login_init', array($this, 'maybe_load_branded_login'));        
        
        // Customize the login page with branding
        add_action('login_head', array($this, 'customize_login_page'));
        add_action('login_header', array($this, 'add_welcome_message'));
        add_action('login_enqueue_scripts', array($this, 'enqueue_branding_styles'));
                
        // Add custom login form
        add_filter('login_message', array($this, 'add_temporary_login_message'));
        
        // Change login logo URL
        add_filter('login_headerurl', array($this, 'change_login_logo_url'));
        add_filter('login_headertext', array($this, 'change_login_logo_text'));
    }

    /**
     * Process temporary login links.
     *
     * @since    1.0.0
     */    
    
    public function process_temporary_login() {
        // Only process if the temp_login parameter is present
        if (!isset($_GET['temp_login'])) {
            return;
        }

        // Get the token
        $token = sanitize_text_field($_GET['temp_login']);

        // Check if IP is blocked due to too many failed attempts
        $ip_blocked = $this->security->is_ip_locked();
        if ($ip_blocked) {
            // Already handled by the security class, just exit
            return;
        }

        // Pre-check if this token exists in the database
        global $wpdb;
        $table_name = $wpdb->prefix . 'temporary_login_links';
        $link_data = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_email, is_active, expiry, max_accesses, access_count, ip_restriction, redirect_to FROM $table_name WHERE link_token = %s",
            $token
        ), ARRAY_A);
    

        // If token doesn't exist in database, log to security logs
        if (!$link_data) {
            $this->security->log_security_event(
                $token, 
                'invalid_token', 
                __('Invalid token - not found in database', 'temporary-login-links-premium')
            );

            // Show error message
            $this->show_login_error(__('Invalid login token.', 'temporary-login-links-premium'));
            return;
        }

        // ALWAYS check token validity and log results
        $validation_error = $this->check_token_validity($link_data, $token);

        // Check if this is an auto-login request
        $auto_login = isset($_GET['auto']) && $_GET['auto'] == '1';

        // Get branding settings
        $branding = get_option('temporary_login_links_premium_branding', array());
        $branding_enabled = !empty($branding['enable_branding']) && $branding['enable_branding'] == 1;

        // If there's a validation error
        if ($validation_error) {
            // Show error message if auto-login or branding disabled
            if ($auto_login || !$branding_enabled) {
                $this->show_login_error($validation_error['message']);
                return;
            }
            // If branding is enabled, the error will be shown in the branded template
            return;
        }

        // If auto-login or branding disabled, proceed with login
        if ($auto_login || !$branding_enabled) {
            // Validate the token with the main system
            $result = $this->links->validate_login_token($token);

            // Check if validation was successful
            if (is_wp_error($result)) {
                $this->security->log_security_event(
                    $token, 
                    'failed', 
                    $result->get_error_message(),
                    $link_data['user_email']
                );

                // Show error message
                $this->show_login_error($result->get_error_message());
                return;
            }
            //exit ($link_data['redirect_to']);
            // Use redirect_to from link_data if available, otherwise use the one from result
            $redirect_to = !empty($link_data['redirect_to']) ? $link_data['redirect_to'] : $result['redirect_to'];

            // Log in the user with the correct redirect URL
            $this->login_user($result['user_id'], $redirect_to);
        }
        // If branding is enabled and not auto-login, the branded login page will be loaded
    } 
    
    
    
    /**
     * Maybe load the branded login page template.
     *
     * @since    1.0.0
     */
    public function maybe_load_branded_login() {
        // Only process if this is a temporary login page
        if (!isset($_GET['temp_login'])) {
            return;
        }

        // Check if this is an auto-login request
        $auto_login = isset($_GET['auto']) && $_GET['auto'] == '1';
        if ($auto_login) {
            return;
        }

        // Get branding settings
        $branding = get_option('temporary_login_links_premium_branding', array());
        $branding_enabled = !empty($branding['enable_branding']) && $branding['enable_branding'] == 1;

        // If branding is enabled, load the branded login page
        if ($branding_enabled) {
            require_once plugin_dir_path(__FILE__) . 'partials/branded-login.php';
            // The exit is in the branded-login.php file
        }
    }    
   
    
    
    /**
     * Check token validity and log specific failure reasons.
     * 
     * @since    1.0.0
     * @param    array     $link_data    The link data from the database.
     * @param    string    $token        The login token.
     * @return   array|false             False if valid, or array with error details if invalid.
     */
    private function check_token_validity($link_data, $token) {
        // Check if link is active
        if ($link_data['is_active'] == 0) {
            $this->security->log_security_event(
                $token, 
                'inactive', 
                __('This login link has been deactivated.', 'temporary-login-links-premium'),
                $link_data['user_email']
            );
            return array(
                'status' => 'inactive',
                'message' => __('This login link has been deactivated.', 'temporary-login-links-premium')
            );
        }

        // Check expiration
        if (strtotime($link_data['expiry']) < time()) {
            $this->security->log_security_event(
                $token, 
                'expired', 
                __('This login link has expired.', 'temporary-login-links-premium'),
                $link_data['user_email']
            );
            return array(
                'status' => 'expired',
                'message' => __('This login link has expired.', 'temporary-login-links-premium')
            );
        }

        // Check max accesses
        if ($link_data['max_accesses'] > 0 && $link_data['access_count'] >= $link_data['max_accesses']) {
            $this->security->log_security_event(
                $token, 
                'max_accesses', 
                __('This login link has reached its maximum number of uses.', 'temporary-login-links-premium'),
                $link_data['user_email']
            );
            return array(
                'status' => 'max_accesses',
                'message' => __('This login link has reached its maximum number of uses.', 'temporary-login-links-premium')
            );
        }

        // Check IP restriction if set
        if (!empty($link_data['ip_restriction'])) {
            $ip_addresses = array_map('trim', explode(',', $link_data['ip_restriction']));
            $current_ip = $this->security->get_client_ip();

            if (!in_array($current_ip, $ip_addresses)) {
                $this->security->log_security_event(
                    $token, 
                    'ip_restricted', 
                    __('Access denied from your IP address.', 'temporary-login-links-premium'),
                    $link_data['user_email']
                );
                return array(
                    'status' => 'ip_restricted',
                    'message' => __('Access denied from your IP address.', 'temporary-login-links-premium')
                );
            }
        }

        // Token is valid
        return false;
    }    
    
    

    /**
     * Show login error message.
     *
     * @since    1.0.0
     * @param    string    $message    The error message.
     */
    private function show_login_error($message) {
        // Store the error message for display
        global $error;
        $error = $message;
        
        // Add a hook to display error in the login form
        add_filter('login_message', function() use ($message) {
            return '<div id="login_error">' . esc_html($message) . '</div>';
        });
    }

    /**
     * Log in the user with a temporary login link.
     *
     * @since    1.0.0
     * @param    int       $user_id       The user ID.
     * @param    string    $redirect_to   The URL to redirect to after login.
     */
    private function login_user($user_id, $redirect_to) {
        // Get the user
        $user = get_user_by('id', $user_id);

        if (!$user) {
            $this->show_login_error(__('User not found.', 'temporary-login-links-premium'));
            return;
        }

        // Set auth cookie
        wp_set_auth_cookie($user_id, false);

        // Set current user
        wp_set_current_user($user_id);

        // Update user last login
        update_user_meta($user_id, 'tlp_last_login', current_time('mysql'));

        // Send admin notification if enabled
        $this->maybe_send_admin_notification($user);

        // If redirect_to is empty, use admin_url as fallback
        if (empty($redirect_to)) {
            $redirect_to = admin_url();
        }

        // For debugging - can be removed in production version
        error_log('Redirecting to: ' . $redirect_to);

        // Redirect after login
        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * Maybe send admin notification when a temporary login is used.
     *
     * @since    1.0.0
     * @param    WP_User    $user    The user who logged in.
     */
    private function maybe_send_admin_notification($user) {
        // Check if admin notifications are enabled
        $settings = get_option('temporary_login_links_premium_settings', array());
        
        if (empty($settings['admin_notification']) || $settings['admin_notification'] != 1) {
            return;
        }
        
        // Get admin email
        $admin_email = get_option('admin_email');
        
        // Prepare email content
        /* translators: %s Company name  */
        $subject = sprintf(__('[%s] Temporary Login Used', 'temporary-login-links-premium'), get_bloginfo('name'));
        
        /* translators: %s Company name  */
        $message = sprintf(__("Hello,\n\nThis is a notification that a temporary login link has been used on your website %s.\n\n", 'temporary-login-links-premium'), get_bloginfo('name'));
        
        /* translators: %s User Email  */   
        $message .= sprintf(__("User Email: %s\n", 'temporary-login-links-premium'), $user->user_email);
        /* translators: %s User Role  */   
        $message .= sprintf(__("User Role: %s\n", 'temporary-login-links-premium'), $this->get_role_display_name($user->roles[0]));
        /* translators: %s Login time  */
        $message .= sprintf(__("Login Time: %s\n", 'temporary-login-links-premium'), current_time('mysql'));
        /* translators: %s IP address  */
        $message .= sprintf(__("IP Address: %s\n\n", 'temporary-login-links-premium'), $this->security->get_client_ip());
        
        /* translators: %s Temporary links  */   
        $message .= sprintf(__("You can view all temporary links here: %s\n\n", 'temporary-login-links-premium'), admin_url('admin.php?page=temporary-login-links-premium-links'));
        
        /* translators: %s Company Name  */   
        $message .= sprintf(__("Regards,\n%s Team", 'temporary-login-links-premium'), get_bloginfo('name'));
        
        // Send the email
        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Customize the login page with branding.
     *
     * @since    1.0.0
     */
    public function customize_login_page() {
        // Only customize if this is a temporary login page
        if (!$this->is_temporary_login_page()) {
            return;
        }
        
        // Get branding settings
        $branding = get_option('temporary_login_links_premium_branding', array());
        
        // Check if branding is enabled
        if (empty($branding['enable_branding']) || $branding['enable_branding'] != 1) {
            return;
        }
        
        // Build custom CSS
        $css = '<style type="text/css">';
        
        // Logo
        if (!empty($branding['login_logo'])) {
            $css .= 'body.login h1 a { 
                background-image: url(' . esc_url($branding['login_logo']) . '); 
                background-size: contain; 
                width: 320px; 
                height: 80px; 
                margin-bottom: 30px;
            }';
        }
        
        // Background color
        if (!empty($branding['login_background_color'])) {
            $css .= 'body.login { background-color: ' . esc_attr($branding['login_background_color']) . '; }';
        }
        
        // Form background
        if (!empty($branding['login_form_background'])) {
            $css .= 'body.login #loginform { background-color: ' . esc_attr($branding['login_form_background']) . '; }';
        }
        
        // Form text color
        if (!empty($branding['login_form_text_color'])) {
            $css .= 'body.login #loginform label, body.login #loginform .forgetmenot label { color: ' . esc_attr($branding['login_form_text_color']) . '; }';
        }
        
        // Button colors
        if (!empty($branding['login_button_color'])) {
            $css .= 'body.login #loginform #wp-submit { 
                background-color: ' . esc_attr($branding['login_button_color']) . '; 
                border-color: ' . esc_attr($branding['login_button_color']) . ';
            }';
        }
        
        if (!empty($branding['login_button_text_color'])) {
            $css .= 'body.login #loginform #wp-submit { color: ' . esc_attr($branding['login_button_text_color']) . '; }';
        }
        
        // Custom CSS
        if (!empty($branding['login_custom_css'])) {
            $css .= wp_strip_all_tags($branding['login_custom_css']);
        }
        
        $css .= '</style>';
        
        echo wp_kses_post($css);
    }

    /**
     * Add welcome message to login page.
     *
     * @since    1.0.0
     */
    public function add_welcome_message() {
        // Only add welcome message if this is a temporary login page
        if (!$this->is_temporary_login_page()) {
            return;
        }
        
        // Get branding settings
        $branding = get_option('temporary_login_links_premium_branding', array());
        
        // Check if branding is enabled
        if (empty($branding['enable_branding']) || $branding['enable_branding'] != 1) {
            return;
        }
        
        // Get welcome text
        $welcome_text = isset($branding['login_welcome_text']) ? $branding['login_welcome_text'] : __('Welcome! You have been granted temporary access to this site.', 'temporary-login-links-premium');
        
        if (!empty($welcome_text)) {
            echo '<div class="tlp-welcome-message">' . wp_kses_post($welcome_text) . '</div>';
        }
    }

    /**
     * Enqueue branding styles for the login page.
     *
     * @since    1.0.0
     */
    public function enqueue_branding_styles() {
        // Only enqueue if this is a temporary login page
        if (!$this->is_temporary_login_page()) {
            return;
        }
        
        // Enqueue the branded login stylesheet
        wp_enqueue_style('tlp-branded-login', plugin_dir_url(__FILE__) . 'css/tlp-public.css', array(), $this->version);
    }

    /**
     * Add a message to the login form for temporary login links.
     *
     * @since    1.0.0
     * @param    string    $message    The current login message.
     * @return   string                The modified login message.
     */
    public function add_temporary_login_message($message) {
        // Only add message if this is a temporary login page
        if (!$this->is_temporary_login_page()) {
            return $message;
        }
        
        $token = isset($_GET['temp_login']) ? sanitize_text_field($_GET['temp_login']) : '';
        
        // Get token info
        global $wpdb;
        $table_name = $wpdb->prefix . 'temporary_login_links';
        
        $link = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE link_token = %s LIMIT 1",
                $token
            )
        );
        
        if (!$link) {
            return $message;
        }
        
        // Build message
        $temp_message = '<div class="tlp-login-message">';
        
        // Check status
        if ($link->is_active == 0) {
            $temp_message .= '<p class="tlp-status-message tlp-status-inactive">';
            $temp_message .= __('This login link has been deactivated.', 'temporary-login-links-premium');
            $temp_message .= '</p>';
        } elseif (strtotime($link->expiry) < time()) {
            $temp_message .= '<p class="tlp-status-message tlp-status-expired">';
            $temp_message .= __('This login link has expired.', 'temporary-login-links-premium');
            $temp_message .= '</p>';
        } elseif ($link->max_accesses > 0 && $link->access_count >= $link->max_accesses) {
            $temp_message .= '<p class="tlp-status-message tlp-status-maxed">';
            $temp_message .= __('This login link has reached its maximum number of uses.', 'temporary-login-links-premium');
            $temp_message .= '</p>';
        } else {
            $temp_message .= '<p class="tlp-status-message tlp-status-active">';
            $temp_message .= __('You are using a temporary login link. No password is required.', 'temporary-login-links-premium');
            $temp_message .= '</p>';
            
            // Add expiry info
            $expiry_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($link->expiry));
            $temp_message .= '<p class="tlp-expiry-info">';
            /* translators: %s Expiry Date  */   
            $temp_message .= sprintf(__('This link will expire on: %s', 'temporary-login-links-premium'), '<strong>' . $expiry_date . '</strong>');
            $temp_message .= '</p>';
            
            // Add auto-login script
            $temp_message .= $this->get_auto_login_script($link->user_login);
        }
        
        $temp_message .= '</div>';
        
        return $message . $temp_message;
    }

    /**
     * Get auto-login script for temporary links.
     *
     * @since    1.0.0
     * @param    string    $username    The username to auto-fill.
     * @return   string                 The auto-login script.
     */
    private function get_auto_login_script($username) {
        ob_start();
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-fill the username
            var usernameField = document.getElementById('user_login');
            if (usernameField) {
                usernameField.value = '<?php echo esc_js($username); ?>';
            }
            
            // Hide the password field
            var passwordField = document.getElementById('user_pass');
            var passwordLabel = document.querySelector('label[for="user_pass"]');
            
            if (passwordField && passwordLabel) {
                passwordField.parentNode.style.display = 'none';
                passwordLabel.style.display = 'none';
            }
            
            // Change submit button text
            var submitButton = document.getElementById('wp-submit');
            if (submitButton) {
                submitButton.value = '<?php echo esc_js(__('Access Site', 'temporary-login-links-premium')); ?>';
                
                // Auto-submit the form
                setTimeout(function() {
                    document.getElementById('loginform').submit();
                }, 1500);
            }
            
            // Add loading indicator
            var form = document.getElementById('loginform');
            if (form) {
                var loadingIndicator = document.createElement('div');
                loadingIndicator.className = 'tlp-loading-indicator';
                loadingIndicator.innerHTML = '<?php echo esc_js(__('Logging in automatically...', 'temporary-login-links-premium')); ?>';
                form.appendChild(loadingIndicator);
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Change login logo URL.
     *
     * @since    1.0.0
     * @param    string    $url    The login logo URL.
     * @return   string            The modified login logo URL.
     */
    public function change_login_logo_url($url) {
        // Only change if this is a temporary login page
        if (!$this->is_temporary_login_page()) {
            return $url;
        }
        
        // Get branding settings
        $branding = get_option('temporary_login_links_premium_branding', array());
        
        // Check if branding is enabled
        if (empty($branding['enable_branding']) || $branding['enable_branding'] != 1) {
            return $url;
        }
        
        return home_url();
    }

    /**
     * Change login logo text.
     *
     * @since    1.0.0
     * @param    string    $text    The login logo text.
     * @return   string             The modified login logo text.
     */
    public function change_login_logo_text($text) {
        // Only change if this is a temporary login page
        if (!$this->is_temporary_login_page()) {
            return $text;
        }
        
        // Get branding settings
        $branding = get_option('temporary_login_links_premium_branding', array());
        
        // Check if branding is enabled
        if (empty($branding['enable_branding']) || $branding['enable_branding'] != 1) {
            return $text;
        }
        
        // Use company name if set
        $company_name = isset($branding['company_name']) ? $branding['company_name'] : get_bloginfo('name');
        
        return $company_name;
    }

    /**
     * Get the display name for a user role.
     *
     * @since    1.0.0
     * @param    string    $role    The role slug.
     * @return   string             The display name for the role.
     */
    private function get_role_display_name($role) {
        global $wp_roles;
        
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        
        return isset($wp_roles->roles[$role]) ? translate_user_role($wp_roles->roles[$role]['name']) : $role;
    }

    /**
     * Initialize the shortcodes.
     *
     * @since    1.0.0
     */
    public function init_shortcodes() {
        $shortcodes = new TLP_Shortcodes($this->plugin_name, $this->version, $this->links);
        $shortcodes->register_shortcodes();
    }
}