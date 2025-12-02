<?php
/**
 * Plugin Name:       ShortifyMe
 * Description:       A secure URL shortener. Settings are primary. Includes cached DNS validation, system error logging, and REST API checks.
 * Version:           4.0
 * Author:            Ian R. Gordon
 * Author URI:        https://iangordon.app
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://github.com/irgordon/shortifyme
 * Text Domain:       shortifyme
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShortifyMe_Plugin {

    private $shortifyme_table_name; 
    private $shortifyme_option_name    = 'shortifyme_custom_domain';
    private $shortifyme_menu_slug      = 'shortifyme'; 
    private $shortifyme_links_slug     = 'shortifyme-links'; 
    private $shortifyme_transient_key  = 'shortifyme_dns_cache';

    public function __construct() {
        global $wpdb;
        $this->shortifyme_table_name = $wpdb->prefix . 'shortifyme_urls';

        // Lifecycle Hooks
        register_activation_hook( __FILE__, array( $this, 'shortifyme_activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'shortifyme_deactivate' ) );

        // Runtime Hooks
        add_action( 'rest_api_init', array( $this, 'shortifyme_register_api' ) );
        add_action( 'template_redirect', array( $this, 'shortifyme_redirect' ) );
        
        // Admin UI Hooks
        add_action( 'admin_menu', array( $this, 'shortifyme_register_menu' ) );
        add_action( 'admin_init', array( $this, 'shortifyme_settings_init' ) );
        
        // Clear cache when option is updated
        add_action( "update_option_{$this->shortifyme_option_name}", array( $this, 'shortifyme_clear_dns_cache' ) );
    }

    /**
     * Helper: System Logging
     * Writes to wp-content/debug.log if WP_DEBUG_LOG is enabled.
     */
    private function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ShortifyMe Error]: ' . $message );
        }
    }

    /**
     * Activation
     */
    public function shortifyme_activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->shortifyme_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title text NOT NULL,
            original_url text NOT NULL,
            short_code varchar(50) NOT NULL UNIQUE,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            clicks mediumint(9) DEFAULT 0 NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        
        // Suppress errors during delta but log if critical failure occurs
        $result = dbDelta( $sql );
        if ( empty( $result ) ) {
            $this->log_error( 'Database table creation failed or table already exists.' );
        }
    }

    public function shortifyme_deactivate() {
        flush_rewrite_rules();
        delete_transient( $this->shortifyme_transient_key );
    }

    public function shortifyme_clear_dns_cache() {
        delete_transient( $this->shortifyme_transient_key );
    }

    /**
     * Admin Menu
     */
    public function shortifyme_register_menu() {
        add_menu_page( 'ShortifyMe Settings', 'ShortifyMe', 'manage_options', $this->shortifyme_menu_slug, array( $this, 'shortifyme_render_settings' ), 'dashicons-admin-links', 20 );
        add_submenu_page( $this->shortifyme_menu_slug, 'ShortifyMe Settings', 'Settings', 'manage_options', $this->shortifyme_menu_slug, array( $this, 'shortifyme_render_settings' ) );
        add_submenu_page( $this->shortifyme_menu_slug, 'Create A ShortifyMe Link', 'Links', 'manage_options', $this->shortifyme_links_slug, array( $this, 'shortifyme_render_links' ) );
    }

    /**
     * Settings Initialization
     */
    public function shortifyme_settings_init() {
        register_setting( 'shortifyme_options_group', $this->shortifyme_option_name, array( 'sanitize_callback' => 'esc_url_raw' ) );
        
        add_settings_section( 'shortifyme_main_section', 'General Configuration', array( $this, 'shortifyme_section_cb' ), $this->shortifyme_menu_slug );
        add_settings_field( 'shortifyme_domain_input', 'Domain:', array( $this, 'shortifyme_input_render' ), $this->shortifyme_menu_slug, 'shortifyme_main_section' );
        add_settings_field( 'shortifyme_domain_status', 'Active Configuration:', array( $this, 'shortifyme_status_render' ), $this->shortifyme_menu_slug, 'shortifyme_main_section' );
    }

    public function shortifyme_section_cb() {
        echo '<p>Configure the custom domain you wish to use for your short links.</p>';
    }

    public function shortifyme_input_render() {
        $value = get_option( $this->shortifyme_option_name );
        ?>
        <input type="url" name="<?php echo esc_attr( $this->shortifyme_option_name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="https://shrt.io">
        <p class="description">Enter a custom domain. e.g., <code>shrt.me</code> (FQDN). <br>You can use a sub-domain but it is generally best practice to use a shortened version of your main domain e.g. <code>website.com</code> -> <code>wbst.io</code></p>
        <?php
    }

    public function shortifyme_status_render() {
        $value = get_option( $this->shortifyme_option_name );
        if ( empty( $value ) ) {
            echo '<span class="description" style="color:#d63638;"><em>No custom domain configured.</em></span>';
        } else {
            ?>
            <div style="padding: 8px 12px; background: #edfaef; border: 1px solid #b8e6bf; border-left: 4px solid #00a32a; display: inline-block; border-radius: 2px;">
                <span class="dashicons dashicons-admin-links" style="color: #00a32a; vertical-align: middle;"></span>
                <strong style="font-size: 1.1em; color: #1d2327; margin-left: 5px;"><?php echo esc_html( $value ); ?></strong>
            </div>
            <p class="description">This domain is the current saved shortened domain.</p>
            <?php
        }
    }

    /**
     * Page: Settings (Cached DNS & Error Handling)
     */
    public function shortifyme_render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_GET['refresh_dns'] ) && '1' === $_GET['refresh_dns'] ) {
            $this->shortifyme_clear_dns_cache();
            wp_redirect( remove_query_arg( 'refresh_dns' ) );
            exit;
        }

        $current_wp_host = parse_url( home_url(), PHP_URL_HOST );
        $server_ip = gethostbyname( $current_wp_host );
        
        $custom_url  = get_option( $this->shortifyme_option_name );
        $custom_host = !empty($custom_url) ? parse_url($custom_url, PHP_URL_HOST) : 'example.com';
        
        // DNS Lookup with Cache
        $dns_cache = get_transient( $this->shortifyme_transient_key );

        if ( false === $dns_cache ) {
            $custom_ip = null;
            if ( ! empty( $custom_url ) && $custom_host ) {
                $custom_ip = gethostbyname( $custom_host );
                if ( $custom_ip === $custom_host ) {
                    $custom_ip = 'Lookup Failed'; 
                    $this->log_error( "DNS Lookup failed for host: $custom_host" );
                }
            }
            $dns_cache = array( 'custom_ip' => $custom_ip );
            set_transient( $this->shortifyme_transient_key, $dns_cache, 2 * MINUTE_IN_SECONDS );
        }

        $custom_ip = $dns_cache['custom_ip'];
        $validation_icon = '';
        $validation_msg  = '';

        if ( empty( $custom_url ) ) {
            $validation_icon = '<span class="dashicons dashicons-warning" style="color:orange;"></span>';
            $validation_msg  = 'Please configure a domain above first.';
        } elseif ( $custom_ip === 'Lookup Failed' ) {
            $validation_icon = '<span class="dashicons dashicons-dismiss" style="color:red; font-size: 20px;"></span>';
            $validation_msg  = '<span style="color:red; font-weight:bold;">Error:</span> DNS lookup failed. Domain may not exist.';
        } elseif ( $custom_ip === $server_ip ) {
            $validation_icon = '<span class="dashicons dashicons-yes-alt" style="color:green; font-size: 20px;"></span>';
            $validation_msg  = '<span style="color:green; font-weight:bold;">Success!</span> Domain resolves to ' . esc_html($custom_ip);
        } else {
            $validation_icon = '<span class="dashicons dashicons-dismiss" style="color:red; font-size: 20px;"></span>';
            $validation_msg  = '<span style="color:red; font-weight:bold;">Error:</span> Resolves to ' . esc_html($custom_ip) . ' (Not ' . esc_html($server_ip) . ')';
        }

        $host_parts = explode('.', $custom_host);
        $dns_host_record = (count($host_parts) > 2) ? $host_parts[0] : '@'; 

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php settings_errors(); ?>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'shortifyme_options_group' );
                do_settings_sections( $this->shortifyme_menu_slug );
                submit_button( 'Save Settings' );
                ?>
            </form>
            <hr style="margin: 30px 0;">
            <div style="display:flex; justify-content: space-between; align-items:flex-end;">
                <h2>DNS Configuration Instructions</h2>
                <a href="<?php echo esc_url( add_query_arg( 'refresh_dns', '1', menu_page_url( $this->shortifyme_menu_slug, false ) ) ); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-update" style="vertical-align:text-bottom;"></span> Refresh Status
                </a>
            </div>
            <p>To use <strong><?php echo esc_html($custom_host); ?></strong>, update your DNS records to point to this server.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th class="manage-column">Record Type</th><th class="manage-column">Host / Name</th><th class="manage-column">Priority</th><th class="manage-column">TTL</th><th class="manage-column">Data / Value (Server IP)</th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong>A Record</strong></td>
                        <td><code><?php echo esc_html( $dns_host_record ); ?></code></td>
                        <td>N/A</td>
                        <td>3600 (Automatic)</td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <code style="font-size:1.1em;"><?php echo esc_html( $server_ip ); ?></code>
                                <?php echo $validation_icon; ?>
                            </div>
                            <p class="description" style="margin: 5px 0 0 0; font-size: 12px;"><?php echo $validation_msg; ?></p>
                        </td>
                    </tr>
                </tbody>
                <tfoot><tr><th class="manage-column">Record Type</th><th class="manage-column">Host / Name</th><th class="manage-column">Priority</th><th class="manage-column">TTL</th><th class="manage-column">Data / Value (Server IP)</th></tr></tfoot>
            </table>
        </div>
        <?php
    }

    /**
     * Page: Links (DB Error Handling)
     */
    public function shortifyme_render_links() {
        global $wpdb;
        $base_domain = get_option( $this->shortifyme_option_name ); 
        $errors = array();
        $is_configured = ! empty( $base_domain );

        if ( $is_configured && isset( $_POST['shortifyme_submit'] ) && check_admin_referer( 'shortifyme_new_link_nonce' ) ) {
            $raw_url = isset($_POST['shortifyme_url']) ? wp_unslash($_POST['shortifyme_url']) : '';
            $url     = esc_url_raw( $raw_url );
            $title   = sanitize_text_field( $_POST['shortifyme_title'] ?? '' );
            $slug    = sanitize_key( $_POST['shortifyme_slug'] ?? '' );

            if ( empty( $url ) ) $errors[] = "Target URL is required.";
            if ( empty( $title ) ) $errors[] = "Title is required.";

            if ( empty( $slug ) ) {
                $slug = substr( md5( uniqid( rand(), true ) ), 0, 6 );
            } else {
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $this->shortifyme_table_name WHERE short_code = %s", $slug ) );
                if ( $exists ) $errors[] = "Slug '{$slug}' is already taken.";
            }

            if ( empty( $errors ) ) {
                $result = $wpdb->insert( $this->shortifyme_table_name, array( 'title' => $title, 'original_url' => $url, 'short_code' => $slug, 'created_at' => current_time( 'mysql' ) ), array( '%s', '%s', '%s', '%s' ) );
                
                if ( false === $result ) {
                    $this->log_error( "Database Insert Failed: " . $wpdb->last_error );
                    $errors[] = "Database error. Please check server logs.";
                } else {
                    wp_redirect( add_query_arg( 'shortifyme_status', 'created', menu_page_url( $this->shortifyme_links_slug, false ) ) );
                    exit;
                }
            }
        }

        if ( isset( $_GET['delete'] ) && check_admin_referer( 'shortifyme_delete_link_nonce' ) ) {
            $result = $wpdb->delete( $this->shortifyme_table_name, array( 'id' => absint( $_GET['delete'] ) ), array( '%d' ) );
            
            if ( false === $result ) {
                $this->log_error( "Database Delete Failed: " . $wpdb->last_error );
                // Redirect with error flag if preferred, or show error on next load
                $errors[] = "Could not delete link.";
            } else {
                wp_redirect( add_query_arg( 'shortifyme_status', 'deleted', menu_page_url( $this->shortifyme_links_slug, false ) ) );
                exit;
            }
        }

        $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'created_at';
        $order   = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( $_GET['order'] ) ) : 'DESC';
        $allowed = array( 'title', 'short_code', 'original_url', 'clicks', 'created_at' );
        if ( ! in_array( $orderby, $allowed ) ) $orderby = 'created_at';
        if ( ! in_array( $order, array('ASC', 'DESC') ) ) $order = 'DESC';

        $results = $wpdb->get_results( "SELECT * FROM $this->shortifyme_table_name ORDER BY $orderby $order" );
        
        $new_order = ($order === 'ASC') ? 'desc' : 'asc';
        $base_link = menu_page_url( $this->shortifyme_links_slug, false );
        $header_link = function($col, $lbl) use ($base_link, $orderby, $new_order, $order) {
            $arrow = ($orderby === $col) ? (($order === 'ASC') ? ' &#9650;' : ' &#9660;') : '';
            return '<a href="' . esc_url( add_query_arg( array('orderby' => $col, 'order' => $new_order), $base_link ) ) . '">' . esc_html( $lbl ) . $arrow . '</a>';
        };

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Create A ShortifyMe Link</h1>
            <?php if ( $is_configured ) : ?>
                <a href="#" class="page-title-action" onclick="document.getElementById('shortifyme-add-wrapper').style.display = (document.getElementById('shortifyme-add-wrapper').style.display === 'none' ? 'block' : 'none'); return false;">Add New Link</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php 
            if ( isset( $_GET['shortifyme_status'] ) && $_GET['shortifyme_status'] === 'created' ) echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> New link created.</p></div>';
            if ( isset( $_GET['shortifyme_status'] ) && $_GET['shortifyme_status'] === 'deleted' ) echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Link deleted.</p></div>';
            if ( ! empty( $errors ) ) foreach ( $errors as $err ) echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html( $err ) . '</p></div>';
            ?>

            <?php if ( ! $is_configured ) : ?>
                <div class="notice notice-error" style="border-left-color: #d63638; padding: 15px;">
                    <h3 style="margin: 0 0 5px 0;">Configuration Required</h3>
                    <p>You must configure a <strong>Fully Qualified Domain Name (FQDN)</strong> before you can create custom shortened URLs. Please go to <a href="<?php echo esc_url( menu_page_url( $this->shortifyme_menu_slug, false ) ); ?>">Settings</a> to set up your domain.</p>
                </div>
            <?php else : ?>
                <p class="description" style="margin-bottom: 20px;">
                    <span class="dashicons dashicons-info" style="color:#2271b1; vertical-align:middle;"></span>
                    New links (custom slug or randomized) will be appended to your active domain: <strong><?php echo esc_html( $base_domain ); ?></strong>
                </p>

                <div id="shortifyme-add-wrapper" style="<?php echo ( !empty($errors) ) ? 'display:block;' : 'display:none;'; ?> background:#fff; padding:20px; margin:20px 0; border-left:4px solid #2271b1; box-shadow:0 1px 1px rgba(0,0,0,.04);">
                    <h3>Create New Link</h3>
                    <form method="post">
                        <?php wp_nonce_field( 'shortifyme_new_link_nonce' ); ?>
                        <table class="form-table">
                            <tr><th>Title</th><td><input type="text" name="shortifyme_title" class="regular-text" required value="<?php echo isset($_POST['shortifyme_title']) ? esc_attr($_POST['shortifyme_title']) : ''; ?>"></td></tr>
                            <tr><th>Target URL</th><td><input type="url" name="shortifyme_url" class="regular-text" required value="<?php echo isset($_POST['shortifyme_url']) ? esc_attr($_POST['shortifyme_url']) : ''; ?>"></td></tr>
                            <tr>
                                <th>Slug</th>
                                <td>
                                    <div style="display:flex; align-items:center;">
                                        <span style="background:#f0f0f1; padding:5px 10px; border:1px solid #8c8f94; border-right:0; color:#50575e;"><?php echo esc_html( parse_url($base_domain, PHP_URL_HOST) ); ?>/</span>
                                        <input type="text" name="shortifyme_slug" class="regular-text" style="width: 150px;" placeholder="random" value="<?php echo isset($_POST['shortifyme_slug']) ? esc_attr($_POST['shortifyme_slug']) : ''; ?>">
                                    </div>
                                    <p class="description">Leave empty to auto-generate a random 6-character code.</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit"><input type="submit" name="shortifyme_submit" class="button button-primary" value="Create Link"></p>
                    </form>
                </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th class="manage-column sortable <?php echo ($orderby == 'title' ? $order : 'desc'); ?>"><?php echo $header_link('title', 'Title'); ?></th><th class="manage-column sortable <?php echo ($orderby == 'short_code' ? $order : 'desc'); ?>"><?php echo $header_link('short_code', 'Slug'); ?></th><th class="manage-column sortable <?php echo ($orderby == 'original_url' ? $order : 'desc'); ?>"><?php echo $header_link('original_url', 'Target URL'); ?></th><th class="manage-column sortable <?php echo ($orderby == 'clicks' ? $order : 'desc'); ?>" style="width:80px;"><?php echo $header_link('clicks', 'Clicks'); ?></th><th class="manage-column" style="width:60px;">QR</th><th class="manage-column" style="width:100px;">Actions</th></tr></thead>
                <tbody>
                    <?php if ( $results ) : foreach ( $results as $row ) : 
                        $display_domain = !empty($base_domain) ? $base_domain : home_url();
                        $short_url = rtrim($display_domain, '/') . '/' . $row->short_code;
                        $qr_url = 'https://quickchart.io/qr?text=' . urlencode( $short_url ) . '&size=150';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row->title ); ?></strong></td>
                        <td><a href="<?php echo esc_url( $short_url ); ?>" target="_blank">/<?php echo esc_html( $row->short_code ); ?></a></td>
                        <td><code><?php echo esc_html( $row->original_url ); ?></code></td>
                        <td><?php echo number_format( $row->clicks ); ?></td>
                        <td><a href="<?php echo esc_url($qr_url); ?>" target="_blank" class="dashicons dashicons-id-alt"></a></td>
                        <td><a href="<?php echo wp_nonce_url( admin_url('admin.php?page=' . $this->shortifyme_links_slug . '&delete=' . $row->id), 'shortifyme_delete_link_nonce' ); ?>" style="color:#a00;" onclick="return confirm('Delete?')">Delete</a></td>
                    </tr>
                    <?php endforeach; else: ?><tr><td colspan="6">No links found.</td></tr><?php endif; ?>
                </tbody>
                <tfoot><tr><th class="manage-column"><?php echo $header_link('title', 'Title'); ?></th><th class="manage-column"><?php echo $header_link('short_code', 'Slug'); ?></th><th class="manage-column"><?php echo $header_link('original_url', 'Target URL'); ?></th><th class="manage-column"><?php echo $header_link('clicks', 'Clicks'); ?></th><th class="manage-column">QR</th><th class="manage-column">Actions</th></tr></tfoot>
            </table>
        </div>
        <?php
    }

    /**
     * REST API with Check
     */
    public function shortifyme_register_api() {
        // Ensure REST API is functioning before registering
        if ( ! function_exists( 'register_rest_route' ) ) {
            $this->log_error( 'REST API function register_rest_route does not exist. API not registered.' );
            return;
        }

        register_rest_route( 'shortifyme/v1', '/shorten', array( 'methods' => 'POST', 'callback' => array( $this, 'shortifyme_api_create' ), 'permission_callback' => '__return_true' ) );
    }

    public function shortifyme_api_create( $request ) {
        global $wpdb;
        $params = $request->get_json_params();
        $url   = esc_url_raw( $params['url'] ?? '' );
        $title = sanitize_text_field( $params['title'] ?? 'API Link' );
        $alias = sanitize_key( $params['alias'] ?? '' );

        if ( empty( $url ) ) return new WP_Error( 'no_url', 'Missing URL', array( 'status' => 400 ) );

        if ( ! empty( $alias ) ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $this->shortifyme_table_name WHERE short_code = %s", $alias ) );
            if ( $exists ) return new WP_Error( 'alias_exists', 'Alias taken', array( 'status' => 409 ) );
            $code = $alias;
        } else {
            $code = substr( md5( uniqid( rand(), true ) ), 0, 6 );
        }

        $result = $wpdb->insert( $this->shortifyme_table_name, array( 'title' => $title, 'original_url' => $url, 'short_code' => $code, 'created_at' => current_time( 'mysql' ) ), array( '%s', '%s', '%s', '%s' ) );
        
        if ( false === $result ) {
            $this->log_error( "API Insert Failed: " . $wpdb->last_error );
            return new WP_Error( 'db_error', 'Database Error', array( 'status' => 500 ) );
        }

        $final_url = rtrim( get_option( $this->shortifyme_option_name, home_url() ), '/') . '/' . $code;
        return new WP_REST_Response( array( 'success' => true, 'short_url' => $final_url, 'qr_code' => 'https://quickchart.io/qr?text=' . urlencode( $final_url ) ), 200 );
    }

    public function shortifyme_redirect() {
        if ( is_admin() ) return;
        $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
        if ( empty( $path ) || preg_match( '/^wp-/', $path ) ) return;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->shortifyme_table_name WHERE short_code = %s", $path ) );
        if ( $row ) {
            $wpdb->query( $wpdb->prepare( "UPDATE $this->shortifyme_table_name SET clicks = clicks + 1 WHERE id = %d", $row->id ) );
            wp_redirect( $row->original_url, 301 );
            exit;
        }
    }
}

global $shortifyme_plugin;
$shortifyme_plugin = new ShortifyMe_Plugin();
