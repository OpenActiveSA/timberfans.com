<?php
/**
 * Plugin Name:     Open Agency: TimberFans GF Mod
 * Description:     Dynamically populates Nested Form #3 with WooCommerce products & fan size options.
 * Version:         1.0.4
 * Author:          Open Agency
 * Text Domain:     oa-timberfans-gf-mod
 * License:         GPL v2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to:   6.4
 * Requires PHP:    7.4
 * Network:         false
 */

// Prevent direct access
defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'OA_TF_GF_MOD_VERSION', '1.0.4' );
define( 'OA_TF_GF_MOD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OA_TF_GF_MOD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OA_TF_GF_MOD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
class OA_TimberFans_GF_Mod {
    
    /**
     * Plugin instance
     *
     * @var OA_TimberFans_GF_Mod
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     *
     * @return OA_TimberFans_GF_Mod
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'plugins_loaded', array( $this, 'load_dependencies' ) );
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain( 'oa-timberfans-gf-mod', false, dirname( OA_TF_GF_MOD_PLUGIN_BASENAME ) . '/languages' );
        
        // Check if WooCommerce is active
        if ( ! $this->is_woocommerce_active() ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }
        
        // Check if Gravity Forms is active
        if ( ! $this->is_gravity_forms_active() ) {
            add_action( 'admin_notices', array( $this, 'gravity_forms_missing_notice' ) );
            return;
        }
    }
    
    /**
     * Load plugin dependencies
     */
    public function load_dependencies() {
        // Include required files
        require_once OA_TF_GF_MOD_PLUGIN_DIR . 'includes/class-oa-tf-form-populator.php';
        require_once OA_TF_GF_MOD_PLUGIN_DIR . 'includes/class-oa-tf-ajax-handler.php';
        require_once OA_TF_GF_MOD_PLUGIN_DIR . 'includes/class-oa-tf-assets.php';
        
        // Initialize classes
        new OA_TF_Form_Populator();
        new OA_TF_Ajax_Handler();
        new OA_TF_Assets();
    }
    
    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    private function is_woocommerce_active() {
        return class_exists( 'WooCommerce' );
    }
    
    /**
     * Check if Gravity Forms is active
     *
     * @return bool
     */
    private function is_gravity_forms_active() {
        return class_exists( 'GFCommon' );
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'OA TimberFans GF Mod requires WooCommerce to be installed and activated.', 'oa-timberfans-gf-mod' ); ?></p>
        </div>
        <?php
    }
    
    /**
     * Gravity Forms missing notice
     */
    public function gravity_forms_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'OA TimberFans GF Mod requires Gravity Forms to be installed and activated.', 'oa-timberfans-gf-mod' ); ?></p>
        </div>
        <?php
    }
}

// Initialize the plugin
function oa_tf_gf_mod_init() {
    return OA_TimberFans_GF_Mod::get_instance();
}

// Start the plugin
oa_tf_gf_mod_init();
