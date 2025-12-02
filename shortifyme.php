<?php
/**
 * Plugin Name:       ShortifyMe
 * Description:       A secure URL shortener adhering to strict prefixing and WP standards.
 * Version:           3.5
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

/**
 * Class ShortifyMe_Plugin
 * * Best Practice: The class name is prefixed with "ShortifyMe_" to avoid 
 * collision with other plugins that might have a class named "Plugin" or "LinkManager".
 */
class ShortifyMe_Plugin {

    // Properties are encapsulated, but we prefix values where they interact with WP Core
    private $shortifyme_table_name; 
    private $shortifyme_option_name    = 'shortifyme_custom_domain';
    private $shortifyme_menu_slug      = 'shortifyme';
    private $shortifyme_settings_slug  = 'shortifyme-settings';

    public function __construct() {
        global $wpdb;
        // Best Practice: Table name relies on WP prefix + Plugin Prefix
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
     * Activation Hook
     * Function name is prefixed (though strictly not required inside a class, it helps readability)
     */
    public function shortifyme_activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Best Practice: SQL keywords uppercase, table and column names specific
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

    /**
     * Deactivation Hook
     */
    public function shortifyme_deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Admin Menu Registration
     */
    public function shortifyme_register_menu() {
        add_menu_page( 
            'ShortifyMe', 
            'ShortifyMe', 
            'manage_options', 
            $this->shortifyme_menu_slug, 
            array( $this, 'shortifyme_render_links' ), 
            'dashicons-admin-links', 
            20 
        );
        
        add_submenu_page( 
            $this->shortifyme_menu_slug, 
            'Manage Links', 
            'Links', 
            'manage_options', 
            $this->shortifyme_menu_slug, 
            array( $this, 'shortifyme_render_links' ) 
        );
        
        add_submenu_page( 
            $this->shortifyme_menu_slug, 
            'ShortifyMe Settings', 
            'Settings', 
            'manage_options', 
            $this->shortifyme_settings_slug, 
            array( $this, 'shortifyme_render_settings' ) 
        );
    }

    /**
     * Settings API Initialization
     */
    public function shortifyme_settings_init() {
        // Best Practice: Prefix the Option Group
        register_setting(
            'shortifyme_options_group', 
            $this->shortifyme_option_name,
            array( 'sanitize_callback' => 'esc_url_raw' )
        );

        // Best Practice: Prefix the Section ID
        add_settings_section(
            'shortifyme_main_section',
            'Domain Configuration',
            array( $this, 'shortifyme_section_cb' ),
            $this->shortifyme_settings_slug
        );

        // Best Practice: Prefix the Field ID
        add_settings_field(
            'shortifyme_domain_field',
            'Custom Short Domain',
            array( $this, 'shortifyme_field_render' ),
            $this->shortifyme_settings_slug,
            'shortifyme_main_section'
        );
    }

    public function shortifyme_section_cb() {
        echo '<p>Configure the Base URL used for your short links.</p>';
    }

    public function shortifyme_field_render() {
        $value = get_option( $this->shortifyme_option_name );
        ?>
        <input type="url" 
               name="<?php echo esc_attr( $this->shortifyme_option_name ); ?>" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="regular-text" 
               placeholder="<?php echo esc_attr( home_url() ); ?>">
        <p class="description">Enter the FQDN (e.g., <code>https://appl.io</code>).</p>
        <?php
    }

    /**
     * Page: Settings (Uses Options API)
     */
    public function shortifyme_render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'shortifyme_options_group' );
                do_settings_sections( $this->shortifyme_settings_slug );
                submit_button( 'Save Settings' );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Page: Links (Manual DB handling)
     */
    public function shortifyme_render_links() {
        global $wpdb;
        $base_domain = get_option( $this->shortifyme_option_name, home_url() );

        // Best Practice: Prefix POST variables to prevent conflicts with other plugins
        if ( isset( $_POST['shortifyme_submit'] ) && check_admin_referer( 'shortifyme_new_link_nonce' ) ) {
            
            $raw_url = isset($_POST['shortifyme_url']) ? wp_unslash($_POST['shortifyme_url']) : '';
            $url     = esc_url_raw( $raw_url );
            $title   = sanitize_text_field( $_POST['shortifyme_title'] ?? '' );
            $slug    = sanitize_key( $_POST['shortifyme_slug'] ?? '' );

            $errors = array();
            if ( empty( $url ) ) $errors[] = "Target URL is required.";
            if ( empty( $title ) ) $errors[] = "Title is required.";

            if ( empty( $slug ) ) {
                $slug = substr( md5( uniqid( rand(), true ) ), 0, 6 );
            } else {
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $this->shortifyme_table_name WHERE short_code = %s", $slug ) );
                if ( $exists ) $errors[] = "Slug taken.";
            }

            if ( ! empty( $errors ) ) {
                foreach ( $errors as $err ) echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
            } else {
                $wpdb->insert( 
                    $this->shortifyme_table_name, 
                    array( 'title' => $title, 'original_url' => $url, 'short_code' => $slug, 'created_at' => current_time( 'mysql' ) ), 
                    array( '%s', '%s', '%s', '%s' ) 
                );
                echo '<div class="notice notice-success is-dismissible"><p>Link created successfully!</p></div>';
            }
        }

        // Handle Delete
        if ( isset( $_GET['delete'] ) && check_admin_referer( 'shortifyme_delete_link_nonce' ) ) {
            $wpdb->delete( $this->shortifyme_table_name, array( 'id' => absint( $_GET['delete'] ) ), array( '%d' ) );
            echo '<div class="notice notice-success is-dismissible"><p>Link deleted.</p></div>';
        }

        $results = $wpdb->get_results( "SELECT * FROM $this->shortifyme_table_name ORDER BY created_at DESC" );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">ShortifyMe Links</h1>
            <a href="#" class="page-title-action" onclick="document.getElementById('shortifyme-add-wrapper').style.display='block';return false;">Add New Link</a>
            <hr class="wp-header-end">
            
            <div id="shortifyme-add-wrapper" style="display:none; background:#fff; padding:20px; margin:20px 0; border-left:4px solid #2271b1; box-shadow:0 1px 1px rgba(0,0,0,.04);">
                <h3>Create New Link</h3>
                <form method="post">
                    <?php wp_nonce_field( 'shortifyme_new_link_nonce' ); ?>
                    <table class="form-table">
                        <tr><th>Title</th><td><input type="text" name="shortifyme_title" class="regular-text" required placeholder="Campaign Name"></td></tr>
                        <tr><th>Target URL</th><td><input type="url" name="shortifyme_url" class="regular-text" required placeholder="https://example.com"></td></tr>
                        <tr><th>Slug</th><td><input type="text" name="shortifyme_slug" class="regular-text" placeholder="Auto-generate"></td></tr>
                    </table>
                    <p class="submit"><input type="submit" name="shortifyme_submit" class="button button-primary" value="Create Link"></p>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Title</th><th>Slug</th><th>Target URL</th><th>Clicks</th><th>QR</th><th>Actions</th></tr></thead>
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
                        <td>
                            <a href="<?php echo wp_nonce_url( admin_url('admin.php?page=shortifyme&delete=' . $row->id), 'shortifyme_delete_link_nonce' ); ?>" style="color:#a00;" onclick="return confirm('Delete?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; else: ?><tr><td colspan="6">No links found.</td></tr><?php endif; ?>
                </tbody>
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

// Global Variable Prefixing:
// Instead of an anonymous 'new Class()', we assign it to a unique global variable.
global $shortifyme_plugin;
$shortifyme_plugin = new ShortifyMe_Plugin();
