<?php
/**
 * Plugin Name:     Open Agency: Elements
 * Description:     Optional shortcodes and features you can toggle for performance. Built for GeneratePress theme compatibility.
 * Version:         1.2.6
 * Author:          Open Agency
 * Text Domain:     open-agency-elements
 * Domain Path:     /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin initialization

// Define plugin constants
define( 'OA_ELEMENTS_VERSION', '1.2.6' );
define( 'OA_ELEMENTS_PLUGIN_FILE', __FILE__ );
define( 'OA_ELEMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OA_ELEMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OA_ELEMENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
class OA_Elements_Plugin {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
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
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'OA Elements: Constructor called.' );
        }
        
        try {
            $this->init_hooks();
            $this->load_dependencies();
            $this->load_post_types(); // Load post types in constructor
            
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( 'OA Elements: Constructor completed successfully.' );
            }
        } catch ( Exception $e ) {
            // Log error but don't break the site
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( 'OA Elements Plugin Constructor Error: ' . $e->getMessage() );
            }
        }
    }
    
    /**
     * Cleanup function to flush output buffer
     */
    public function cleanup() {
        if ( ob_get_level() ) {
            ob_end_flush();
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
        add_action( 'wp_footer', array( $this, 'cleanup' ) );
        add_action( 'admin_footer', array( $this, 'cleanup' ) );
        
        // Handle post type registration when features are enabled/disabled
        add_action( 'update_option', array( $this, 'handle_feature_option_update' ), 10, 3 );
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        try {
            // Load shortcodes
            $this->load_shortcodes();
            
            // Load post types based on enabled features
            $this->load_post_types();
            
            // Load site loader
            if ( oa_is_feature_enabled( 'oa_enable_site_loader' ) ) {
                require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/class-oa-site-loader.php';
                new OA_Site_Loader();
            }
            
            // Load admin functionality
            if ( function_exists( 'is_admin' ) && is_admin() ) {
                require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/admin/class-oa-elements-admin.php';
                new OA_Elements_Admin();
            }
            
            // Load page options functionality
            require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/class-oa-page-options.php';
            new OA_Page_Options();
            
            // Debug: Check if post types are registered (only in debug mode)
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                $this->debug_post_type_registration();
            }
        } catch ( Exception $e ) {
            // Log error but don't break the site
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( 'OA Elements Plugin Init Error: ' . $e->getMessage() );
            }
        }
    }
    
    /**
     * Debug post type registration
     */
    private function debug_post_type_registration() {
        // Only debug if WP_DEBUG_LOG is enabled
        if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
            return;
        }
        
        // Check if post types are properly registered
        $post_types = array( 'logo', 'testimonial', 'oa_slider', 'oa_feature' );
        
        foreach ( $post_types as $post_type ) {
            $post_type_obj = get_post_type_object( $post_type );
            if ( ! $post_type_obj ) {
                error_log( 'OA Elements: Post type "' . $post_type . '" is NOT registered.' );
            } else {
                error_log( 'OA Elements: Post type "' . $post_type . '" is registered successfully.' );
            }
        }
        
        // Check if taxonomies are registered
        $taxonomies = array( 'logo_category', 'testimonial_category', 'slider_category', 'feature_category' );
        foreach ( $taxonomies as $taxonomy ) {
            $taxonomy_obj = get_taxonomy( $taxonomy );
            if ( ! $taxonomy_obj ) {
                error_log( 'OA Elements: Taxonomy "' . $taxonomy . '" is NOT registered.' );
            } else {
                error_log( 'OA Elements: Taxonomy "' . $taxonomy . '" is registered successfully.' );
            }
        }
    }
    

    

    
    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'open-agency-elements',
            false,
            dirname( OA_ELEMENTS_PLUGIN_BASENAME ) . '/languages'
        );
    }
    
    /**
     * Load dependencies
     */
    private function load_dependencies() {
        // Load core functions
        require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/functions.php';
        
        // Load utilities
        require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/class-oa-elements-utilities.php';
        
        // Load enqueue functions
        require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/class-oa-elements-enqueue.php';
    }
    
    /**
     * Load shortcodes
     */
    private function load_shortcodes() {
        $shortcodes_dir = OA_ELEMENTS_PLUGIN_DIR . 'includes/shortcodes/';
        
        $shortcodes = array(
            'button',
            'slider',
            'logo-carousel',
            'logo-grid',
            'brand-logo-carousel',
            'testimonial-carousel',
            'featured-products-carousel',
            'links',
            'title-area',
            'features',
            'faq',
        );
        
        foreach ( $shortcodes as $shortcode ) {
            $file = $shortcodes_dir . 'class-oa-' . $shortcode . '-shortcode.php';
            if ( file_exists( $file ) ) {
                require_once $file;
                
                // Instantiate the shortcode class
                $class_name = 'OA_' . str_replace( '-', '_', ucwords( $shortcode, '-' ) ) . '_Shortcode';
                
                if ( class_exists( $class_name ) ) {
                    new $class_name();
                }
            }
        }
    }
    
    /**
     * Load custom post types
     */
    public function load_post_types() {
        try {
            // Only load post types for enabled features
            $post_type_features = array(
                'logo' => 'oa_enable_logo_carousel',
                'testimonial' => 'oa_enable_testimonial_carousel',
                'oa_slider' => 'oa_enable_slider_shortcode',
                'oa_feature' => 'oa_enable_features_shortcode',
                'oa_faq' => 'oa_enable_faq_shortcode',
            );
            
            foreach ( $post_type_features as $post_type => $feature ) {
                if ( oa_is_feature_enabled( $feature ) ) {
                    $this->load_specific_post_type( $post_type );
                }
            }
            
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( 'OA Elements: Post types loaded based on enabled features.' );
            }
        } catch ( Exception $e ) {
            // Log error but don't break the site
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( 'OA Elements Post Type Loading Error: ' . $e->getMessage() );
            }
        }
    }
    
    /**
     * Load specific post type based on feature
     */
    private function load_specific_post_type( $post_type ) {
        switch ( $post_type ) {
            case 'logo':
                require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/post-types/class-oa-logo-post-type.php';
                new OA_Logo_Post_Type();
                break;
                
            case 'testimonial':
                require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/post-types/class-oa-testimonial-post-type.php';
                new OA_Testimonial_Post_Type();
                break;
                
            case 'oa_slider':
                require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/post-types/class-oa-slider-post-type.php';
                new OA_Slider_Post_Type();
                break;
                
            case 'oa_feature':
                require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/post-types/class-oa-feature-post-type.php';
                new OA_Feature_Post_Type();
                break;
                
            case 'oa_faq':
                require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/post-types/class-oa-faq-post-type.php';
                new OA_FAQ_Post_Type();
                break;
        }
    }
    
    /**
     * Handle feature option updates to register/unregister post types
     */
    public function handle_feature_option_update( $option, $old_value, $new_value ) {
        // Only handle our feature options
        $feature_options = array(
            'oa_enable_logo_carousel',
            'oa_enable_testimonial_carousel',
            'oa_enable_slider_shortcode',
            'oa_enable_features_shortcode',
            'oa_enable_faq_shortcode',
        );
        
        if ( ! in_array( $option, $feature_options ) ) {
            return;
        }
        
        // Map option to post type
        $option_to_post_type = array(
            'oa_enable_logo_carousel' => 'logo',
            'oa_enable_testimonial_carousel' => 'testimonial',
            'oa_enable_slider_shortcode' => 'oa_slider',
            'oa_enable_features_shortcode' => 'oa_feature',
        );
        
        $post_type = $option_to_post_type[ $option ];
        
        if ( $new_value && ! $old_value ) {
            // Feature was enabled - register post type
            if ( ! post_type_exists( $post_type ) ) {
                $this->load_specific_post_type( $post_type );
                flush_rewrite_rules();
                
                if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    error_log( 'OA Elements: Post type "' . $post_type . '" registered after feature enable.' );
                }
            }
        } elseif ( ! $new_value && $old_value ) {
            // Feature was disabled - unregister post type
            if ( post_type_exists( $post_type ) ) {
                unregister_post_type( $post_type );
                flush_rewrite_rules();
                
                if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    error_log( 'OA Elements: Post type "' . $post_type . '" unregistered after feature disable.' );
                }
            }
        }
    }
    

    
    /**
     * Register fallback post types to ensure they are always available
     * Only used for backward compatibility with existing installations
     */
    public function register_fallback_post_types() {
        // This method is kept for backward compatibility but should not be used
        // Post types are now loaded conditionally based on enabled features
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'OA Elements: Fallback post type registration called - this should not happen in normal operation.' );
        }
    }
    
    /**
     * Register logo post type fallback
     */
    private function register_logo_post_type() {
        $labels = array(
            'name'               => __( 'Logos', 'open-agency-elements' ),
            'singular_name'      => __( 'Logo', 'open-agency-elements' ),
            'menu_name'          => __( 'Logos', 'open-agency-elements' ),
            'add_new'            => __( 'Add New', 'open-agency-elements' ),
            'add_new_item'       => __( 'Add New Logo', 'open-agency-elements' ),
            'edit_item'          => __( 'Edit Logo', 'open-agency-elements' ),
            'new_item'           => __( 'New Logo', 'open-agency-elements' ),
            'view_item'          => __( 'View Logo', 'open-agency-elements' ),
            'search_items'       => __( 'Search Logos', 'open-agency-elements' ),
            'not_found'          => __( 'No logos found', 'open-agency-elements' ),
            'not_found_in_trash' => __( 'No logos found in trash', 'open-agency-elements' ),
        );
        
        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_admin_bar'   => true,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-format-image',
            'show_in_nav_menus'   => false,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => true,
            'capability_type'     => 'post',
            'supports'            => array( 'title', 'thumbnail', 'page-attributes' ),
            'rewrite'             => false,
        );
        
        register_post_type( 'logo', $args );
        
        // Register taxonomy
        $taxonomy_labels = array(
            'name'              => __( 'Logo Categories', 'open-agency-elements' ),
            'singular_name'     => __( 'Logo Category', 'open-agency-elements' ),
            'search_items'      => __( 'Search Logo Categories', 'open-agency-elements' ),
            'all_items'         => __( 'All Logo Categories', 'open-agency-elements' ),
            'parent_item'       => __( 'Parent Logo Category', 'open-agency-elements' ),
            'parent_item_colon' => __( 'Parent Logo Category:', 'open-agency-elements' ),
            'edit_item'         => __( 'Edit Logo Category', 'open-agency-elements' ),
            'update_item'       => __( 'Update Logo Category', 'open-agency-elements' ),
            'add_new_item'      => __( 'Add New Logo Category', 'open-agency-elements' ),
            'new_item_name'     => __( 'New Logo Category Name', 'open-agency-elements' ),
            'menu_name'         => __( 'Categories', 'open-agency-elements' ),
        );
        
        $taxonomy_args = array(
            'hierarchical'      => true,
            'labels'            => $taxonomy_labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => false,
        );
        
        register_taxonomy( 'logo_category', array( 'logo' ), $taxonomy_args );
    }
    
    /**
     * Register testimonial post type fallback
     */
    private function register_testimonial_post_type() {
        $labels = array(
            'name'               => __( 'Testimonials', 'open-agency-elements' ),
            'singular_name'      => __( 'Testimonial', 'open-agency-elements' ),
            'menu_name'          => __( 'Testimonials', 'open-agency-elements' ),
            'add_new'            => __( 'Add New', 'open-agency-elements' ),
            'add_new_item'       => __( 'Add New Testimonial', 'open-agency-elements' ),
            'edit_item'          => __( 'Edit Testimonial', 'open-agency-elements' ),
            'new_item'           => __( 'New Testimonial', 'open-agency-elements' ),
            'view_item'          => __( 'View Testimonial', 'open-agency-elements' ),
            'search_items'       => __( 'Search Testimonials', 'open-agency-elements' ),
            'not_found'          => __( 'No testimonials found', 'open-agency-elements' ),
            'not_found_in_trash' => __( 'No testimonials found in trash', 'open-agency-elements' ),
        );
        
        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_admin_bar'   => true,
            'menu_position'       => 21,
            'menu_icon'           => 'dashicons-format-quote',
            'show_in_nav_menus'   => false,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => true,
            'capability_type'     => 'post',
            'supports'            => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
            'rewrite'             => false,
        );
        
        register_post_type( 'testimonial', $args );
        
        // Register taxonomy
        $taxonomy_labels = array(
            'name'              => __( 'Testimonial Categories', 'open-agency-elements' ),
            'singular_name'     => __( 'Testimonial Category', 'open-agency-elements' ),
            'search_items'      => __( 'Search Testimonial Categories', 'open-agency-elements' ),
            'all_items'         => __( 'All Testimonial Categories', 'open-agency-elements' ),
            'parent_item'       => __( 'Parent Testimonial Category', 'open-agency-elements' ),
            'parent_item_colon' => __( 'Parent Testimonial Category:', 'open-agency-elements' ),
            'edit_item'         => __( 'Edit Testimonial Category', 'open-agency-elements' ),
            'update_item'       => __( 'Update Testimonial Category', 'open-agency-elements' ),
            'add_new_item'      => __( 'Add New Testimonial Category', 'open-agency-elements' ),
            'new_item_name'     => __( 'New Testimonial Category Name', 'open-agency-elements' ),
            'menu_name'         => __( 'Categories', 'open-agency-elements' ),
        );
        
        $taxonomy_args = array(
            'hierarchical'      => true,
            'labels'            => $taxonomy_labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => false,
        );
        
        register_taxonomy( 'testimonial_category', array( 'testimonial' ), $taxonomy_args );
    }
    
    /**
     * Register slider post type fallback
     */
    private function register_slider_post_type() {
        $labels = array(
            'name'               => __( 'Sliders', 'open-agency-elements' ),
            'singular_name'      => __( 'Slider', 'open-agency-elements' ),
            'menu_name'          => __( 'Sliders', 'open-agency-elements' ),
            'add_new'            => __( 'Add New', 'open-agency-elements' ),
            'add_new_item'       => __( 'Add New Slider', 'open-agency-elements' ),
            'edit_item'          => __( 'Edit Slider', 'open-agency-elements' ),
            'new_item'           => __( 'New Slider', 'open-agency-elements' ),
            'view_item'          => __( 'View Slider', 'open-agency-elements' ),
            'search_items'       => __( 'Search Sliders', 'open-agency-elements' ),
            'not_found'          => __( 'No sliders found', 'open-agency-elements' ),
            'not_found_in_trash' => __( 'No sliders found in trash', 'open-agency-elements' ),
        );
        
        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_admin_bar'   => true,
            'menu_position'       => 22,
            'menu_icon'           => 'dashicons-slides',
            'show_in_nav_menus'   => false,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => true,
            'capability_type'     => 'post',
            'supports'            => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
            'rewrite'             => false,
        );
        
        register_post_type( 'oa_slider', $args );
        
        // Register taxonomy
        $taxonomy_labels = array(
            'name'              => __( 'Slider Categories', 'open-agency-elements' ),
            'singular_name'     => __( 'Slider Category', 'open-agency-elements' ),
            'search_items'      => __( 'Search Slider Categories', 'open-agency-elements' ),
            'all_items'         => __( 'All Slider Categories', 'open-agency-elements' ),
            'parent_item'       => __( 'Parent Slider Category', 'open-agency-elements' ),
            'parent_item_colon' => __( 'Parent Slider Category:', 'open-agency-elements' ),
            'edit_item'         => __( 'Edit Slider Category', 'open-agency-elements' ),
            'update_item'       => __( 'Update Slider Category', 'open-agency-elements' ),
            'add_new_item'      => __( 'Add New Slider Category', 'open-agency-elements' ),
            'new_item_name'     => __( 'New Slider Category Name', 'open-agency-elements' ),
            'menu_name'         => __( 'Categories', 'open-agency-elements' ),
        );
        
        $taxonomy_args = array(
            'hierarchical'      => true,
            'labels'            => $taxonomy_labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => false,
        );
        
        register_taxonomy( 'slider_category', array( 'oa_slider' ), $taxonomy_args );
    }
    
    /**
     * Register feature post type fallback
     */
    private function register_feature_post_type() {
        $labels = array(
            'name'               => __( 'Features', 'open-agency-elements' ),
            'singular_name'      => __( 'Feature', 'open-agency-elements' ),
            'menu_name'          => __( 'Features', 'open-agency-elements' ),
            'add_new'            => __( 'Add New', 'open-agency-elements' ),
            'add_new_item'       => __( 'Add New Feature', 'open-agency-elements' ),
            'edit_item'          => __( 'Edit Feature', 'open-agency-elements' ),
            'new_item'           => __( 'New Feature', 'open-agency-elements' ),
            'view_item'          => __( 'View Feature', 'open-agency-elements' ),
            'search_items'       => __( 'Search Features', 'open-agency-elements' ),
            'not_found'          => __( 'No features found', 'open-agency-elements' ),
            'not_found_in_trash' => __( 'No features found in trash', 'open-agency-elements' ),
        );
        
        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_admin_bar'   => true,
            'menu_position'       => 23,
            'menu_icon'           => 'dashicons-star-filled',
            'show_in_nav_menus'   => false,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => true,
            'capability_type'     => 'post',
            'supports'            => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
            'rewrite'             => false,
        );
        
        register_post_type( 'oa_feature', $args );
        
        // Register taxonomy
        $taxonomy_labels = array(
            'name'              => __( 'Feature Categories', 'open-agency-elements' ),
            'singular_name'     => __( 'Feature Category', 'open-agency-elements' ),
            'search_items'      => __( 'Search Feature Categories', 'open-agency-elements' ),
            'all_items'         => __( 'All Feature Categories', 'open-agency-elements' ),
            'parent_item'       => __( 'Parent Feature Category', 'open-agency-elements' ),
            'parent_item_colon' => __( 'Parent Feature Category:', 'open-agency-elements' ),
            'edit_item'         => __( 'Edit Feature Category', 'open-agency-elements' ),
            'update_item'       => __( 'Update Feature Category', 'open-agency-elements' ),
            'add_new_item'      => __( 'Add New Feature Category', 'open-agency-elements' ),
            'new_item_name'     => __( 'New Feature Category Name', 'open-agency-elements' ),
            'menu_name'         => __( 'Categories', 'open-agency-elements' ),
        );
        
        $taxonomy_args = array(
            'hierarchical'      => true,
            'labels'            => $taxonomy_labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => false,
        );
        
        register_taxonomy( 'feature_category', array( 'oa_feature' ), $taxonomy_args );
    }
    
    /**
     * Register FAQ post type fallback
     */
    private function register_faq_post_type() {
        $labels = array(
            'name'               => __( 'FAQs', 'open-agency-elements' ),
            'singular_name'      => __( 'FAQ', 'open-agency-elements' ),
            'menu_name'          => __( 'FAQs', 'open-agency-elements' ),
            'add_new'            => __( 'Add New', 'open-agency-elements' ),
            'add_new_item'       => __( 'Add New FAQ', 'open-agency-elements' ),
            'edit_item'          => __( 'Edit FAQ', 'open-agency-elements' ),
            'new_item'           => __( 'New FAQ', 'open-agency-elements' ),
            'view_item'          => __( 'View FAQ', 'open-agency-elements' ),
            'search_items'       => __( 'Search FAQs', 'open-agency-elements' ),
            'not_found'          => __( 'No FAQs found', 'open-agency-elements' ),
            'not_found_in_trash' => __( 'No FAQs found in trash', 'open-agency-elements' ),
        );
        
        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_admin_bar'   => true,
            'menu_position'       => 24,
            'menu_icon'           => 'dashicons-format-chat',
            'show_in_nav_menus'   => false,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => true,
            'capability_type'     => 'post',
            'supports'            => array( 'title', 'editor', 'page-attributes' ),
            'rewrite'             => false,
        );
        
        register_post_type( 'oa_faq', $args );
        
        // Register taxonomy
        $taxonomy_labels = array(
            'name'              => __( 'FAQ Categories', 'open-agency-elements' ),
            'singular_name'     => __( 'FAQ Category', 'open-agency-elements' ),
            'search_items'      => __( 'Search FAQ Categories', 'open-agency-elements' ),
            'all_items'         => __( 'All FAQ Categories', 'open-agency-elements' ),
            'parent_item'       => __( 'Parent FAQ Category', 'open-agency-elements' ),
            'parent_item_colon' => __( 'Parent FAQ Category:', 'open-agency-elements' ),
            'edit_item'         => __( 'Edit FAQ Category', 'open-agency-elements' ),
            'update_item'       => __( 'Update FAQ Category', 'open-agency-elements' ),
            'add_new_item'      => __( 'Add New FAQ Category', 'open-agency-elements' ),
            'new_item_name'     => __( 'New FAQ Category Name', 'open-agency-elements' ),
            'menu_name'         => __( 'Categories', 'open-agency-elements' ),
        );
        
        $taxonomy_args = array(
            'hierarchical'      => true,
            'labels'            => $taxonomy_labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => false,
        );
        
        register_taxonomy( 'faq_category', array( 'oa_faq' ), $taxonomy_args );
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        OA_Elements_Enqueue::enqueue_frontend_assets();
        OA_Elements_Enqueue::enqueue_conditional_assets();
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_enqueue_scripts() {
        OA_Elements_Enqueue::enqueue_admin_assets();
    }
}

/**
 * Initialize plugin
 */
function oa_elements_init() {
    // Safety check - ensure WordPress is ready
    if ( ! function_exists( 'add_action' ) ) {
        return;
    }
    
    // Prevent output before headers
    if ( ! headers_sent() ) {
        // Start output buffering to prevent headers already sent errors
        ob_start();
    }
    
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( 'OA Elements: Plugin initialization started.' );
    }
    
    try {
        $instance = OA_Elements_Plugin::get_instance();
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'OA Elements: Plugin initialization completed successfully.' );
        }
        return $instance;
    } catch ( Exception $e ) {
        // Log error but don't break the site
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'OA Elements Plugin Error: ' . $e->getMessage() );
        }
        return null;
    }
}

// Initialize plugin at the right time
add_action( 'plugins_loaded', 'oa_elements_init', 0 );

/**
 * Activation hook
 */
register_activation_hook( __FILE__, 'oa_elements_activate' );

function oa_elements_activate() {
    try {
        // Load functions first to access helper functions
        require_once OA_ELEMENTS_PLUGIN_DIR . 'includes/functions.php';
        
        // Get all available features
        $all_features = oa_get_all_features();
        
        // Check if this is a fresh installation
        $is_fresh_install = oa_is_fresh_installation();
        
        if ( $is_fresh_install ) {
            // Fresh installation - set all features to disabled by default
            foreach ( $all_features as $feature ) {
                add_option( $feature, 0 );
            }
        } else {
            // Update - only set options that don't exist yet
            foreach ( $all_features as $feature ) {
                if ( ! get_option( $feature ) ) {
                    add_option( $feature, 0 );
                }
            }
        }
        
        // Set plugin version
        update_option( 'oa_elements_version', OA_ELEMENTS_VERSION );
        
        // Handle migration for existing installations
        $previous_version = get_option( 'oa_elements_previous_version' );
        if ( $previous_version && version_compare( $previous_version, '1.1.0', '<' ) ) {
            // This is an update from a previous version
            // Keep existing settings as they were
            foreach ( $all_features as $feature ) {
                if ( ! get_option( $feature ) ) {
                    // For existing installations, set reasonable defaults
                    $default_enabled = array(
                        'oa_enable_button_shortcode',
                        'oa_enable_slider_shortcode',
                        'oa_enable_logo_carousel',
                        'oa_enable_testimonial_carousel',
                        'oa_enable_features_shortcode',
                    );
                    
                    $default_value = in_array( $feature, $default_enabled ) ? 1 : 0;
                    add_option( $feature, $default_value );
                }
            }
        }
        
        // Store current version as previous for next update
        update_option( 'oa_elements_previous_version', OA_ELEMENTS_VERSION );
        
        // Note: Post types are now loaded conditionally based on enabled features
        // They will be registered when the plugin initializes and features are enabled
        
        // Flush rewrite rules for any existing post types
        flush_rewrite_rules();
    } catch ( Exception $e ) {
        // Log error but don't break activation
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'OA Elements Activation Error: ' . $e->getMessage() );
        }
    }
}

/**
 * Deactivation hook
 */
register_deactivation_hook( __FILE__, 'oa_elements_deactivate' );

function oa_elements_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
}

