<?php
/**
 * Assets Class
 *
 * Handles CSS and JavaScript asset enqueuing
 *
 * @package OA_TimberFans_GF_Mod
 * @since 1.0.0
 */

// Prevent direct access
defined( 'ABSPATH' ) || exit;

/**
 * OA_TF_Assets class
 */
class OA_TF_Assets {
    
    /**
     * Tracks whether assets have already been enqueued.
     *
     * @var bool
     */
    private $assets_enqueued = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_filter( 'gform_pre_render', array( $this, 'ensure_assets_from_form' ), 10, 2 );
        add_filter( 'gform_field_css_class', array( $this, 'add_field_classes' ), 10, 3 );
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only enqueue on pages with Gravity Forms
        if ( ! $this->should_enqueue_assets() ) {
            return;
        }
        
        $this->enqueue_assets();
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets() {
        // Only enqueue on Gravity Forms admin pages
        if ( ! $this->is_gravity_forms_admin() ) {
            return;
        }
        
        $this->enqueue_css();
    }
    
    /**
     * Hooked into gform_pre_render so assets load whenever the form renders,
     * even via the block editor or AJAX.
     *
     * @param array $form Form data.
     * @param bool  $is_ajax Whether the form is rendering via AJAX.
     * @return array
     */
    public function ensure_assets_from_form( $form, $is_ajax ) {
        $this->enqueue_assets();
        return $form;
    }
    
    /**
     * Enqueue both CSS and JS (only once, unless a later dequeue stripped them).
     */
    private function enqueue_assets() {
        $style_ok  = wp_style_is( 'oa-timberfans-gf-mod', 'enqueued' );
        $script_ok = wp_script_is( 'oa-timberfans-gf-mod', 'enqueued' );

        if ( $this->assets_enqueued && $style_ok && $script_ok ) {
            return;
        }

        $this->enqueue_css();
        $this->enqueue_js();
        $this->assets_enqueued = true;
    }

    /**
     * Add swatch/chip classes in PHP so the popup is styled even if JS is delayed.
     *
     * @param string $classes Space-separated CSS classes.
     * @param object $field   Gravity Forms field.
     * @param array  $form    Gravity Forms form.
     * @return string
     */
    public function add_field_classes( $classes, $field, $form ) {
        $form_id = 0;
        if ( is_array( $form ) && isset( $form['id'] ) ) {
            $form_id = (int) $form['id'];
        } elseif ( is_object( $form ) && isset( $form->id ) ) {
            $form_id = (int) $form->id;
        }

        if ( $form_id !== 3 ) {
            return $classes;
        }

        $field_id = isset( $field->id ) ? (int) $field->id : 0;

        if ( in_array( $field_id, array( 1, 5, 6 ), true ) ) {
            $classes .= ' oa-tf-grid-field oa-tf-field';
            if ( in_array( $field_id, array( 5, 6 ), true ) ) {
                $classes .= ' oa-tf-grid-field--6col';
            }
        }

        if ( in_array( $field_id, array( 4, 7 ), true ) ) {
            $classes .= ' oa-tf-flex-field oa-tf-field';
        }

        return $classes;
    }

    /**
     * Enqueue CSS
     */
    private function enqueue_css() {
        wp_enqueue_style(
            'oa-timberfans-gf-mod',
            OA_TF_GF_MOD_PLUGIN_URL . 'oa-timberfans-gf-mod.css',
            array(),
            OA_TF_GF_MOD_VERSION
        );
    }
    
    /**
     * Enqueue JavaScript
     */
    private function enqueue_js() {
        wp_enqueue_script(
            'oa-timberfans-gf-mod',
            OA_TF_GF_MOD_PLUGIN_URL . 'oa-timberfans-gf-mod.js',
            array( 'jquery' ),
            OA_TF_GF_MOD_VERSION,
            true
        );
        
        $this->localize_script();
    }
    
    /**
     * Localize script with data
     */
    private function localize_script() {
        $localize_data = array(
            'ajax_url'           => admin_url( 'admin-ajax.php' ),
            'form_id'            => 3,
            'field_product'      => 1,
            'field_size'         => 4,
            'field_speed_regulator' => 7,
            'nonce'              => wp_create_nonce( 'oa_tf_nonce' ),
        );
        
        wp_localize_script(
            'oa-timberfans-gf-mod',
            'OATF',
            $localize_data
        );
    }
    
    /**
     * Check if assets should be enqueued
     *
     * @return bool Whether to enqueue assets
     */
    private function should_enqueue_assets() {
        // Check if we're on a page with Gravity Forms
        if ( ! class_exists( 'GFCommon' ) ) {
            return false;
        }
        
        // Check if we're on a page with our specific form
        global $post;
        if ( $post ) {
            // Classic shortcode usage.
            if ( has_shortcode( $post->post_content, 'gravityform' ) ) {
                return true;
            }

            // Blocks-based Gravity Forms.
            if ( function_exists( 'has_block' ) && has_block( 'gravityforms/form', $post ) ) {
                return true;
            }
        }

        // Always load on the quote page (slug or designated page ID).
        $quote_page_id = absint( get_option( 'oa_tfp_quote_page' ) );
        if ( is_page( 'quote' ) || ( $quote_page_id && is_page( $quote_page_id ) ) ) {
            return true;
        }

        // Always load on WooCommerce product pages where the popup can be triggered.
        if ( function_exists( 'is_product' ) && is_product() ) {
            return true;
        }
        
        // Check if we're on a page with Gravity Forms
        if ( function_exists( 'gravity_form' ) ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if we're on Gravity Forms admin page
     *
     * @return bool Whether on GF admin page
     */
    private function is_gravity_forms_admin() {
        if ( ! class_exists( 'GFCommon' ) ) {
            return false;
        }
        
        $screen = get_current_screen();
        if ( ! $screen ) {
            return false;
        }
        
        // Check for Gravity Forms admin pages
        $gf_screens = array(
            'toplevel_page_gf_edit_forms',
            'gravity-forms_page_gf_edit_forms',
            'gravity-forms_page_gf_entries',
            'gravity-forms_page_gf_settings',
        );
        
        return in_array( $screen->id, $gf_screens, true );
    }
} 