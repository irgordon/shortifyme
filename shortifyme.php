<?php
/**
 * Plugin Name:       ShortifyMe
 * Description:       A secure URL shortener with Live DNS Validation and Native WP UI.
 * Version:           3.8
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
    private $shortifyme_settings_slug  = 'shortifyme-settings';

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
        dbDelta( $sql );
    }

    public function shortifyme_deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Admin Menu
     */
    public function shortifyme_register_menu() {
        add_menu_page( 'ShortifyMe', 'ShortifyMe', 'manage_options', $this->shortifyme_menu_slug, array( $this, 'shortifyme_render_links' ), 'dashicons-admin-links', 20 );
        add_submenu_page( $this->shortifyme_menu_slug, 'Manage Links', 'Links', 'manage_options', $this->shortifyme_menu_slug, array( $this, 'shortifyme_render_links' ) );
        add_submenu_page( $this->shortifyme_menu_slug, 'ShortifyMe Settings', 'Settings', 'manage_options', $this->shortifyme_settings_slug, array( $this, 'shortifyme_render_settings' ) );
    }

    /**
     * Settings Initialization
     */
    public function shortifyme_settings_init() {
        register_setting( 'shortifyme_options_group', $this->shortifyme_option_name, array( 'sanitize_callback' => 'esc_url_raw' ) );

        // Section 1: Domain Input
        add_settings_section( 'shortifyme_main_section', 'General Configuration', array( $this, 'shortifyme_section_cb' ), $this->shortifyme_settings_slug );
        add_settings_field( 'shortifyme_domain_field', 'Domain:', array( $this, 'shortifyme_field_render' ), $this->shortifyme_settings_slug, 'shortifyme_main_section' );
    }

    public function shortifyme_section_cb() {
        echo '<p>Configure the custom domain you wish to use for your short links.</p>';
    }

    /**
     * Render the Domain Field with Logic to Check DNS
     */
    public function shortifyme_field_render() {
        $value = get_option( $this->shortifyme_option_name );
        
        // 1. Determine Current Server IP (The IP this WordPress install is on)
        // We use gethostbyname on the current domain to ensure we get the public-facing IP
        $current_wp_host = parse_url( home_url(), PHP_URL_HOST );
        $server_ip       = gethostbyname( $current_wp_host );

        // 2. Determine Custom Domain Status
        $custom_host = '';
        $custom_ip   = '';
        $status_icon = '';
        $status_msg  = '';
        $status_color= '';

        if ( ! empty( $value ) ) {
            $custom_host = parse_url( $value, PHP_URL_HOST );
            
            // Check if valid host was parsed
            if ( $custom_host ) {
                $custom_ip = gethostbyname( $custom_host );

                if ( $custom_ip === $server_ip ) {
                    // MATCH: GREEN CHECK
                    $status_icon  = 'dashicons-yes-alt';
                    $status_color = '#00a32a'; // Green
                    $status_msg   = "Connected! Domain points to your Server IP ($custom_ip).";
                } else {
                    // MISMATCH: RED X
                    $status_icon  = 'dashicons-dismiss';
                    $status_color = '#d63638'; // Red
                    $status_msg   = "Error: Domain points to $custom_ip, but your Server IP is $server_ip.";
                }
            } else {
                $status_msg = "Invalid Domain URL.";
            }
        } else {
            // No domain set
            $status_msg = "Using default WordPress URL.";
        }
        ?>
        
        <input type="url" 
               name="<?php echo esc_attr( $this->shortifyme_option_name ); ?>" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="regular-text" 
               placeholder="<?php echo esc_attr( home_url() ); ?>">
        <p class="description">Enter your custom Short Domain (FQDN), e.g., <code>https://link.mysite.com</code>.</p>
        
        <?php if ( ! empty( $value ) ) : ?>
        <div style="margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid <?php echo $status_color; ?>; display: inline-block;">
            <strong style="font-size:1.1em; color: <?php echo $status_color; ?>;">
                <span class="dashicons <?php echo $status_icon; ?>" style="vertical-align: text-bottom;"></span>
                <?php echo esc_html( $custom_host ); ?>
            </strong>
            <p style="margin: 5px 0 0 0; color: #646970;">
                <?php echo esc_html( $status_msg ); ?>
            </p>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Page: Settings (With Dynamic DNS Table)
     */
    public function shortifyme_render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        // Dynamic Variables for Instructions
        $current_wp_host = parse_url( home_url(), PHP_URL_HOST );
        $server_ip       = gethostbyname( $current_wp_host ); // The IP user needs to point to
        
        $custom_url      = get_option( $this->shortifyme_option_name );
        $custom_host     = !empty($custom_url) ? parse_url($custom_url, PHP_URL_HOST) : 'example.com';
        
        // Determine if using root domain (@) or subdomain
        $host_parts = explode('.', $custom_host);
        $dns_host_record = (count($host_parts) > 2) ? $host_parts[0] : '@'; 

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php settings_errors(); ?>
            
            <form action="options.php" method="post">
                <?php
                settings_fields( 'shortifyme_options_group' );
                do_settings_sections( $this->shortifyme_settings_slug );
                submit_button( 'Save Settings' );
                ?>
            </form>

            <hr style="margin: 30px 0;">

            <h2>DNS Configuration Instructions</h2>
            <p>
                To use <strong><?php echo esc_html($custom_host); ?></strong>, log in to your Domain Registrar (e.g., GoDaddy, Namecheap) 
                and add the following <strong>A Record</strong> to point to this WordPress installation.
            </p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="manage-column">Record Type</th>
                        <th class="manage-column">Host / Name</th>
                        <th class="manage-column">Priority</th>
                        <th class="manage-column">TTL</th>
                        <th class="manage-column">Data / Value (Server IP)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>A Record</strong></td>
                        <td><code><?php echo esc_html( $dns_host_record ); ?></code></td>
                        <td>N/A</td>
                        <td>3600 (Automatic)</td>
                        <td>
                            <code><?php echo esc_html( $server_ip ); ?></code>
                            <span class="dashicons dashicons-admin-site-alt3" title="This is your current Server IP" style="color:#666; cursor:help;"></span>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th class="manage-column">Record Type</th>
                        <th class="manage-column">Host / Name</th>
                        <th class="manage-column">Priority</th>
                        <th class="manage-column">TTL</th>
                        <th class="manage-column">Data / Value (Server IP)</th>
                    </tr>
                </tfoot>
            </table>
            <p class="description" style="margin-top:10px;">
                <em>Note: DNS propagation can take up to 24 hours. The verification icon above will turn Green once the update is detected.</em>
            </p>
        </div>
        <?php
    }

    /**
     * Page: Links
     */
    public function shortifyme_render_links() {
        global $wpdb;
        $base_domain = get_option( $this->shortifyme_option_name, home_url() );
        $errors = array();

        // Add Link
        if ( isset( $_POST['shortifyme_submit'] ) && check_admin_referer( 'shortifyme_new_link_nonce' ) ) {
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
                $wpdb->insert( $this->shortifyme_table_name, array( 'title' => $title, 'original_url' => $url, 'short_code' => $slug, 'created_at' => current_time( 'mysql' ) ), array( '%s', '%s', '%s', '%s' ) );
                wp_redirect( add_query_arg( 'shortifyme_status', 'created', menu_page_url( $this->shortifyme_menu_slug, false ) ) );
                exit;
            }
        }

        // Delete Link
        if ( isset( $_GET['delete'] ) && check_admin_referer( 'shortifyme_delete_link_nonce' ) ) {
            $wpdb->delete( $this->shortifyme_table_name, array( 'id' => absint( $_GET['delete'] ) ), array( '%d' ) );
            wp_redirect( add_query_arg( 'shortifyme_status', 'deleted', menu_page_url( $this->shortifyme_menu_slug, false ) ) );
            exit;
        }

        // Sorting
        $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'created_at';
        $order   = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( $_GET['order'] ) ) : 'DESC';
        $allowed = array( 'title', 'short_code', 'original_url', 'clicks', 'created_at' );
        if ( ! in_array( $orderby, $allowed ) ) $orderby = 'created_at';
        if ( ! in_array( $order, array('ASC', 'DESC') ) ) $order = 'DESC';

        $results = $wpdb->get_results( "SELECT * FROM $this->shortifyme_table_name ORDER BY $orderby $order" );
        
        $new_order = ($order === 'ASC') ? 'desc' : 'asc';
        $base_link = menu_page_url( $this->shortifyme_menu_slug, false );
        $header_link = function($col, $lbl) use ($base_link, $orderby, $new_order, $order) {
            $arrow = ($orderby === $col) ? (($order === 'ASC') ? ' &#9650;' : ' &#9660;') : '';
            return '<a href="' . esc_url( add_query_arg( array('orderby' => $col, 'order' => $new_order), $base_link ) ) . '">' . esc_html( $lbl ) . $arrow . '</a>';
        };

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">ShortifyMe Links</h1>
            <a href="#" class="page-title-action" onclick="document.getElementById('shortifyme-add-wrapper').style.display = (document.getElementById('shortifyme-add-wrapper').style.display === 'none' ? 'block' : 'none'); return false;">Add New Link</a>
            <hr class="wp-header-end">

            <?php 
            if ( isset( $_GET['shortifyme_status'] ) && $_GET['shortifyme_status'] === 'created' ) echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> New link created.</p></div>';
            if ( isset( $_GET['shortifyme_status'] ) && $_GET['shortifyme_status'] === 'deleted' ) echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Link deleted.</p></div>';
            if ( ! empty( $errors ) ) foreach ( $errors as $err ) echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html( $err ) . '</p></div>';
            ?>
            
            <div id="shortifyme-add-wrapper" style="<?php echo ( !empty($errors) ) ? 'display:block;' : 'display:none;'; ?> background:#fff; padding:20px; margin:20px 0; border-left:4px solid #2271b1; box-shadow:0 1px 1px rgba(0,0,0,.04);">
                <h3>Create New Link</h3>
                <form method="post">
                    <?php wp_nonce_field( 'shortifyme_new_link_nonce' ); ?>
                    <table class="form-table">
                        <tr><th>Title</th><td><input type="text" name="shortifyme_title" class="regular-text" required value="<?php echo isset($_POST['shortifyme_title']) ? esc_attr($_POST['shortifyme_title']) : ''; ?>"></td></tr>
                        <tr><th>Target URL</th><td><input type="url" name="shortifyme_url" class="regular-text" required value="<?php echo isset($_POST['shortifyme_url']) ? esc_attr($_POST['shortifyme_url']) : ''; ?>"></td></tr>
                        <tr><th>Slug</th><td><input type="text" name="shortifyme_slug" class="regular-text" placeholder="Auto-generate" value="<?php echo isset($_POST['shortifyme_slug']) ? esc_attr($_POST['shortifyme_slug']) : ''; ?>"></td></tr>
                    </table>
                    <p class="submit"><input type="submit" name="shortifyme_submit" class="button button-primary" value="Create Link"></p>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="manage-column sortable <?php echo ($orderby == 'title' ? $order : 'desc'); ?>"><?php echo $header_link('title', 'Title'); ?></th>
                        <th class="manage-column sortable <?php echo ($orderby == 'short_code' ? $order : 'desc'); ?>"><?php echo $header_link('short_code', 'Slug'); ?></th>
                        <th class="manage-column sortable <?php echo ($orderby == 'original_url' ? $order : 'desc'); ?>"><?php echo $header_link('original_url', 'Target URL'); ?></th>
                        <th class="manage-column sortable <?php echo ($orderby == 'clicks' ? $order : 'desc'); ?>" style="width:80px;"><?php echo $header_link('clicks', 'Clicks'); ?></th>
                        <th class="manage-column" style="width:60px;">QR</th>
                        <th class="manage-column" style="width:100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $results ) : foreach ( $results as $row ) : 
                        $short_url = rtrim($base_domain, '/') . '/' . $row->short_code;
                        $qr_url = 'https://quickchart.io/qr?text=' . urlencode( $short_url ) . '&size=150';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row->title ); ?></strong></td>
                        <td><a href="<?php echo esc_url( $short_url ); ?>" target="_blank">/<?php echo esc_html( $row->short_code ); ?></a></td>
                        <td><code><?php echo esc_html( $row->original_url ); ?></code></td>
                        <td><?php echo number_format( $row->clicks ); ?></td>
                        <td><a href="<?php echo esc_url($qr_url); ?>" target="_blank" class="dashicons dashicons-id-alt"></a></td>
                        <td><a href="<?php echo wp_nonce_url( admin_url('admin.php?page=shortifyme&delete=' . $row->id), 'shortifyme_delete_link_nonce' ); ?>" style="color:#a00;" onclick="return confirm('Delete?')">Delete</a></td>
                    </tr>
                    <?php endforeach; else: ?><tr><td colspan="6">No links found.</td></tr><?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th class="manage-column"><?php echo $header_link('title', 'Title'); ?></th>
                        <th class="manage-column"><?php echo $header_link('short_code', 'Slug'); ?></th>
                        <th class="manage-column"><?php echo $header_link('original_url', 'Target URL'); ?></th>
                        <th class="manage-column"><?php echo $header_link('clicks', 'Clicks'); ?></th>
                        <th class="manage-column">QR</th>
                        <th class="manage-column">Actions</th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }

    /**
     * REST API
     */
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

        $wpdb->insert( $this->shortifyme_table_name, array( 'title' => $title, 'original_url' => $url, 'short_code' => $code, 'created_at' => current_time( 'mysql' ) ), array( '%s', '%s', '%s', '%s' ) );
        $final_url = rtrim( get_option( $this->shortifyme_option_name, home_url() ), '/') . '/' . $code;

        return new WP_REST_Response( array( 'success' => true, 'short_url' => $final_url, 'qr_code' => 'https://quickchart.io/qr?text=' . urlencode( $final_url ) ), 200 );
    }

    public function shortifyme_register_api() {
        register_rest_route( 'shortifyme/v1', '/shorten', array( 'methods' => 'POST', 'callback' => array( $this, 'shortifyme_api_create' ), 'permission_callback' => '__return_true' ) );
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
