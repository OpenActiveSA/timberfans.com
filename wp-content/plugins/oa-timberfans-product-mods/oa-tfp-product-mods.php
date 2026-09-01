<?php
/*
Plugin Name: Open Agency: Timberfans Product Mods
Description: Adds product banner meta box and shortcode functionality for WooCommerce products. Includes modern Gravity Forms email styling.
Version: 4.1.2
Author: Open Agency
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('OA_TFP_PLUGIN_VERSION', '4.1.2');
define('OA_TFP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OA_TFP_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Include required files
require_once OA_TFP_PLUGIN_PATH . 'includes/class-oa-tfp-core.php';
require_once OA_TFP_PLUGIN_PATH . 'includes/class-oa-tfp-admin.php';
require_once OA_TFP_PLUGIN_PATH . 'includes/class-oa-tfp-shortcodes.php';
require_once OA_TFP_PLUGIN_PATH . 'includes/class-oa-tfp-variations.php';
require_once OA_TFP_PLUGIN_PATH . 'includes/class-oa-tfp-assets.php';
require_once OA_TFP_PLUGIN_PATH . 'includes/class-oa-tfp-gravity-forms.php';
require_once OA_TFP_PLUGIN_PATH . 'includes/class-oa-tfp-fix-theme-warnings.php';

// Include debug helper (optional - add ?debug_variations=1 to product URL)
// Uncomment the line below if you need to debug variation issues
// if (file_exists(OA_TFP_PLUGIN_PATH . 'debug-variation-images.php')) {
//     require_once OA_TFP_PLUGIN_PATH . 'debug-variation-images.php';
// }


// Initialize the plugin
function oa_tfp_init() {
    new OA_TFP_Core();
    
    // Initialize Gravity Forms email styling if Gravity Forms is active
    if (class_exists('GFForms')) {
        new OA_TFP_Gravity_Forms();
    }
}
add_action('plugins_loaded', 'oa_tfp_init');

// Force WooCommerce to load all variations upfront (no AJAX)
// This ensures variation data is always available for our price calculations
add_filter('woocommerce_ajax_variation_threshold', function($qty, $product) {
    return 200; // Increase threshold - most products have fewer than 200 variations
}, 10, 2);

// Also ensure variations are not treated as too large
add_filter('woocommerce_show_variation_price', '__return_true');

// Ensure variation data includes all necessary fields (especially images)
add_filter('woocommerce_available_variation', 'oa_tfp_ensure_variation_data_loads', 5, 3);

function oa_tfp_ensure_variation_data_loads($data, $product, $variation) {
    // Ensure the variation data is always returned, even if WC tries to skip it
    if (empty($data)) {
        $data = array(
            'variation_id' => $variation->get_id(),
            'attributes' => $variation->get_attributes(),
            'display_price' => $variation->get_price(),
            'is_purchasable' => $variation->is_purchasable(),
            'is_in_stock' => $variation->is_in_stock(),
            'image' => wc_get_product_attachment_props( $variation->get_image_id() ),
            'image_id' => $variation->get_image_id(),
        );
    }
    return $data;
} 