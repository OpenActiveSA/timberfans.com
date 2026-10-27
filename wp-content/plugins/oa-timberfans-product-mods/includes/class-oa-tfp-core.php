<?php
/**
 * OA TFP Core Class
 * 
 * Main plugin class that handles initialization and core functionality
 */
class OA_TFP_Core {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
        $this->init_components();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // WooCommerce modifications
        add_action('init', array($this, 'modify_woocommerce_hooks'));
        
        // Admin settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        new OA_TFP_Admin();
        new OA_TFP_Shortcodes();
        new OA_TFP_Variations();
        new OA_TFP_Assets();
    }
    
    /**
     * Modify WooCommerce hooks
     */
    public function modify_woocommerce_hooks() {
        // Remove default WooCommerce elements
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
        
        // Remove product tabs
        add_filter('woocommerce_product_tabs', function($tabs) { 
            return array(); 
        }, 98);
        
        // Description tab is handled by custom accordion, so we don't need this filter
        

        

        
        // Keep out-of-stock variations selectable and visible
        add_filter('woocommerce_variation_is_active', array($this, 'keep_out_of_stock_variations_active'), 10, 2);
        add_filter('woocommerce_variation_is_visible', array($this, 'keep_all_variations_visible'), 10, 3);
        
        // Include out-of-stock variations in price calculation
        // FIXED: Now uses context checking to avoid breaking variation gallery
        add_filter('woocommerce_get_children', array($this, 'include_all_variations_in_price'), 10, 2);
        add_filter('woocommerce_product_is_visible', array($this, 'make_all_products_visible'), 10, 2);
        
        // Force variation data to include prices for out-of-stock items
        add_filter('woocommerce_available_variation', array($this, 'add_price_to_out_of_stock_variations'), 10, 3);
        
        // Modify price display on shop/archive pages to show "From X"
        add_filter('woocommerce_variable_sale_price_html', array($this, 'custom_variation_price'), 10, 2);
        add_filter('woocommerce_variable_price_html', array($this, 'custom_variation_price'), 10, 2);
        
        // Add custom subtotal before add to cart container
        add_action('woocommerce_before_add_to_cart_button', array($this, 'add_custom_subtotal'), 5);
        
        // Add quote button inside the add to cart container
        add_action('woocommerce_after_add_to_cart_button', array($this, 'add_quote_button'), 10);
        
        // Add variation no photo notice
        add_action('woocommerce_before_single_product_summary', array($this, 'add_variation_no_photo_notice'), 25);
        
        // Enable gallery slider for products with static images
        add_filter('woocommerce_single_product_flexslider_enabled', array($this, 'enable_gallery_slider_for_static_images'), 10, 1);
        
        // Enable navigation arrows in flexslider options for static images
        add_filter('woocommerce_single_product_carousel_options', array($this, 'enable_flexslider_navigation_for_static_images'), 10, 1);
        
        // Ensure flexslider has navigation arrows enabled for static images
        add_filter('woocommerce_single_product_image_gallery_classes', array($this, 'add_slider_class_for_static_images'), 10, 1);
        
        // Add button toggle JavaScript
        add_action('wp_footer', array($this, 'add_button_toggle_js'));
        add_action('wp_footer', array($this, 'add_quote_form_js'));
    }
    

    
    /**
     * Keep out-of-stock variations active/selectable
     */
    public function keep_out_of_stock_variations_active($active, $variation) {
        if ($variation instanceof WC_Product_Variation && !$variation->is_in_stock()) {
            return true; // force active
        }
        return $active;
    }
    
    /**
     * Keep all variations visible in dropdowns
     */
    public function keep_all_variations_visible($visible, $variation_id, $parent_id) {
        return true; // force visible
    }
    
    /**
     * Include all variations (including out-of-stock) when getting children for price calculation
     */
    public function include_all_variations_in_price($children, $product) {
        // Only apply to variable products
        if ($product && is_a($product, 'WC_Product_Variable')) {
            // Check the call stack to only apply this for price calculations
            // Don't apply it for variation data loading (which breaks the gallery)
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            $is_price_calculation = false;
            
            foreach ($backtrace as $trace) {
                if (isset($trace['function']) && 
                    in_array($trace['function'], array('get_variation_prices', 'get_price_html', 'custom_variation_price'))) {
                    $is_price_calculation = true;
                    break;
                }
            }
            
            // Only modify children when called from price calculation functions
            if ($is_price_calculation) {
                // Get all children regardless of status
                global $wpdb;
                $children = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' ORDER BY menu_order ASC, ID ASC",
                    $product->get_id()
                ));
            }
        }
        return $children;
    }
    
    /**
     * Make all products visible (including those with zero stock)
     */
    public function make_all_products_visible($visible, $product_id) {
        return true;
    }
    
    /**
     * Ensure ALL variations include price data for JavaScript (including out-of-stock)
     */
    public function add_price_to_out_of_stock_variations($variation_data, $product, $variation) {
        // Get the price - ensure it's always included
        $price = $variation->get_price();
        $regular_price = $variation->get_regular_price();
        $sale_price = $variation->get_sale_price();
        
        // Force price data to be present in variation data
        if ($price) {
            $variation_data['display_price'] = floatval($price);
        }
        if ($regular_price) {
            $variation_data['display_regular_price'] = floatval($regular_price);
        }
        if ($sale_price) {
            $variation_data['display_sale_price'] = floatval($sale_price);
        }
        
        // For out-of-stock items, ensure they're still purchasable for quote system
        if (!$variation->is_in_stock()) {
            $variation_data['is_purchasable'] = true;
        }
        
        return $variation_data;
    }
    
    /**
     * Custom price display for variable products - show "From X" instead of price range
     */
    public function custom_variation_price($price, $product) {
        // Get all variations including out of stock ones
        $variations = $product->get_available_variations();
        $prices = array();
        
        // Collect all variation prices
        foreach ($variations as $variation_data) {
            if (isset($variation_data['display_price']) && $variation_data['display_price'] > 0) {
                $prices[] = floatval($variation_data['display_price']);
            }
        }
        
        // If no prices found from available_variations, try getting children directly
        if (empty($prices)) {
            $children = $product->get_children();
            foreach ($children as $child_id) {
                $variation = wc_get_product($child_id);
                if ($variation && $variation->get_price()) {
                    $prices[] = floatval($variation->get_price());
                }
            }
        }
        
        // Get the minimum price
        if (!empty($prices)) {
            $min_price = min($prices);
            $price = sprintf(__('From %s', 'woocommerce'), wc_price($min_price));
        }
        
        return $price;
    }
    
    /**
     * Add quote button inside the add to cart container
     */
    public function add_quote_button() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        
        global $product;
        if (!($product instanceof WC_Product_Variable)) {
            return;
        }
        
        $quote_page_id = get_option('oa_tfp_quote_page');
        $quote_url = $quote_page_id ? get_permalink($quote_page_id) : '/quote/';
        
        echo '<a href="' . esc_url($quote_url) . '" class="button oa-tfp-quote-button" style="display:none;" data-quote-url="' . esc_url($quote_url) . '">Request Quote</a>';
    }
    
    /**
     * Add custom subtotal display
     */
    public function add_custom_subtotal() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        
        global $product;
        if (!($product instanceof WC_Product_Variable)) {
            return;
        }
        
        echo '<div class="oa-tfp-stock-status" style="display:none;"></div>';
        echo '<div class="oa-tfp-variation-note" style="display:none;" role="note"></div>';
        echo '<div class="oa-tfp-custom-subtotal" style="display:none;">';
        echo '<div class="oa-tfp-subtotal-line">';
        echo '<span class="oa-tfp-subtotal-label">Price:</span>';
        echo '<span class="oa-tfp-subtotal-amount"></span>';
        echo '</div>';
        echo '<div class="oa-tfp-vat-text">Inclusive of VAT ex. delivery</div>';
        echo '</div>';
    }
    
    /**
     * Add variation no photo notice
     */
    public function add_variation_no_photo_notice() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        
        global $product;
        if (!($product instanceof WC_Product_Variable)) {
            return;
        }
        
        echo '<div class="oa-tfp-no-variation-photo" style="display:none;">No Photo of this variation</div>';
    }
    
    /**
     * Enable gallery slider for products with static images
     */
    public function enable_gallery_slider_for_static_images($enabled) {
        if (!is_product()) {
            return $enabled;
        }
        
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return $enabled;
        }
        
        $static_images = get_post_meta($product->get_id(), 'oa_tfp_static_images_mode', true);
        $static_with_gallery = get_post_meta($product->get_id(), 'oa_tfp_static_with_gallery_mode', true);
        
        // Enable slider if static images mode is enabled (including static with gallery)
        if ($static_images === '1' || $static_with_gallery === '1') {
            return true;
        }
        
        return $enabled;
    }
    
    /**
     * Enable flexslider navigation arrows for static images products
     */
    public function enable_flexslider_navigation_for_static_images($options) {
        if (!is_product()) {
            return $options;
        }
        
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return $options;
        }
        
        $static_images = get_post_meta($product->get_id(), 'oa_tfp_static_images_mode', true);
        $static_with_gallery = get_post_meta($product->get_id(), 'oa_tfp_static_with_gallery_mode', true);
        
        // Enable navigation arrows ONLY for static images mode (NOT for static with gallery - thumbnails handle navigation)
        if ($static_images === '1' && $static_with_gallery !== '1') {
            $options['directionNav'] = true;
            $options['prevText'] = '';
            $options['nextText'] = '';
        } else if ($static_with_gallery === '1') {
            // Disable arrows for static with gallery mode
            $options['directionNav'] = false;
        }
        
        // Disable touch/swipe functionality on mobile
        $options['touch'] = false;
        $options['mousewheel'] = false;
        $options['keyboard'] = false;
        
        return $options;
    }
    
    /**
     * Add slider class for static images products
     */
    public function add_slider_class_for_static_images($classes) {
        if (!is_product()) {
            return $classes;
        }
        
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return $classes;
        }
        
        $static_images = get_post_meta($product->get_id(), 'oa_tfp_static_images_mode', true);
        $static_with_gallery = get_post_meta($product->get_id(), 'oa_tfp_static_with_gallery_mode', true);
        
        // Add class to enable slider navigation (including static with gallery)
        if ($static_images === '1' || $static_with_gallery === '1') {
            $classes[] = 'oa-tfp-static-images-slider';
        }
        
        return $classes;
    }
    
    
    /**
     * Add button toggle JavaScript
     */
    public function add_button_toggle_js() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        
        $quote_page_id = get_option('oa_tfp_quote_page');
        $quote_url = $quote_page_id ? get_permalink($quote_page_id) : '/quote/';
        ?>
        <script>
        jQuery(function($){
            var $form = $('form.variations_form');
            if(!$form.length) return;

            var $addToCartButton = $('.single_add_to_cart_button.button');
            var $quoteButton = $('.oa-tfp-quote-button');
            var $quantity = $('.quantity.buttons-added');
            var $customSubtotal = $('.oa-tfp-custom-subtotal');
            var $subtotalAmount = $('.oa-tfp-subtotal-amount');
            var $stockStatus = $('.oa-tfp-stock-status');
            var $variationNote = $('.oa-tfp-variation-note');
            var $noVariationPhoto = $('.oa-tfp-no-variation-photo');
                
            // Get all variations data
            var variations = $form.data('product_variations');
            var quotePageUrl = '<?php echo esc_url($quote_url); ?>';
            
            // Enhanced state management
            var state = {
                isQuoteMode: 'none',
                isAnimating: false,
                currentVariation: null,
                pendingUpdate: null,
                pendingCalculation: null
            };
            
            // Clear any pending timeouts
            if (window.oaTfpCalculationTimeout) {
                clearTimeout(window.oaTfpCalculationTimeout);
            }
            if (window.oaTfpTimeout) {
                clearTimeout(window.oaTfpTimeout);
            }
            
            // Update stock status text
            function updateStockStatus() {
                if (!state.currentVariation) {
                    $stockStatus.hide();
                    updateVariationNote();
                    return;
                }
                
                var isOutOfStock = (state.currentVariation.is_in_stock === false) ||
                                  (state.currentVariation.is_purchasable === false) ||
                                  (state.currentVariation.availability_html && /out\sof\sstock/i.test(state.currentVariation.availability_html));
                
                if (isOutOfStock) {
                    $stockStatus.text('Made to order').show();
                } else {
                    $stockStatus.text('In Stock').show();
                }

                updateVariationNote();
            }

            // Show the WooCommerce variation Description (e.g. custom paint surcharge).
            function updateVariationNote() {
                if (!$variationNote.length) {
                    return;
                }

                if (!state.currentVariation || !state.currentVariation.variation_description) {
                    $variationNote.hide().empty();
                    return;
                }

                var text = $('<div>').html(state.currentVariation.variation_description).text().replace(/\s+/g, ' ').trim();
                if (!text) {
                    $variationNote.hide().empty();
                    return;
                }

                $variationNote.text(text).show();
            }
            
            // Update variation photo notice
            function updateVariationPhotoNotice() {
                if (!state.currentVariation) {
                    $noVariationPhoto.hide();
                    return;
                }
                
                // Check if variation has an image
                var hasImage = false;
                
                // Check image_id first (most reliable)
                if (state.currentVariation.image_id && parseInt(state.currentVariation.image_id) > 0) {
                    hasImage = true;
                }
                
                // Check image object if image_id didn't work
                if (!hasImage && state.currentVariation.image) {
                    // Check if image object has src
                    if (state.currentVariation.image.src) {
                        var src = state.currentVariation.image.src;
                        // Check if src is not empty and not just a placeholder
                        if (src.length > 0 && src !== '' && !src.includes('placeholder') && !src.includes('woocommerce-placeholder')) {
                            hasImage = true;
                        }
                    }
                    // Check url as fallback
                    if (!hasImage && state.currentVariation.image.url) {
                        var url = state.currentVariation.image.url;
                        if (url.length > 0 && url !== '' && !url.includes('placeholder') && !url.includes('woocommerce-placeholder')) {
                            hasImage = true;
                        }
                    }
                }
                
                // Debug logging (can be removed later)
                if (typeof console !== 'undefined' && window.location.search.indexOf('debug_variations') > -1) {
                    console.log('Variation photo check:', {
                        variation_id: state.currentVariation.variation_id,
                        image_id: state.currentVariation.image_id,
                        image: state.currentVariation.image,
                        hasImage: hasImage
                    });
                }
                
                // Show notice if no image, hide if image exists
                if (!hasImage) {
                    $noVariationPhoto.show();
                    // Ensure it's positioned correctly
                    setTimeout(positionNoPhotoNotice, 50);
                } else {
                    $noVariationPhoto.hide();
                }
            }
            
            // Custom subtotal calculation
            function calculateCustomSubtotal() {
                // Clear any pending calculation
                if (window.oaTfpCalculationTimeout) {
                    clearTimeout(window.oaTfpCalculationTimeout);
                    window.oaTfpCalculationTimeout = null;
                }
                
                // Hide price when quote mode is active, but keep stock status visible
                if (state.isQuoteMode === 'quote') {
                    $customSubtotal.hide();
                    $subtotalAmount.html('');
                    // Don't hide stock status - it should still show "Made to order" for out of stock items
                    // updateStockStatus() will be called below to ensure it's shown
                    if (state.currentVariation) {
                        updateStockStatus();
                    }
                    return;
                }
                
                // Check if current variation is out of stock - hide price but show stock status
                if (state.currentVariation) {
                    var isOutOfStock = (state.currentVariation.is_in_stock === false) ||
                                      (state.currentVariation.is_purchasable === false) ||
                                      (state.currentVariation.availability_html && /out\sof\sstock/i.test(state.currentVariation.availability_html));
                    
                    // Update stock status (will show even when out of stock)
                    updateStockStatus();
                    
                    // Update variation photo notice
                    updateVariationPhotoNotice();
                    
                    if (isOutOfStock) {
                        // Hide price section but keep stock status visible
                        $customSubtotal.hide();
                        $subtotalAmount.html('');
                        return;
                    }
                } else {
                    $stockStatus.hide();
                    $variationNote.hide().empty();
                    $noVariationPhoto.hide();
                }
                
                var basePrice = 0;
                var addonTotal = 0;
                var quantity = parseInt($quantity.find('input').val()) || 1;
                
                // Get base price from current variation - try multiple sources
                if (state.currentVariation) {
                    // Priority 1: display_price (most reliable)
                    if (state.currentVariation.display_price !== undefined) {
                        basePrice = parseFloat(state.currentVariation.display_price);
                    }
                    // Priority 2: Try to get from WooCommerce variation input
                    else if (basePrice === 0) {
                        var variationId = state.currentVariation.variation_id;
                        var $variationInput = $form.find('input[name="variation_id"], input.variation_id');
                        if ($variationInput.length && $variationInput.val() == variationId) {
                            // Try to get price from variation data stored in form
                            var formVariations = $form.data('product_variations');
                            if (formVariations && Array.isArray(formVariations)) {
                                for (var i = 0; i < formVariations.length; i++) {
                                    if (formVariations[i].variation_id == variationId && formVariations[i].display_price) {
                                        basePrice = parseFloat(formVariations[i].display_price);
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Calculate add-on prices
                $('.wc-pao-addon-radio:checked').each(function() {
                    var price = $(this).data('price');
                    if (price) {
                        addonTotal += parseFloat(price);
                    }
                });
                
                // Calculate total
                var total = (basePrice + addonTotal) * quantity;
                
                // Update display - only show if in cart mode
                if (state.isQuoteMode === 'cart' && total > 0) {
                    $subtotalAmount.html('R' + total.toFixed(2));
                    $customSubtotal.show();
                    // Stock status is already updated above
                } else {
                    $customSubtotal.hide();
                    // Don't hide stock status - it should still show for out of stock items
                    // Only hide if there's no variation
                    if (!state.currentVariation) {
                        $stockStatus.hide();
                        $variationNote.hide().empty();
                    } else {
                        // Ensure stock status is still shown (for "Made to order" or "In Stock")
                        updateStockStatus();
                    }
                }
            }
            
            // Debounced version of calculateCustomSubtotal
            function debouncedCalculateCustomSubtotal() {
                if (window.oaTfpCalculationTimeout) {
                    clearTimeout(window.oaTfpCalculationTimeout);
                }
                window.oaTfpCalculationTimeout = setTimeout(function() {
                    calculateCustomSubtotal();
                }, 150);
            }
            
            // Throttle function for better performance
            function throttle(func, limit) {
                var inThrottle;
                return function() {
                    var args = arguments;
                    var context = this;
                    if (!inThrottle) {
                        func.apply(context, args);
                        inThrottle = true;
                        setTimeout(function() {
                            inThrottle = false;
                        }, limit);
                    }
                };
            }

            // Smooth UI update function
            function updateButtons(mode) {
                mode = mode || 'none';

                if (state.isAnimating) {
                    state.pendingUpdate = { mode: mode };
                    return;
                }

                if (mode === state.isQuoteMode) {
                    return;
                }

                state.isAnimating = true;
                isUpdatingButtons = true; // Prevent MutationObserver from triggering

                var complete = function() {
                    state.isAnimating = false;
                    state.isQuoteMode = mode;
                    
                    // Ensure stock status is still shown after button animation
                    if (state.currentVariation) {
                        updateStockStatus();
                    }
                    
                    // Calculate subtotal after animation completes (only for cart mode)
                    if (mode === 'cart' && state.currentVariation) {
                        setTimeout(function() {
                            calculateCustomSubtotal();
                        }, 50);
                    }

                    // Reset flag after animation completes
                    setTimeout(function() {
                        isUpdatingButtons = false;
                    }, 100);

                    if (state.pendingUpdate) {
                        var pending = state.pendingUpdate;
                        state.pendingUpdate = null;
                        updateButtons(pending.mode);
                    }
                };

                if (mode === 'quote') {
                    // Hide price when showing quote button
                    $customSubtotal.hide();
                    
                    $addToCartButton.stop().fadeOut(150);
                    $quoteButton.stop().fadeOut(150);

                    setTimeout(function() {
                        $quoteButton.stop().fadeIn(150);
                        $addToCartButton.hide();

                        $quantity.find('input').prop('disabled', true);
                        $quantity.addClass('disabled');

                        setTimeout(complete, 200);
                    }, 150);
                } else if (mode === 'cart') {
                    $addToCartButton.stop().fadeOut(150);
                    $quoteButton.stop().fadeOut(150);

                    setTimeout(function() {
                        $addToCartButton.stop().fadeIn(150);
                        $quoteButton.hide();

                        $quantity.find('input').prop('disabled', false);
                        $quantity.removeClass('disabled');
                        
                        // Price calculation will happen in complete() callback after animation

                        setTimeout(complete, 200);
                    }, 150);
                } else {
                    // Hide price when no button is shown
                    $customSubtotal.hide();
                    
                    $addToCartButton.stop().fadeOut(150, function() {
                        $(this).hide();
                    });
                    $quoteButton.stop().fadeOut(150, function() {
                        $(this).hide();
                    });

                    $quantity.find('input').prop('disabled', true);
                    $quantity.addClass('disabled');

                    setTimeout(complete, 200);
                }
            }

            // Check for unavailable variation combinations
            function checkUnavailableOptions() {
                if (!variations) return;
                
                // Get all attribute selects
                var attributeSelects = $form.find('select[name^="attribute_"]');
                var currentSelection = {};
                
                // Get current selection
                attributeSelects.each(function() {
                    var attr = $(this).attr('name').replace('attribute_', '');
                    var val = $(this).val();
                    if (val) currentSelection[attr] = val;
                });
                
                // For each attribute, check which options are available
                attributeSelects.each(function() {
                    var $select = $(this);
                    var attrName = $select.attr('name').replace('attribute_', '');
                    var currentValue = $select.val();
                    
                    // Reset all options to available
                    $select.find('option').removeClass('unavailable');
                    
                    // Check each option in this attribute
                    $select.find('option').each(function() {
                        var $option = $(this);
                        var optionValue = $option.val();
                        
                        if (!optionValue) return; // Skip empty options
                        
                        // Create a test selection with this option
                        var testSelection = Object.assign({}, currentSelection);
                        testSelection[attrName] = optionValue;
                        
                        // Check if this combination exists in variations
                        var hasMatchingVariation = false;
                        for (var i = 0; i < variations.length; i++) {
                            var variation = variations[i];
                            var matches = true;
                            
                            // Only check attributes that are set in testSelection
                            for (var attr in testSelection) {
                                var attrKey = 'attribute_' + attr;
                                if (variation.attributes[attrKey] !== testSelection[attr]) {
                                    matches = false;
                                    break;
                                }
                            }
                            
                            if (matches) {
                                hasMatchingVariation = true;
                                break;
                            }
                        }
                        
                        // Mark as unavailable if no matching variation found
                        if (!hasMatchingVariation) {
                            $option.addClass('unavailable');
                        }
                    });
                });
                
                // Also check custom variation options (timber/metal)
                checkCustomVariationOptions(currentSelection);
            }
            
            // Check custom variation options (timber/metal finishes)
            function checkCustomVariationOptions(currentSelection) {
                // Check timber options
                $('.oa-tfp-timber-option').each(function() {
                    var $option = $(this);
                    var $item = $option.closest('.oa-tfp-item');
                    var timberSlug = $item.attr('class').match(/oa-tfp-([a-z-]+)/);
                    
                    if (timberSlug) {
                        var testSelection = Object.assign({}, currentSelection);
                        testSelection['pa_timber-finish'] = timberSlug[1];
                        
                        var hasMatchingVariation = checkVariationExists(testSelection);
                        $item.toggleClass('unavailable', !hasMatchingVariation);
                    }
                });
                
                // Check metal options
                $('.oa-tfp-metal-option').each(function() {
                    var $option = $(this);
                    var $item = $option.closest('.oa-tfp-item');
                    var metalSlug = $item.attr('class').match(/oa-tfp-([a-z-]+)/);
                    
                    if (metalSlug) {
                        var testSelection = Object.assign({}, currentSelection);
                        testSelection['pa_metal-finish'] = metalSlug[1];
                        
                        var hasMatchingVariation = checkVariationExists(testSelection);
                        $item.toggleClass('unavailable', !hasMatchingVariation);
                    }
                });
            }
            
            // Helper function to check if a variation exists
            function checkVariationExists(testSelection) {
                for (var i = 0; i < variations.length; i++) {
                    var variation = variations[i];
                    var matches = true;
                    
                    for (var attr in testSelection) {
                        var attrKey = 'attribute_' + attr;
                        if (variation.attributes[attrKey] !== testSelection[attr]) {
                            matches = false;
                            break;
                        }
                    }
                    
                    if (matches) {
                        return true;
                    }
                }
                return false;
            }

            // Enhanced variation checking with recursion prevention
            var isCheckingVariation = false;
            var lastCheckSelection = null;
            var lastFoundVariationId = null;
            function checkVariation() {
                // Prevent recursive calls
                if (isCheckingVariation) {
                    return;
                }
                isCheckingVariation = true;
                
                // Get current selection and total attributes count
                var currentSelection = {};
                var totalAttributes = 0;
                $form.find('select[name^="attribute_"]').each(function() {
                    var attr = $(this).attr('name').replace('attribute_', '');
                    var val = $(this).val();
                    totalAttributes++; // Count total attributes
                    if (val) currentSelection[attr] = val;
                });
                
                // Check if selection has actually changed (prevent unnecessary processing)
                var selectionKey = JSON.stringify(currentSelection);
                // If selection hasn't changed AND we already have a variation, skip processing
                if (lastCheckSelection === selectionKey && state.currentVariation && lastFoundVariationId === state.currentVariation.variation_id) {
                    isCheckingVariation = false;
                    return;
                }
                lastCheckSelection = selectionKey;
                
                if (!variations) {
                    isCheckingVariation = false;
                    return;
                }
                
                // Check for unavailable options first (temporarily disabled)
                // checkUnavailableOptions();
                
                // Check if we have a complete selection
                var hasCompleteSelection = true;
                for (var attr in currentSelection) {
                    if (!currentSelection[attr]) {
                        hasCompleteSelection = false;
                        break;
                    }
                }
                
                if (!hasCompleteSelection || Object.keys(currentSelection).length !== totalAttributes) {
                    // Clear custom subtotal when no complete selection
                    // Only update if we had a variation before (to prevent unnecessary updates)
                    if (state.currentVariation || lastFoundVariationId) {
                        state.currentVariation = null;
                        lastFoundVariationId = null;
                        $customSubtotal.hide();
                        // Only update buttons if mode would actually change (updateButtons has its own check, but this prevents the call)
                        if (state.isQuoteMode !== 'none') {
                            updateButtons('none');
                        }
                    }
                    isCheckingVariation = false;
                    return;
                }
                
                // Find matching variation
                var foundVariation = null;
                for (var i = 0; i < variations.length; i++) {
                    var variation = variations[i];
                    var matches = true;
                    
                    for (var attr in currentSelection) {
                        var attrKey = 'attribute_' + attr;
                        if (variation.attributes[attrKey] !== currentSelection[attr]) {
                            matches = false;
                            break;
                        }
                    }
                    
                    if (matches) {
                        foundVariation = variation;
                        break;
                    }
                }
                
                if (foundVariation) {
                    // Check if this is the same variation we already processed
                    var isSameVariation = state.currentVariation && 
                                         state.currentVariation.variation_id === foundVariation.variation_id &&
                                         lastFoundVariationId === foundVariation.variation_id;
                    
                    // Store current variation for subtotal calculation
                    // Note: This uses static variation data, but found_variation event should be authoritative
                    // Only update if we don't already have a more recent variation from found_variation event
                    if (!state.currentVariation || state.currentVariation.variation_id !== foundVariation.variation_id) {
                        state.currentVariation = foundVariation;
                        lastFoundVariationId = foundVariation.variation_id;
                    }
                    
                    // Only update UI if variation actually changed
                    if (!isSameVariation) {
                        // Check if out of stock
                        var isOutOfStock = (foundVariation.is_in_stock === false) ||
                                          (foundVariation.is_purchasable === false) ||
                                          (foundVariation.availability_html && /out\sof\sstock/i.test(foundVariation.availability_html));
                        
                        var targetMode = isOutOfStock ? 'quote' : 'cart';
                        // Only update buttons if mode would actually change (updateButtons has its own check, but this prevents the call)
                        if (state.isQuoteMode !== targetMode) {
                            updateButtons(targetMode);
                        }
                        
                        // Recalculate price if we have valid variation data
                        if (!isOutOfStock && foundVariation.display_price !== undefined) {
                            setTimeout(function() {
                                calculateCustomSubtotal();
                            }, 150);
                        } else {
                            // Update stock status (shows for both in stock and out of stock)
                            updateStockStatus();
                            
                            // Update variation photo notice
                            updateVariationPhotoNotice();
                            
                            if (isOutOfStock) {
                                // Hide price but keep stock status visible
                                $customSubtotal.hide();
                                $subtotalAmount.html('');
                            }
                        }
                    }
                } else {
                    // No matching variation found - show quote button
                    // Only update if we had a variation before (to prevent unnecessary updates)
                    if (state.currentVariation || lastFoundVariationId) {
                        state.currentVariation = null;
                        lastFoundVariationId = null;
                        // Only update buttons if mode would actually change (updateButtons has its own check, but this prevents the call)
                        if (state.isQuoteMode !== 'quote') {
                            updateButtons('quote');
                        }
                        $customSubtotal.hide();
                        $stockStatus.hide();
                        $variationNote.hide().empty();
                        $noVariationPhoto.hide();
                    }
                }
                
                // Reset flag after a longer delay to allow any triggered events and DOM changes to complete
                // This prevents the MutationObserver from triggering handleVariationChange while we're still processing
                setTimeout(function() {
                    isCheckingVariation = false;
                }, 300); // Increased from 100ms to 300ms to allow DOM changes to settle
            }
            
            // Check if add to cart button is disabled by WooCommerce
            function checkAddToCartButtonState() {
                // Don't run if checkVariation is already running
                if (isCheckingVariation) {
                    return;
                }
                
                var isDisabled = $addToCartButton.hasClass('disabled') || 
                                $addToCartButton.hasClass('wc-variation-selection-needed') ||
                                $addToCartButton.prop('disabled') ||
                                $addToCartButton.is(':disabled');
                
                if (isDisabled && !state.currentVariation) {
                    updateButtons('none');
                } else {
                    // Only run variation check if button is not disabled
                    checkVariation();
                }
            }
            
            // Throttled version of check function
            var throttledCheck = throttle(checkAddToCartButtonState, 100);
            
            // Force Product Add-Ons subtotal to refresh reliably
            function refreshAddOnTotals() {
                document.body.dispatchEvent(new Event('wc_addons_refresh_totals'));
            }

            // Enhanced refresh function with multiple triggers
            function forceAddOnRefresh() {
                // Multiple refresh methods to ensure it works
                refreshAddOnTotals();
                
                // Trigger WooCommerce events that Product Add-Ons listens to
                $form.trigger('woocommerce_variation_has_changed');
                $form.trigger('woocommerce_variation_select_change');
                
                // Force a price update
                if (typeof wc_add_to_cart_variation_params !== 'undefined') {
                    $form.trigger('woocommerce_variation_select_change');
                }
                
                // Additional delay to ensure everything is processed
                setTimeout(function() {
                    refreshAddOnTotals();
                }, 100);
            }

            // Any change in add-ons should refresh totals
            document.body.addEventListener('change', function (e) {
                if (e.target.closest('.wc-pao-addon')) {
                    setTimeout(refreshAddOnTotals, 50);
                    debouncedCalculateCustomSubtotal();
                }
            });
            document.body.addEventListener('keyup', function (e) {
                if (e.target.closest('.wc-pao-addon')) {
                    setTimeout(refreshAddOnTotals, 50);
                    debouncedCalculateCustomSubtotal();
                }
            });
            
            // Listen for add-on radio button changes
            $(document).on('change', '.wc-pao-addon-radio', function() {
                debouncedCalculateCustomSubtotal();
            });
            
            // Listen for quantity changes
            $(document).on('change keyup', '.quantity input', function() {
                debouncedCalculateCustomSubtotal();
            });

            // Variation found / reset should also refresh
            document.body.addEventListener('found_variation', function() {
                setTimeout(forceAddOnRefresh, 100);
            });
            document.body.addEventListener('reset_data', function() {
                // Clear custom subtotal on reset
                state.currentVariation = null;
                $customSubtotal.hide();
                setTimeout(forceAddOnRefresh, 100);
            });

            // Flag to prevent recursive calls
            var isHandlingVariationChange = false;
            
            // Enhanced variation change handler with better debouncing
            function handleVariationChange() {
                // Prevent recursive calls
                if (isHandlingVariationChange) {
                    return;
                }
                
                // Don't trigger if checkVariation is already running
                if (isCheckingVariation) {
                    return;
                }
                
                // Clear any existing timeouts
                if (window.oaTfpTimeout) {
                    clearTimeout(window.oaTfpTimeout);
                }
                
                // Clear any pending calculations
                if (window.oaTfpCalculationTimeout) {
                    clearTimeout(window.oaTfpCalculationTimeout);
                }
                
                isHandlingVariationChange = true;
                
                // Delay to ensure WooCommerce has updated
                window.oaTfpTimeout = setTimeout(function() {
                    // Double-check that checkVariation is not running before calling throttledCheck
                    if (!isCheckingVariation) {
                        throttledCheck();
                    }
                    
                    // Force add-on refresh after variation check
                    setTimeout(forceAddOnRefresh, 150);
                    
                    // Reset flag after processing
                    setTimeout(function() {
                        isHandlingVariationChange = false;
                    }, 200);
                }, 100);
            }
            
            // Event listeners
            $(document).on('click', '.oa-tfp-option', function() {
                handleVariationChange();
            });
            
            // Quote button click handler
            $(document).on('click', '.oa-tfp-quote-button', function(e) {
                e.preventDefault();
                collectAndSubmitQuote();
            });
            
            // Collect product selections and submit to quote form
            function collectAndSubmitQuote() {
                var quoteData = {
                    product_name: $('h1.product_title').text() || 'Product',
                    fan_range: '',
                    fan_size: '',
                    timber_finish: '',
                    metal_finish: '',
                    speed_regulator: '',
                    quantity: $quantity.find('input').val() || 1,
                    price: $subtotalAmount.text() || '',
                    addons: []
                };
                
                // Get selected fan range as a stable value (product ID for matching GF values)
                var productId = $('div[id^="product-"]').attr('id');
                if (productId && productId.indexOf('product-') === 0) {
                    quoteData.fan_range = productId.replace('product-', '');
                } else {
                    // Fallback to product title
                    var productTitle = $('h1.product_title').text();
                    if (productTitle) {
                        quoteData.fan_range = productTitle;
                    }
                }
                
                // Get selected fan size from WooCommerce variations
                var fanSizeValue = $form.find('select[name="attribute_pa_fan-size"]').val();
                if (!fanSizeValue) {
                    // Try alternative attribute names
                    fanSizeValue = $form.find('select[name="attribute_pa_size"]').val();
                }
                if (!fanSizeValue) {
                    // Try to get from selected button
                    var selectedSizeButton = $('.oa-tfp-option.selected[data-attribute_name*="size"]');
                    if (selectedSizeButton.length) {
                        fanSizeValue = selectedSizeButton.data('value');
                    }
                }
                if (fanSizeValue) {
                    quoteData.fan_size = fanSizeValue;
                }
                
                // Get selected timber finish
                var selectedTimber = $('.oa-tfp-timber-option.selected');
                if (selectedTimber.length) {
                    var timberText = selectedTimber.closest('.oa-tfp-item').find('.oa-tfp-timber-label-text').text();
                    if (timberText) {
                        quoteData.timber_finish = timberText;
                    }
                }
                
                // Get selected metal finish
                var selectedMetal = $('.oa-tfp-metal-option.selected');
                if (selectedMetal.length) {
                    var metalText = selectedMetal.closest('.oa-tfp-item').find('.oa-tfp-metal-label-text').text();
                    if (metalText) {
                        quoteData.metal_finish = metalText;
                    }
                }
                
                // Get selected add-ons
                $('.wc-pao-addon-radio:checked').each(function() {
                    var addonName = $(this).closest('.wc-pao-addon').find('.wc-pao-addon-name').text();
                    var addonPrice = $(this).data('price');
                    if (addonName) {
                        quoteData.addons.push({
                            name: addonName,
                            price: addonPrice || 0
                        });
                        
                    }
                });
                
                // Get standard WooCommerce variations
                $form.find('select[name^="attribute_"]').each(function() {
                    var attrName = $(this).attr('name').replace('attribute_', '');
                    var attrValue = $(this).val();
                    if (attrValue) {
                        quoteData[attrName] = attrValue;
                    }
                });
                
                // Store data in localStorage for the quote form to access
                localStorage.setItem('timberfans_quote_data', JSON.stringify(quoteData));
                
                // Test: Log the collected data to console
                console.log('=== QUOTE DATA BEING SENT ===');
                console.log('Product Name:', quoteData.product_name);
                console.log('Fan Range:', quoteData.fan_range);
                console.log('Fan Size:', quoteData.fan_size);
                console.log('Timber Finish:', quoteData.timber_finish);
                console.log('Metal Finish:', quoteData.metal_finish);
                console.log('Quantity:', quoteData.quantity);
                console.log('Price:', quoteData.price);
                console.log('Add-ons:', quoteData.addons);
                console.log('Full Data Object:', quoteData);
                console.log('=== END QUOTE DATA ===');
                
                // Redirect to quote page
                var quoteUrl = $('.oa-tfp-quote-button').data('quote-url');
                if (quoteUrl) {
                    window.location.href = quoteUrl;
                }
            }
            
            // WooCommerce events with enhanced handling
            $form.on('found_variation', function(e, variation) {
                // Don't process variation changes if we're manually navigating images
                if (window.oaTfpNavigatingImages) {
                    return;
                }
                
                console.log('✓ WooCommerce found_variation event!', {id: variation.variation_id, price: variation.display_price});
                
                // Use the variation data directly from WooCommerce - this is the authoritative source
                state.currentVariation = variation;
                
                // Check if out of stock
                var isOutOfStock = (variation.is_in_stock === false) ||
                                  (variation.is_purchasable === false) ||
                                  (variation.availability_html && /out\sof\sstock/i.test(variation.availability_html));
                
                // Update stock status immediately (shows even when out of stock)
                updateStockStatus();
                
                // Update variation photo notice
                updateVariationPhotoNotice();
                
                // Hide price if out of stock (but stock status stays visible)
                if (isOutOfStock) {
                    $customSubtotal.hide();
                    $subtotalAmount.html('');
                }
                
                // Update buttons based on stock
                if (isOutOfStock) {
                    updateButtons('quote'); // Show quote button
                } else {
                    updateButtons('cart'); // Show add to cart
                }
                
                // Immediately recalculate price with the new variation data
                // This ensures the price updates even if updateButtons animation hasn't completed
                if (!isOutOfStock && variation.display_price !== undefined) {
                    // Force immediate price recalculation
                    setTimeout(function() {
                        calculateCustomSubtotal();
                    }, 100);
                }
                
                // Trigger add-on refresh
                setTimeout(forceAddOnRefresh, 100);
            });
            
            $form.on('reset_data', function() {
                console.log('✓ WooCommerce reset_data event');
                state.currentVariation = null;
                $customSubtotal.hide();
                $stockStatus.hide();
                $variationNote.hide().empty();
                $noVariationPhoto.hide();
                updateButtons('none');
            });
            
            // Fallback to manual checking
            $form.on('woocommerce_variation_has_changed', function() {
                handleVariationChange();
            });
            
            // Watch for variation_id input changes - this is a reliable indicator of variation selection
            var $variationInput = $form.find('input[name="variation_id"], input.variation_id');
            if ($variationInput.length) {
                var lastVariationId = $variationInput.val();
                
                // Function to check and update variation
                function checkVariationIdChange() {
                    var currentVariationId = $variationInput.val();
                    if (currentVariationId && currentVariationId !== lastVariationId) {
                        lastVariationId = currentVariationId;
                        // Variation ID changed - trigger price recalculation
                        setTimeout(function() {
                            // Try to get fresh variation data from form
                            var formVariations = $form.data('product_variations');
                            if (formVariations && Array.isArray(formVariations)) {
                                for (var i = 0; i < formVariations.length; i++) {
                                    if (formVariations[i].variation_id == currentVariationId) {
                                        // Update state with fresh variation data
                                        state.currentVariation = formVariations[i];
                                        
                                        // Check if out of stock and hide price immediately
                                        var isOutOfStock = (formVariations[i].is_in_stock === false) ||
                                                          (formVariations[i].is_purchasable === false) ||
                                                          (formVariations[i].availability_html && /out\sof\sstock/i.test(formVariations[i].availability_html));
                                        
                                        // Update stock status (shows even when out of stock)
                                        updateStockStatus();
                                        
                                        // Update variation photo notice
                                        updateVariationPhotoNotice();
                                        
                                        if (isOutOfStock) {
                                            // Hide price but keep stock status visible
                                            $customSubtotal.hide();
                                            $subtotalAmount.html('');
                                        } else {
                                            // Recalculate price only if in stock
                                            calculateCustomSubtotal();
                                        }
                                        break;
                                    }
                                }
                            }
                        }, 50);
                    }
                }
                
                // Watch for direct value changes via jQuery events
                $variationInput.on('change input', checkVariationIdChange);
                
                // Also use MutationObserver for attribute changes
                var variationIdObserver = new MutationObserver(function(mutations) {
                    checkVariationIdChange();
                });
                
                // Observe the input for value changes
                variationIdObserver.observe($variationInput[0], {
                    attributes: true,
                    attributeFilter: ['value'],
                    childList: false,
                    subtree: false
                });
                
                // Fallback: Poll every 200ms to catch any missed changes (lightweight check)
                var variationIdPollInterval = setInterval(function() {
                    checkVariationIdChange();
                }, 200);
                
                // Clean up interval when form is removed from DOM
                $form.on('remove', function() {
                    clearInterval(variationIdPollInterval);
                });
            }
            
            // Select changes (backup)
            $form.find('select[name^="attribute_"]').on('change', function() {
                handleVariationChange();
            });
            
            // Flag to prevent observer from triggering during our own updates
            var isUpdatingButtons = false;
            
            // Monitor for changes to the add to cart button state
            var observer = new MutationObserver(function(mutations) {
                // Don't trigger if we're already handling a change or updating buttons ourselves
                if (isHandlingVariationChange || isUpdatingButtons || isCheckingVariation) {
                    return;
                }
                
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && 
                        (mutation.attributeName === 'class' || mutation.attributeName === 'disabled')) {
                        handleVariationChange();
                    }
                });
            });
            
            // Start observing the add to cart button
            if ($addToCartButton.length) {
                observer.observe($addToCartButton[0], {
                    attributes: true,
                    attributeFilter: ['class', 'disabled']
                });
            }
            
            // Position the no variation photo notice above the product gallery
            function positionNoPhotoNotice() {
                if (!$noVariationPhoto.length) return;
                
                // Try multiple selectors to find the product gallery
                var $productGallery = $('.woocommerce-product-gallery').first();
                if (!$productGallery.length) {
                    $productGallery = $('.images').first();
                }
                if (!$productGallery.length) {
                    $productGallery = $('.product-images').first();
                }
                if (!$productGallery.length) {
                    $productGallery = $('.woocommerce-product-gallery__wrapper').closest('.woocommerce-product-gallery, .images').first();
                }
                
                if ($productGallery.length) {
                    // Check if notice is already positioned correctly
                    var $galleryParent = $productGallery.parent();
                    var noticeIndex = $galleryParent.children().index($noVariationPhoto);
                    var galleryIndex = $galleryParent.children().index($productGallery);
                    
                    // If notice is not positioned before gallery, move it
                    if (noticeIndex === -1 || noticeIndex >= galleryIndex) {
                        $productGallery.before($noVariationPhoto);
                    }
                } else {
                    // Fallback: try to find the main product image wrapper
                    var $mainImage = $('.woocommerce-product-gallery__image, .wp-post-image').closest('.woocommerce-product-gallery, .images').first();
                    if ($mainImage.length) {
                        $mainImage.before($noVariationPhoto);
                    }
                }
            }
            
            // Position notice on page load
            setTimeout(function() {
                positionNoPhotoNotice();
            }, 100);
            
            // Also position when gallery is ready
            $(document).ready(function() {
                positionNoPhotoNotice();
            });
            
            // Initialize on page load
            setTimeout(function() {
                checkAddToCartButtonState();
                forceAddOnRefresh();
                positionNoPhotoNotice();
                
                // Debug: Check if notice element exists
                if (typeof console !== 'undefined' && window.location.search.indexOf('debug_variations') > -1) {
                    console.log('No variation photo notice element:', $noVariationPhoto.length ? 'Found' : 'NOT FOUND');
                    if ($noVariationPhoto.length) {
                        console.log('Notice element:', $noVariationPhoto[0]);
                        console.log('Notice is visible:', $noVariationPhoto.is(':visible'));
                    }
                }
            }, 500);
            
            // One final nudge after page load with longer delay
            setTimeout(function() {
                forceAddOnRefresh();
                positionNoPhotoNotice();
            }, 1000);
            
            // Also position after variation changes
            $form.on('found_variation', function() {
                setTimeout(function() {
                    positionNoPhotoNotice();
                }, 200);
            });
            
            // ==========================================================================
            // Image Layout Mode (Dynamic vs Static Images) - Per Product Setting
            // ==========================================================================
            
            // Get settings from product meta
            var staticImagesMode = <?php 
                global $product;
                $product_id = 0;
                if ($product && is_a($product, 'WC_Product')) {
                    $product_id = $product->get_id();
                } elseif (is_product()) {
                    $product_id = get_queried_object_id();
                }
                if ($product_id) {
                    $static_images = get_post_meta($product_id, 'oa_tfp_static_images_mode', true);
                    echo ($static_images === '1') ? 'true' : 'false';
                } else {
                    echo 'false';
                }
            ?>;
            
            var staticWithGalleryMode = <?php 
                global $product;
                $product_id = 0;
                if ($product && is_a($product, 'WC_Product')) {
                    $product_id = $product->get_id();
                } elseif (is_product()) {
                    $product_id = get_queried_object_id();
                }
                if ($product_id) {
                    $static_with_gallery = get_post_meta($product_id, 'oa_tfp_static_with_gallery_mode', true);
                    echo ($static_with_gallery === '1') ? 'true' : 'false';
                } else {
                    echo 'false';
                }
            ?>;
            
            var thumbnailGalleryMode = <?php 
                global $product;
                $product_id = 0;
                if ($product && is_a($product, 'WC_Product')) {
                    $product_id = $product->get_id();
                } elseif (is_product()) {
                    $product_id = get_queried_object_id();
                }
                if ($product_id) {
                    $thumbnail_gallery = get_post_meta($product_id, 'oa_tfp_thumbnail_gallery_mode', true);
                    echo ($thumbnail_gallery === '1') ? 'true' : 'false';
                } else {
                    echo 'false';
                }
            ?>;
            
            // ACTUAL featured product image from PHP (this is the real main product image, not a variation)
            var actualFeaturedProductImage = <?php
                global $product;
                $featured_image_data = null;
                if ($product && is_a($product, 'WC_Product')) {
                    $featured_id = $product->get_image_id();
                    if ($featured_id) {
                        $image_url = wp_get_attachment_image_url($featured_id, 'full');
                        if ($image_url) {
                            $image_meta = wp_get_attachment_metadata($featured_id);
                            $featured_image_data = array(
                                'src' => $image_url,
                                'id' => $featured_id,
                                'filename' => basename($image_url),
                                'alt' => get_post_meta($featured_id, '_wp_attachment_image_alt', true),
                                'title' => get_the_title($featured_id)
                            );
                        }
                    }
                }
                echo $featured_image_data ? json_encode($featured_image_data) : 'null';
            ?>;
            
            // Product gallery images from PHP (with full image data)
            var productGalleryImages = <?php
                global $product;
                $gallery_images = array();
                if ($product && is_a($product, 'WC_Product')) {
                    // Get featured image
                    $featured_id = $product->get_image_id();
                    if ($featured_id && wp_attachment_is_image($featured_id)) {
                        $image_url = wp_get_attachment_image_url($featured_id, 'full');
                        
                        // Fallback: if URL is empty or relative, try to get it from attachment file
                        if (empty($image_url)) {
                            $attachment_file = get_attached_file($featured_id);
                            if ($attachment_file && file_exists($attachment_file)) {
                                $image_url = wp_get_attachment_url($featured_id);
                            }
                        }
                        
                        // Ensure URL is absolute
                        if ($image_url && !preg_match('/^https?:\/\//', $image_url)) {
                            $image_url = home_url($image_url);
                        }
                        
                        if ($image_url) {
                            $image_srcset = wp_get_attachment_image_srcset($featured_id, 'full');
                            $image_sizes = wp_get_attachment_image_sizes($featured_id, 'full');
                            $image_meta = wp_get_attachment_metadata($featured_id);
                            
                            // Ensure srcset URLs are absolute
                            if ($image_srcset) {
                                $image_srcset = preg_replace_callback(
                                    '/(https?:\/\/[^\s,]+)/',
                                    function($matches) {
                                        // Already absolute, return as is
                                        return $matches[0];
                                    },
                                    $image_srcset
                                );
                            }
                            
                            $gallery_images[] = array(
                                'src' => esc_url($image_url),
                                'srcset' => $image_srcset ? esc_attr($image_srcset) : '',
                                'sizes' => $image_sizes ? esc_attr($image_sizes) : '',
                                'alt' => get_post_meta($featured_id, '_wp_attachment_image_alt', true),
                                'title' => get_the_title($featured_id),
                                'width' => isset($image_meta['width']) ? $image_meta['width'] : '',
                                'height' => isset($image_meta['height']) ? $image_meta['height'] : ''
                            );
                        }
                    }
                    // Get gallery images
                    $gallery_image_ids = $product->get_gallery_image_ids();
                    if (!empty($gallery_image_ids)) {
                        foreach ($gallery_image_ids as $image_id) {
                            // Validate attachment exists and is an image
                            if (!wp_attachment_is_image($image_id)) {
                                continue;
                            }
                            
                            // Get image URL - ensure it's absolute
                            $image_url = wp_get_attachment_image_url($image_id, 'full');
                            
                            // Fallback: if URL is empty or relative, try to get it from attachment file
                            if (empty($image_url)) {
                                $attachment_file = get_attached_file($image_id);
                                if ($attachment_file && file_exists($attachment_file)) {
                                    $image_url = wp_get_attachment_url($image_id);
                                }
                            }
                            
                            // Ensure URL is absolute
                            if ($image_url && !preg_match('/^https?:\/\//', $image_url)) {
                                $image_url = home_url($image_url);
                            }
                            
                            // Skip if URL is still empty
                            if (empty($image_url)) {
                                continue;
                            }
                            
                            // Skip if already added (featured image might be in gallery)
                            $already_added = false;
                            foreach ($gallery_images as $existing) {
                                if ($existing['src'] === $image_url) {
                                    $already_added = true;
                                    break;
                                }
                            }
                            
                            if (!$already_added) {
                                $image_srcset = wp_get_attachment_image_srcset($image_id, 'full');
                                $image_sizes = wp_get_attachment_image_sizes($image_id, 'full');
                                $image_meta = wp_get_attachment_metadata($image_id);
                                
                                // Ensure srcset URLs are absolute
                                if ($image_srcset) {
                                    $image_srcset = preg_replace_callback(
                                        '/(https?:\/\/[^\s,]+)/',
                                        function($matches) {
                                            // Already absolute, return as is
                                            return $matches[0];
                                        },
                                        $image_srcset
                                    );
                                }
                                
                                $gallery_images[] = array(
                                    'src' => esc_url($image_url),
                                    'srcset' => $image_srcset ? esc_attr($image_srcset) : '',
                                    'sizes' => $image_sizes ? esc_attr($image_sizes) : '',
                                    'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                                    'title' => get_the_title($image_id),
                                    'width' => isset($image_meta['width']) ? $image_meta['width'] : '',
                                    'height' => isset($image_meta['height']) ? $image_meta['height'] : ''
                                );
                            }
                        }
                    }
                }
                echo json_encode($gallery_images);
            ?>;
            
            // Store original WooCommerce image update function
            var originalWcVariationsImageUpdate = null;
            
            // Wait for WooCommerce to load, then initialize
            setTimeout(function() {
                // Store original function if it exists
                if (typeof $.fn.wc_variations_image_update !== 'undefined') {
                    originalWcVariationsImageUpdate = $.fn.wc_variations_image_update;
                    
                    // If static mode is enabled (including static with gallery), override the function
                    if (staticImagesMode || staticWithGalleryMode) {
                        $.fn.wc_variations_image_update = function(variation) {
                            // Do nothing - images stay static
                            return this;
                        };
                    }
                    
                    // In thumbnail gallery mode, allow normal updates but also update thumbnails
                    if (thumbnailGalleryMode && !staticImagesMode) {
                        // Store original function
                        var originalUpdate = $.fn.wc_variations_image_update;
                        $.fn.wc_variations_image_update = function(variation) {
                            // Call original function first
                            if (originalUpdate && typeof originalUpdate === 'function') {
                                originalUpdate.call(this, variation);
                            }
                            // Then update thumbnails
                            setTimeout(function() {
                                $(document).trigger('oa_tfp_update_thumbnails');
                            }, 100);
                            return this;
                        };
                    }
                }
            }, 100);
            
            // Re-apply mode when form changes (in case of AJAX reloads)
            $(document).on('woocommerce_variation_has_changed', function() {
                if ((staticImagesMode || staticWithGalleryMode) && typeof $.fn.wc_variations_image_update !== 'undefined') {
                    // Ensure override is still in place for static mode
                    $.fn.wc_variations_image_update = function(variation) {
                        return this;
                    };
                } else if (thumbnailGalleryMode && !staticImagesMode) {
                    // For thumbnail gallery mode, update thumbnails after variation change
                    setTimeout(function() {
                        $(document).trigger('oa_tfp_update_thumbnails');
                    }, 100);
                }
            });
            
            // Navigate through variation images for static images mode (including static with gallery)
            if (staticImagesMode || staticWithGalleryMode) {
                // Get all variation images
                var variationImages = [];
                var currentImageIndex = 0;
                
                // Collect all variation images from form data
                function collectVariationImages() {
                    variationImages = [];
                    var variationImagesList = [];
                    var galleryImagesList = [];
                    var seenVariationImages = new Set();
                    var seenGalleryImages = new Set();
                    
                    // Get main product image info for exclusion (if available)
                    var mainProductImageSrc = '';
                    var mainProductImageFilename = '';
                    var mainProductImageBaseName = '';
                    if (actualFeaturedProductImage && actualFeaturedProductImage.src) {
                        mainProductImageSrc = actualFeaturedProductImage.src.split('?')[0];
                        if (mainProductImageSrc) {
                            var filenameMatch = mainProductImageSrc.match(/\/([^\/]+)$/);
                            if (filenameMatch) {
                                mainProductImageFilename = filenameMatch[1].toLowerCase();
                                var baseNameMatch = mainProductImageFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                if (baseNameMatch) {
                                    mainProductImageBaseName = baseNameMatch[1].toLowerCase();
                                }
                            }
                        }
                    }
                    
                    // Collect variation images
                    var formVariations = $form.data('product_variations');
                    if (formVariations && Array.isArray(formVariations)) {
                        formVariations.forEach(function(variation) {
                            if (variation.image && variation.image.src) {
                                // Normalize the src URL to avoid duplicates from different formats
                                var normalizedSrc = variation.image.src.split('?')[0]; // Remove query params
                                
                                // Check if this is the main product image (by URL, filename, or base name)
                                var isMainImage = false;
                                if (mainProductImageSrc && normalizedSrc === mainProductImageSrc) {
                                    isMainImage = true;
                                }
                                if (!isMainImage && mainProductImageFilename) {
                                    var varFilenameMatch = normalizedSrc.match(/\/([^\/]+)$/);
                                    if (varFilenameMatch) {
                                        var varFilename = varFilenameMatch[1].toLowerCase();
                                        if (varFilename === mainProductImageFilename) {
                                            isMainImage = true;
                                        }
                                        // Also check base name (without size suffix)
                                        if (!isMainImage && mainProductImageBaseName) {
                                            var varBaseNameMatch = varFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                            if (varBaseNameMatch && varBaseNameMatch[1].toLowerCase() === mainProductImageBaseName) {
                                                isMainImage = true;
                                            }
                                        }
                                    }
                                }
                                
                                // Skip if this is the main product image
                                if (isMainImage) {
                                    return;
                                }
                                
                                // Check if this image is already in the set
                                if (!seenVariationImages.has(normalizedSrc)) {
                                    seenVariationImages.add(normalizedSrc);
                                    
                                    var imageData = {
                                        src: variation.image.src,
                                        srcset: variation.image.srcset || '',
                                        sizes: variation.image.sizes || '',
                                        title: variation.image.title || '',
                                        alt: variation.image.alt || '',
                                        width: variation.image.src_w || '',
                                        height: variation.image.src_h || '',
                                        variation_id: variation.variation_id,
                                        normalizedSrc: normalizedSrc
                                    };
                                    
                                    variationImagesList.push(imageData);
                                }
                            }
                        });
                    }
                    
                    // If static with gallery mode, collect gallery images separately
                    if (staticWithGalleryMode) {
                        // Get gallery images from DOM (all gallery images including hidden ones)
                        // Skip the first image (main product image)
                        var $galleryImages = $('.woocommerce-product-gallery__image').not(':first');
                        $galleryImages.each(function() {
                            var $img = $(this).find('.wp-post-image');
                            if ($img.length) {
                                var imgSrc = $img.attr('src') || $img.attr('data-src') || $img.attr('data-large_image') || '';
                                var normalizedSrc = imgSrc.split('?')[0];
                                
                                // Check if this is the main product image (by URL, filename, or basename)
                                var isMainImage = false;
                                if (mainProductImageSrc && normalizedSrc === mainProductImageSrc) {
                                    isMainImage = true;
                                }
                                if (!isMainImage && mainProductImageFilename) {
                                    var galFilenameMatch = normalizedSrc.match(/\/([^\/]+)$/);
                                    if (galFilenameMatch) {
                                        var galFilename = galFilenameMatch[1].toLowerCase();
                                        if (galFilename === mainProductImageFilename) {
                                            isMainImage = true;
                                        }
                                        // Also check base name
                                        if (!isMainImage && mainProductImageBaseName) {
                                            var galBaseNameMatch = galFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                            if (galBaseNameMatch && galBaseNameMatch[1].toLowerCase() === mainProductImageBaseName) {
                                                isMainImage = true;
                                            }
                                        }
                                    }
                                }
                                
                                // Skip if this is the main product image
                                if (isMainImage) {
                                    return;
                                }
                                
                                if (imgSrc && !seenGalleryImages.has(normalizedSrc)) {
                                    seenGalleryImages.add(normalizedSrc);
                                    galleryImagesList.push({
                                        src: imgSrc,
                                        srcset: $img.attr('srcset') || $img.attr('data-srcset') || '',
                                        sizes: $img.attr('sizes') || $img.attr('data-sizes') || '',
                                        title: $img.attr('title') || '',
                                        alt: $img.attr('alt') || '',
                                        width: $img.attr('width') || $img.attr('data-width') || '',
                                        height: $img.attr('height') || $img.attr('data-height') || '',
                                        normalizedSrc: normalizedSrc,
                                        variation_id: 0
                                    });
                                }
                            }
                        });
                        
                        // Also get images from PHP product gallery data
                        if (typeof productGalleryImages !== 'undefined' && Array.isArray(productGalleryImages)) {
                            productGalleryImages.forEach(function(galleryImage) {
                                if (galleryImage && galleryImage.src) {
                                    var normalizedGallerySrc = galleryImage.src.split('?')[0];
                                    
                                    // Exclude if this is the actual featured product image
                                    var isMainImage = false;
                                    if (mainProductImageSrc && normalizedGallerySrc === mainProductImageSrc) {
                                        isMainImage = true;
                                    }
                                    if (!isMainImage && mainProductImageFilename) {
                                        var galleryFilenameMatch = normalizedGallerySrc.match(/\/([^\/]+)$/);
                                        if (galleryFilenameMatch) {
                                            var galleryFilename = galleryFilenameMatch[1].toLowerCase();
                                            if (galleryFilename === mainProductImageFilename) {
                                                isMainImage = true;
                                            }
                                            // Also check base name
                                            if (!isMainImage && mainProductImageBaseName) {
                                                var galleryBaseNameMatch = galleryFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                                if (galleryBaseNameMatch && galleryBaseNameMatch[1].toLowerCase() === mainProductImageBaseName) {
                                                    isMainImage = true;
                                                }
                                            }
                                        }
                                    }
                                    
                                    if (!isMainImage && !seenGalleryImages.has(normalizedGallerySrc)) {
                                        seenGalleryImages.add(normalizedGallerySrc);
                                        
                                        var galleryImageData = {
                                            src: galleryImage.src,
                                            srcset: galleryImage.srcset || '',
                                            sizes: galleryImage.sizes || '',
                                            title: galleryImage.title || '',
                                            alt: galleryImage.alt || '',
                                            width: galleryImage.width || '',
                                            height: galleryImage.height || '',
                                            variation_id: 0,
                                            normalizedSrc: normalizedGallerySrc
                                        };
                                        
                                        galleryImagesList.push(galleryImageData);
                                    }
                                }
                            });
                        }
                        
                        // Also get images from data attributes if available (for hidden gallery images)
                        var $galleryWrapper = $('.woocommerce-product-gallery__wrapper');
                        if ($galleryWrapper.length) {
                            // Check for data attributes that might contain gallery image info
                            $galleryWrapper.find('[data-thumb], [data-src], [data-large_image]').each(function() {
                                var $el = $(this);
                                var imgSrc = $el.attr('data-large_image') || $el.attr('data-src') || $el.attr('src') || '';
                                var normalizedSrc = imgSrc.split('?')[0];
                                
                                // Check if this is the main product image (by URL, filename, or basename)
                                var isMainImage = false;
                                if (mainProductImageSrc && normalizedSrc === mainProductImageSrc) {
                                    isMainImage = true;
                                }
                                if (!isMainImage && mainProductImageFilename) {
                                    var dataFilenameMatch = normalizedSrc.match(/\/([^\/]+)$/);
                                    if (dataFilenameMatch) {
                                        var dataFilename = dataFilenameMatch[1].toLowerCase();
                                        if (dataFilename === mainProductImageFilename) {
                                            isMainImage = true;
                                        }
                                        // Also check base name
                                        if (!isMainImage && mainProductImageBaseName) {
                                            var dataBaseNameMatch = dataFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                            if (dataBaseNameMatch && dataBaseNameMatch[1].toLowerCase() === mainProductImageBaseName) {
                                                isMainImage = true;
                                            }
                                        }
                                    }
                                }
                                
                                // Skip if this is the main product image
                                if (isMainImage) {
                                    return;
                                }
                                
                                if (imgSrc && !seenGalleryImages.has(normalizedSrc)) {
                                    seenGalleryImages.add(normalizedSrc);
                                    galleryImagesList.push({
                                        src: imgSrc,
                                        srcset: $el.attr('data-srcset') || $el.attr('srcset') || '',
                                        sizes: $el.attr('data-sizes') || $el.attr('sizes') || '',
                                        title: $el.attr('title') || '',
                                        alt: $el.attr('alt') || '',
                                        width: $el.attr('data-width') || $el.attr('width') || '',
                                        height: $el.attr('data-height') || $el.attr('height') || '',
                                        normalizedSrc: normalizedSrc,
                                        variation_id: 0
                                    });
                                }
                            });
                        }
                    }
                    
                    // Interleave: variation, gallery, variation, gallery, etc.
                    // Gallery images start at second position (positions 1, 3, 5, 7...)
                    // Pattern: [variation[0], gallery[0], variation[1], gallery[1], ...]
                    if (staticWithGalleryMode && galleryImagesList.length > 0) {
                        // Calculate how many pairs we can make
                        var maxPairs = Math.max(variationImagesList.length, galleryImagesList.length);
                        for (var i = 0; i < maxPairs; i++) {
                            // Add variation image first (even positions: 0, 2, 4, 6...)
                            // First image (position 0) is always a variation image
                            if (i < variationImagesList.length) {
                                variationImages.push(variationImagesList[i]);
                            }
                            // Add gallery image second (odd positions: 1, 3, 5, 7...)
                            // Gallery images start at position 1 (second image)
                            if (i < galleryImagesList.length) {
                                variationImages.push(galleryImagesList[i]);
                            }
                        }
                    } else {
                        // If no gallery images or not in static with gallery mode, just add variation images
                        variationImages = variationImages.concat(variationImagesList);
                    }
                    
                    // Find which image is currently displayed and set index accordingly
                    var $currentImage = $('.woocommerce-product-gallery__image:first .wp-post-image');
                    if ($currentImage.length && variationImages.length > 0) {
                        var currentSrc = ($currentImage.attr('src') || $currentImage.attr('data-src') || '').split('?')[0];
                        
                        // Find matching image in our array
                        var foundIndex = -1;
                        for (var i = 0; i < variationImages.length; i++) {
                            var imgSrc = (variationImages[i].normalizedSrc || variationImages[i].src || '').split('?')[0];
                            if (imgSrc === currentSrc || variationImages[i].src === currentSrc) {
                                foundIndex = i;
                                break;
                            }
                        }
                        
                        currentImageIndex = foundIndex >= 0 ? foundIndex : 0;
                    } else {
                        currentImageIndex = 0;
                    }
                    
                    // Debug: log collected images
                    console.log('OA TFP: Collected variation images:', variationImages.length);
                    if (variationImages.length > 0) {
                        console.log('OA TFP: Current image index:', currentImageIndex);
                        variationImages.forEach(function(img, idx) {
                            console.log('OA TFP: Image ' + idx + ':', img.normalizedSrc || img.src, idx === currentImageIndex ? '(CURRENT)' : '');
                        });
                    }
                }
                
                // Update the displayed image (without triggering variation changes)
                function updateVariationImage(index) {
                    if (variationImages.length === 0 || index < 0 || index >= variationImages.length) {
                        return;
                    }
                    
                    currentImageIndex = index;
                    var imageData = variationImages[index];
                    var $mainImage = $('.woocommerce-product-gallery__image:first .wp-post-image');
                    var $mainImageWrap = $mainImage.closest('.woocommerce-product-gallery__image');
                    var $mainImageLink = $mainImageWrap.find('a');
                    
                    if ($mainImage.length) {
                        // Update image attributes directly without triggering events
                        if (imageData.src) {
                            $mainImage.attr('src', imageData.src);
                            $mainImage.attr('data-src', imageData.src);
                        }
                        if (imageData.srcset) {
                            $mainImage.attr('srcset', imageData.srcset);
                        }
                        if (imageData.sizes) {
                            $mainImage.attr('sizes', imageData.sizes);
                        }
                        if (imageData.title) {
                            $mainImage.attr('title', imageData.title);
                        }
                        if (imageData.alt) {
                            $mainImage.attr('alt', imageData.alt);
                        }
                        // Remove width/height attributes to let CSS control the size (especially for thumbnail mode square)
                        $mainImage.removeAttr('width');
                        $mainImage.removeAttr('height');
                        
                        // Update link if exists
                        if ($mainImageLink.length && imageData.src) {
                            $mainImageLink.attr('href', imageData.src);
                        }
                        
                        // Trigger zoom refresh if available (but prevent variation triggers)
                        if (typeof $mainImageWrap.trigger === 'function') {
                            // Use a custom event that won't trigger variation changes
                            $mainImageWrap.trigger('oa_tfp_image_update');
                            
                            // Only trigger zoom if it won't cause issues
                            setTimeout(function() {
                                if (typeof $mainImageWrap.trigger === 'function') {
                                    $mainImageWrap.trigger('woocommerce_gallery_init_zoom');
                                }
                            }, 50);
                        }
                    }
                    
                    // Update navigation visibility
                    updateNavigationVisibility();
                    
                    // Update thumbnail active state if in static with gallery mode
                    if (staticWithGalleryMode) {
                        $('.oa-tfp-thumbnail-item').removeClass('active');
                        $('.oa-tfp-thumbnail-item[data-index="' + index + '"]').addClass('active');
                        
                        // Navigate to page containing this thumbnail
                        var thumbnailsPerPage = 4;
                        var targetPage = Math.floor(index / thumbnailsPerPage);
                        var $wrapper = $('.oa-tfp-thumbnail-gallery-wrapper');
                        if ($wrapper.length) {
                            var currentPage = parseInt($wrapper.data('current-page') || '0');
                            if (targetPage !== currentPage) {
                                $wrapper.data('current-page', targetPage);
                                var $container = $wrapper.find('.oa-tfp-thumbnail-gallery-container');
                                var $prevBtn = $wrapper.find('.oa-tfp-thumbnail-nav.prev');
                                var $nextBtn = $wrapper.find('.oa-tfp-thumbnail-nav.next');
                                
                                if ($container.length) {
                                    var $firstThumb = $container.find('.oa-tfp-thumbnail-item').first();
                                    if ($firstThumb.length) {
                                        var thumbWidth = $firstThumb.width();
                                        var gap = 10;
                                        var totalThumbnailsWidth = variationImages.length * (thumbWidth + gap) - gap;
                                        var galleryWidth = $container.width();
                                        var maxOffset = Math.max(0, totalThumbnailsWidth - galleryWidth);
                                        var offset = targetPage * (thumbWidth + gap) * thumbnailsPerPage;
                                        offset = Math.min(offset, maxOffset);
                                        $container.css('transform', 'translateX(-' + offset + 'px)');
                                        
                                        // Update navigation buttons
                                        var totalPages = Math.ceil(variationImages.length / thumbnailsPerPage);
                                        if ($prevBtn.length) {
                                            $prevBtn.css('opacity', targetPage > 0 ? '1' : '0.3');
                                            $prevBtn.css('pointer-events', targetPage > 0 ? 'auto' : 'none');
                                        }
                                        if ($nextBtn.length) {
                                            $nextBtn.css('opacity', targetPage < totalPages - 1 ? '1' : '0.3');
                                            $nextBtn.css('pointer-events', targetPage < totalPages - 1 ? 'auto' : 'none');
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Navigate to next/previous variation image
                function navigateVariationImage(direction) {
                    if (variationImages.length === 0) {
                        collectVariationImages();
                    }
                    
                    if (variationImages.length === 0) return;
                    
                    // If only one image, don't navigate
                    if (variationImages.length <= 1) return;
                    
                    // Set flag to prevent variation selection during navigation
                    window.oaTfpNavigatingImages = true;
                    
                    var newIndex = currentImageIndex;
                    if (direction === 'next') {
                        newIndex = (currentImageIndex + 1) % variationImages.length;
                    } else if (direction === 'prev') {
                        newIndex = (currentImageIndex - 1 + variationImages.length) % variationImages.length;
                    }
                    
                    // Debug logging
                    console.log('OA TFP: Navigating:', direction, 'from index', currentImageIndex, 'to', newIndex, 'out of', variationImages.length);
                    if (variationImages[currentImageIndex]) {
                        console.log('OA TFP: Current image:', variationImages[currentImageIndex].normalizedSrc || variationImages[currentImageIndex].src);
                    }
                    if (variationImages[newIndex]) {
                        console.log('OA TFP: New image:', variationImages[newIndex].normalizedSrc || variationImages[newIndex].src);
                    }
                    
                    // Only update if the index actually changed AND it's a different image
                    if (newIndex !== currentImageIndex) {
                        var currentImgSrc = variationImages[currentImageIndex] ? (variationImages[currentImageIndex].normalizedSrc || variationImages[currentImageIndex].src) : '';
                        var newImgSrc = variationImages[newIndex] ? (variationImages[newIndex].normalizedSrc || variationImages[newIndex].src) : '';
                        
                        // Normalize for comparison
                        currentImgSrc = currentImgSrc.split('?')[0];
                        newImgSrc = newImgSrc.split('?')[0];
                        
                        if (currentImgSrc !== newImgSrc) {
                            updateVariationImage(newIndex);
                        } else {
                            console.log('OA TFP: Skipping - same image at different index');
                            // If same image, skip to next one
                            if (direction === 'next') {
                                var nextIndex = (newIndex + 1) % variationImages.length;
                                if (nextIndex !== currentImageIndex) {
                                    updateVariationImage(nextIndex);
                                }
                            } else if (direction === 'prev') {
                                var prevIndex = (newIndex - 1 + variationImages.length) % variationImages.length;
                                if (prevIndex !== currentImageIndex) {
                                    updateVariationImage(prevIndex);
                                }
                            }
                        }
                    } else {
                        console.log('OA TFP: Same index, no change needed');
                    }
                    
                    // Clear flag after a short delay
                    setTimeout(function() {
                        window.oaTfpNavigatingImages = false;
                    }, 200);
                }
                
                // Update navigation arrow visibility
                function updateNavigationVisibility() {
                    var $prev = $('.oa-tfp-variation-nav .oa-tfp-nav-prev');
                    var $next = $('.oa-tfp-variation-nav .oa-tfp-nav-next');
                    
                    if (variationImages.length <= 1) {
                        $prev.hide();
                        $next.hide();
                    } else {
                        $prev.show();
                        $next.show();
                    }
                    
                    // For static with gallery mode, always hide flexslider arrows (thumbnails handle navigation)
                    if (staticWithGalleryMode) {
                        $('.woocommerce-product-gallery .flex-direction-nav').hide();
                        $('.woocommerce-product-gallery .flex-direction-nav a').hide();
                    }
                }
                
                // Create navigation arrows (skip if static with gallery mode - thumbnails handle navigation)
                function createVariationNavigation() {
                    // Don't create top arrows if in static with gallery mode
                    if (staticWithGalleryMode) {
                        // Still collect images for thumbnail gallery
                        collectVariationImages();
                        return;
                    }
                    
                    var $gallery = $('.woocommerce-product-gallery');
                    if (!$gallery.length) return;
                    
                    // Remove existing navigation if any
                    $('.oa-tfp-variation-nav').remove();
                    
                    // Create navigation with SVG arrows (matching site's owl carousel style)
                    var $nav = $('<ul class="oa-tfp-variation-nav flex-direction-nav"><li class="flex-nav-prev"><a class="oa-tfp-nav-prev flex-prev" href="#" role="presentation"><svg viewBox="0 0 24 40" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 1 L2 20 L22 39"/></svg></a></li><li class="flex-nav-next"><a class="oa-tfp-nav-next flex-next" href="#" role="presentation"><svg viewBox="0 0 24 40" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 1 L22 20 L2 39"/></svg></a></li></ul>');
                    $gallery.append($nav);
                    
                    // Bind click events
                    $nav.find('.oa-tfp-nav-prev').on('click', function(e) {
                        e.preventDefault();
                        navigateVariationImage('prev');
                        return false;
                    });
                    
                    $nav.find('.oa-tfp-nav-next').on('click', function(e) {
                        e.preventDefault();
                        navigateVariationImage('next');
                        return false;
                    });
                    
                    // Collect variation images
                    collectVariationImages();
                    updateNavigationVisibility();
                }
                
                // Initialize navigation
                setTimeout(function() {
                    createVariationNavigation();
                }, 500);
                
                // If static with gallery mode, also create thumbnails
                if (staticWithGalleryMode) {
                    var currentThumbnailIndex = 0;
                    var staticThumbnailPage = 0; // Use different name to avoid conflicts
                    var thumbnailsPerPage = 4;
                    
                    // Create thumbnail gallery
                    function createStaticThumbnailGallery() {
                        var $gallery = $('.woocommerce-product-gallery');
                        if (!$gallery.length) return;
                        
                        // Add class to gallery to indicate thumbnail mode
                        $gallery.addClass('oa-tfp-thumbnail-mode');
                        
                        // Hide custom variation nav (not needed in static with gallery mode)
                        $('.woocommerce-product-gallery .oa-tfp-variation-nav').hide();
                        
                        // Completely hide and disable flexslider arrows for static with gallery mode
                        // Thumbnails handle navigation, so top arrows are not needed
                        function hideFlexsliderArrows() {
                            var $flexArrows = $('.woocommerce-product-gallery .flex-direction-nav');
                            $flexArrows.hide();
                            $flexArrows.css('display', 'none !important');
                            
                            // Also hide individual arrow links
                            $('.woocommerce-product-gallery .flex-direction-nav a').hide();
                            $('.woocommerce-product-gallery .flex-direction-nav a').css('display', 'none !important');
                        }
                        
                        // Hide arrows immediately and keep hiding them
                        hideFlexsliderArrows();
                        
                        // Retry hiding in case flexslider recreates them
                        var hideAttempts = 0;
                        var maxHideAttempts = 15;
                        function tryHideArrows() {
                            hideFlexsliderArrows();
                            
                            hideAttempts++;
                            if (hideAttempts < maxHideAttempts) {
                                setTimeout(tryHideArrows, 200);
                            }
                        }
                        
                        // Start hiding attempts
                        setTimeout(tryHideArrows, 100);
                        
                        // Also hide when flexslider initializes (it might recreate arrows)
                        $(document).on('woocommerce_gallery_init_zoom.oa-tfp-hide-arrows', function() {
                            setTimeout(hideFlexsliderArrows, 100);
                        });
                        
                        // Also hide when gallery is ready (flexslider might recreate arrows)
                        $gallery.on('flexslider:ready.oa-tfp-hide-arrows', function() {
                            setTimeout(hideFlexsliderArrows, 100);
                        });
                        
                        // Use MutationObserver to catch any dynamically added arrows
                        if (typeof MutationObserver !== 'undefined') {
                            var observer = new MutationObserver(function(mutations) {
                                hideFlexsliderArrows();
                            });
                            
                            var $galleryWrapper = $('.woocommerce-product-gallery');
                            if ($galleryWrapper.length) {
                                observer.observe($galleryWrapper[0], {
                                    childList: true,
                                    subtree: true
                                });
                            }
                        }
                        
                        // Remove existing thumbnail gallery
                        $('.oa-tfp-thumbnail-gallery-wrapper').remove();
                        
                        // Collect images (already done in collectVariationImages, which includes gallery)
                        collectVariationImages();
                        
                        if (variationImages.length <= 1) {
                            // Don't show thumbnails if only one image
                            return;
                        }
                        
                        // Calculate total pages
                        var totalPages = Math.ceil(variationImages.length / thumbnailsPerPage);
                        staticThumbnailPage = 0;
                        
                        // Find current image index
                        var $currentImage = $('.woocommerce-product-gallery__image:first .wp-post-image');
                        if ($currentImage.length && variationImages.length > 0) {
                            var currentSrc = ($currentImage.attr('src') || $currentImage.attr('data-src') || '').split('?')[0];
                            var foundIndex = -1;
                            for (var i = 0; i < variationImages.length; i++) {
                                var imgSrc = (variationImages[i].normalizedSrc || variationImages[i].src || '').split('?')[0];
                                if (imgSrc === currentSrc) {
                                    foundIndex = i;
                                    break;
                                }
                            }
                            currentThumbnailIndex = foundIndex >= 0 ? foundIndex : 0;
                            staticThumbnailPage = Math.floor(currentThumbnailIndex / thumbnailsPerPage);
                        } else {
                            currentThumbnailIndex = 0;
                            staticThumbnailPage = 0;
                        }
                        
                        // Get main image width
                        var $mainImage = $('.woocommerce-product-gallery__image:first');
                        var mainImageWidth = $mainImage.length ? $mainImage.width() : 0;
                        if (mainImageWidth === 0) {
                            var $galleryWrapper = $('.woocommerce-product-gallery__wrapper');
                            mainImageWidth = $galleryWrapper.length ? $galleryWrapper.width() : 0;
                        }
                        if (mainImageWidth === 0) {
                            mainImageWidth = 600;
                        }
                        
                        // Create wrapper
                        var $wrapper = $('<div class="oa-tfp-thumbnail-gallery-wrapper"></div>');
                        
                        // Create thumbnail container
                        var $thumbnailContainer = $('<div class="oa-tfp-thumbnail-gallery"><div class="oa-tfp-thumbnail-gallery-container"></div></div>');
                        var $container = $thumbnailContainer.find('.oa-tfp-thumbnail-gallery-container');
                        
                        $thumbnailContainer.css({
                            'width': '100%',
                            'max-width': '100%'
                        });
                        
                        // Calculate thumbnail size
                        var wrapperPadding = 80;
                        var galleryContentWidth = mainImageWidth - wrapperPadding;
                        var isMobile = window.innerWidth <= 768;
                        var gap = isMobile ? 8 : 10;
                        var buffer = 2;
                        var thumbWidth = (galleryContentWidth - (gap * 3) - buffer) / 4;
                        
                        // Set container width to fit all thumbnails
                        var totalThumbnailsWidth = (thumbWidth * variationImages.length) + (gap * (variationImages.length - 1));
                        $container.css('width', totalThumbnailsWidth + 'px');
                        
                        // Create thumbnails
                        variationImages.forEach(function(img, idx) {
                            var $thumbItem = $('<div class="oa-tfp-thumbnail-item" data-index="' + idx + '"></div>');
                            var thumbSrc = img.src || '';
                            if (thumbSrc) {
                                // Use thumbnail size version if available
                                var thumbUrl = thumbSrc.replace(/(-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)/i, '-150x150.$2');
                                $thumbItem.html('<img src="' + thumbUrl + '" alt="" />');
                                
                                // Set explicit width and height for thumbnail
                                $thumbItem.css({
                                    'width': thumbWidth + 'px',
                                    'height': thumbWidth + 'px',
                                    'min-width': thumbWidth + 'px'
                                });
                                
                                // Click handler
                                $thumbItem.on('click', function() {
                                    var clickedIndex = parseInt($(this).attr('data-index'));
                                    if (clickedIndex >= 0 && clickedIndex < variationImages.length) {
                                        currentImageIndex = clickedIndex;
                                        currentThumbnailIndex = clickedIndex;
                                        updateVariationImage(clickedIndex);
                                        
                                        // Update active state
                                        $('.oa-tfp-thumbnail-item').removeClass('active');
                                        $(this).addClass('active');
                                        
                                        // Navigate to page containing this thumbnail
                                        var targetPage = Math.floor(clickedIndex / thumbnailsPerPage);
                                        if (targetPage !== staticThumbnailPage) {
                                            staticThumbnailPage = targetPage;
                                            $wrapper.data('current-page', staticThumbnailPage);
                                            updateThumbnailPosition();
                                            updateThumbnailNavigation();
                                        }
                                    }
                                });
                                
                                // Mark as active if this is the current image
                                if (idx === currentImageIndex) {
                                    $thumbItem.addClass('active');
                                }
                                
                                $container.append($thumbItem);
                            }
                        });
                        
                        // Create navigation arrows (matching regular thumbnail gallery classes)
                        var $prevBtn = $('<button class="oa-tfp-thumbnail-nav prev" type="button" aria-label="Previous thumbnails"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg></button>');
                        var $nextBtn = $('<button class="oa-tfp-thumbnail-nav next" type="button" aria-label="Next thumbnails"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></button>');
                        
                        // Append to wrapper in same order as regular thumbnail gallery
                        $wrapper.append($thumbnailContainer);
                        $wrapper.append($prevBtn);
                        $wrapper.append($nextBtn);
                        
                        // Insert inside gallery (same as regular thumbnail gallery mode)
                        $gallery.append($wrapper);
                        
                        // Update thumbnail position
                        function updateThumbnailPosition() {
                            if (!$container.length || !variationImages || variationImages.length === 0) return;
                            
                            // Get gallery width (actual rendered width within wrapper) - same logic as regular thumbnail gallery
                            var $gallery = $thumbnailContainer;
                            var galleryWidth = $gallery.width();
                            if (galleryWidth === 0) {
                                // Fallback: try to get from wrapper
                                if ($wrapper.length) {
                                    // Get wrapper width minus padding
                                    var wrapperWidth = $wrapper.width();
                                    var paddingLeft = parseInt($wrapper.css('padding-left')) || 40;
                                    var paddingRight = parseInt($wrapper.css('padding-right')) || 40;
                                    galleryWidth = wrapperWidth - paddingLeft - paddingRight;
                                }
                                if (galleryWidth === 0) {
                                    galleryWidth = 600; // Final fallback
                                }
                            }
                            
                            // Recalculate thumbWidth in case it changed
                            var isMobile = window.innerWidth <= 768;
                            var gap = isMobile ? 8 : 10;
                            var buffer = 2;
                            var currentThumbWidth = (galleryWidth - (gap * 3) - buffer) / 4;
                            
                            // Calculate offset
                            var totalPages = Math.ceil(variationImages.length / thumbnailsPerPage);
                            var maxPage = Math.max(0, totalPages - 1);
                            staticThumbnailPage = Math.min(staticThumbnailPage, maxPage);
                            
                            var offset = staticThumbnailPage * (currentThumbWidth + gap) * thumbnailsPerPage;
                            
                            // Calculate maximum possible offset (container width - gallery width)
                            var containerWidth = $container.width();
                            var maxOffset = Math.max(0, containerWidth - galleryWidth);
                            
                            // Ensure offset doesn't exceed maximum
                            offset = Math.min(offset, maxOffset);
                            
                            $container.css('transform', 'translateX(-' + offset + 'px)');
                        }
                        
                        // Update navigation buttons
                        function updateThumbnailNavigation() {
                            if (!variationImages || variationImages.length === 0) return;
                            
                            var totalPages = Math.ceil(variationImages.length / thumbnailsPerPage);
                            if ($prevBtn.length) {
                                $prevBtn.css('opacity', staticThumbnailPage > 0 ? '1' : '0.3');
                                $prevBtn.css('pointer-events', staticThumbnailPage > 0 ? 'auto' : 'none');
                            }
                            if ($nextBtn.length) {
                                $nextBtn.css('opacity', staticThumbnailPage < totalPages - 1 ? '1' : '0.3');
                                $nextBtn.css('pointer-events', staticThumbnailPage < totalPages - 1 ? 'auto' : 'none');
                            }
                        }
                        
                        // Store initial page in wrapper data
                        $wrapper.data('current-page', staticThumbnailPage);
                        
                        // Navigation handlers - attach after DOM insertion
                        setTimeout(function() {
                            $prevBtn.off('click').on('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                if (staticThumbnailPage > 0) {
                                    staticThumbnailPage--;
                                    $wrapper.data('current-page', staticThumbnailPage);
                                    updateThumbnailPosition();
                                    updateThumbnailNavigation();
                                }
                                return false;
                            });
                            
                            $nextBtn.off('click').on('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                if (!variationImages || variationImages.length === 0) return false;
                                
                                var totalPages = Math.ceil(variationImages.length / thumbnailsPerPage);
                                if (staticThumbnailPage < totalPages - 1) {
                                    staticThumbnailPage++;
                                    $wrapper.data('current-page', staticThumbnailPage);
                                    updateThumbnailPosition();
                                    updateThumbnailNavigation();
                                }
                                return false;
                            });
                        }, 50);
                        
                        // Initialize after a short delay to ensure DOM is ready
                        setTimeout(function() {
                            updateThumbnailPosition();
                            updateThumbnailNavigation();
                        }, 100);
                        
                        // Handle resize
                        var resizeTimer;
                        $(window).on('resize', function() {
                            clearTimeout(resizeTimer);
                            resizeTimer = setTimeout(function() {
                                mainImageWidth = $mainImage.length ? $mainImage.width() : 0;
                                if (mainImageWidth === 0) {
                                    var $galleryWrapper = $('.woocommerce-product-gallery__wrapper');
                                    mainImageWidth = $galleryWrapper.length ? $galleryWrapper.width() : 0;
                                }
                                if (mainImageWidth === 0) {
                                    mainImageWidth = 600;
                                }
                                galleryContentWidth = mainImageWidth - wrapperPadding;
                                thumbWidth = (galleryContentWidth - (gap * 3) - buffer) / 4;
                                $container.find('.oa-tfp-thumbnail-item').css('width', thumbWidth + 'px');
                                updateThumbnailPosition();
                            }, 250);
                        });
                    }
                    
                    // Initialize thumbnail gallery
                    setTimeout(function() {
                        createStaticThumbnailGallery();
                    }, 800);
                }
                
                // Re-collect images when variations change (but don't auto-update image in static mode)
                // Note: In static mode, we prevent the image from changing, so we don't update here
                // The user can manually navigate through images using the arrows
            }
            
            // ==========================================================================
            // Thumbnail Gallery Mode - Show thumbnails below main image
            // ==========================================================================
            
            if (thumbnailGalleryMode) {
                var thumbnailImages = [];
                var currentThumbnailIndex = 0;
                var currentPage = 0; // Current page (4 thumbnails per page)
                var thumbnailsPerPage = 4;
                // Store main product image info for exclusion checks
                var mainProductImageSrc = '';
                var mainProductImageFilename = '';
                var mainProductImageBaseName = '';
                var mainProductImageId = '';
                
                // Collect all images (variations + gallery) - interleaved: every second image is a gallery image
                function collectThumbnailImages() {
                    thumbnailImages = [];
                    var variationImagesList = [];
                    var galleryImagesList = [];
                    var seenVariationImages = new Set();
                    var seenGalleryImages = new Set();
                    
                    // Get the ACTUAL featured product image from PHP (not from DOM, which may show a variation)
                    // Reset main product image variables (they're module-level)
                    mainProductImageSrc = '';
                    mainProductImageFilename = '';
                    mainProductImageBaseName = ''; // Base name without size suffix (e.g., "BSOAK" from "BSOAK-600x600.png")
                    var mainProductImageId = ''; // WordPress attachment ID if available
                    
                    // Use the actual featured product image from PHP, not what's displayed in DOM
                    if (actualFeaturedProductImage && actualFeaturedProductImage.src) {
                        mainProductImageSrc = actualFeaturedProductImage.src.split('?')[0];
                        mainProductImageId = actualFeaturedProductImage.id || '';
                        
                        // Get filename for more robust comparison
                        if (mainProductImageSrc) {
                            var filenameMatch = mainProductImageSrc.match(/\/([^\/]+)$/);
                            if (filenameMatch) {
                                mainProductImageFilename = filenameMatch[1].toLowerCase();
                                // Extract base name (remove size suffix like -600x600, -300x300, etc.)
                                var baseNameMatch = mainProductImageFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                if (baseNameMatch) {
                                    mainProductImageBaseName = baseNameMatch[1].toLowerCase();
                                }
                            }
                            seenVariationImages.add(mainProductImageSrc);
                            seenGalleryImages.add(mainProductImageSrc);
                            // Also add filename-based check
                            if (mainProductImageFilename) {
                                seenVariationImages.add('filename:' + mainProductImageFilename);
                                seenGalleryImages.add('filename:' + mainProductImageFilename);
                            }
                            if (mainProductImageBaseName) {
                                seenVariationImages.add('basename:' + mainProductImageBaseName);
                                seenGalleryImages.add('basename:' + mainProductImageBaseName);
                            }
                        }
                        
                    }
                    
                    // Get variation images first (excluding main product image)
                    var formVariations = $form.data('product_variations');
                    if (formVariations && Array.isArray(formVariations)) {
                        formVariations.forEach(function(variation) {
                            if (variation.image && variation.image.src) {
                                var normalizedSrc = variation.image.src.split('?')[0];
                                
                                // Check if this is the main product image (by URL, filename, or base name)
                                var isMainImage = false;
                                if (mainProductImageSrc && normalizedSrc === mainProductImageSrc) {
                                    isMainImage = true;
                                }
                                if (!isMainImage && mainProductImageFilename) {
                                    var varFilenameMatch = normalizedSrc.match(/\/([^\/]+)$/);
                                    if (varFilenameMatch) {
                                        var varFilename = varFilenameMatch[1].toLowerCase();
                                        if (varFilename === mainProductImageFilename) {
                                            isMainImage = true;
                                        }
                                        // Also check base name (without size suffix)
                                        if (!isMainImage && mainProductImageBaseName) {
                                            var varBaseNameMatch = varFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                            if (varBaseNameMatch && varBaseNameMatch[1].toLowerCase() === mainProductImageBaseName) {
                                                isMainImage = true;
                                            }
                                        }
                                    }
                                }
                                
                                // Skip if this is the main product image
                                if (isMainImage) {
                                    return;
                                }
                                
                                if (!seenVariationImages.has(normalizedSrc)) {
                                    seenVariationImages.add(normalizedSrc);
                                    variationImagesList.push({
                                        src: variation.image.src,
                                        srcset: variation.image.srcset || '',
                                        sizes: variation.image.sizes || '',
                                        title: variation.image.title || '',
                                        alt: variation.image.alt || '',
                                        width: variation.image.src_w || '',
                                        height: variation.image.src_h || '',
                                        normalizedSrc: normalizedSrc,
                                        variation_id: variation.variation_id
                                    });
                                }
                            }
                        });
                    }
                    
                    // Get gallery images from WooCommerce product gallery
                    // First, try to get from the DOM (all gallery images including hidden ones)
                    // Skip the first image (main product image)
                    var $galleryImages = $('.woocommerce-product-gallery__image').not(':first');
                    $galleryImages.each(function() {
                        var $img = $(this).find('.wp-post-image');
                        if ($img.length) {
                            var imgSrc = $img.attr('src') || $img.attr('data-src') || $img.attr('data-large_image') || '';
                            var normalizedSrc = imgSrc.split('?')[0];
                            
                            // Check if this is the main product image (by URL, filename, or basename)
                            var isMainImage = false;
                            if (mainProductImageSrc && normalizedSrc === mainProductImageSrc) {
                                isMainImage = true;
                            }
                            if (!isMainImage && mainProductImageFilename) {
                                var galFilenameMatch = normalizedSrc.match(/\/([^\/]+)$/);
                                if (galFilenameMatch) {
                                    var galFilename = galFilenameMatch[1].toLowerCase();
                                    if (galFilename === mainProductImageFilename) {
                                        isMainImage = true;
                                    }
                                    // Also check base name
                                    if (!isMainImage && mainProductImageBaseName) {
                                        var galBaseNameMatch = galFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                        if (galBaseNameMatch && galBaseNameMatch[1].toLowerCase() === mainProductImageBaseName) {
                                            isMainImage = true;
                                        }
                                    }
                                }
                            }
                            
                            // Skip if this is the main product image
                            if (isMainImage) {
                                return;
                            }
                            
                            if (imgSrc && !seenGalleryImages.has(normalizedSrc)) {
                                seenGalleryImages.add(normalizedSrc);
                                galleryImagesList.push({
                                    src: imgSrc,
                                    srcset: $img.attr('srcset') || $img.attr('data-srcset') || '',
                                    sizes: $img.attr('sizes') || $img.attr('data-sizes') || '',
                                    title: $img.attr('title') || '',
                                    alt: $img.attr('alt') || '',
                                    width: $img.attr('width') || $img.attr('data-width') || '',
                                    height: $img.attr('height') || $img.attr('data-height') || '',
                                    normalizedSrc: normalizedSrc,
                                    variation_id: 0
                                });
                            }
                        }
                    });
                    
                    // Also get images from PHP product gallery data
                    if (typeof productGalleryImages !== 'undefined' && Array.isArray(productGalleryImages)) {
                        productGalleryImages.forEach(function(imageData) {
                            if (imageData && imageData.src) {
                                var normalizedSrc = imageData.src.split('?')[0];
                                
                                // Check if this is the main product image (by URL, filename, or basename)
                                var isMainImage = false;
                                if (mainProductImageSrc && normalizedSrc === mainProductImageSrc) {
                                    isMainImage = true;
                                }
                                if (!isMainImage && mainProductImageFilename) {
                                    var phpFilenameMatch = normalizedSrc.match(/\/([^\/]+)$/);
                                    if (phpFilenameMatch) {
                                        var phpFilename = phpFilenameMatch[1].toLowerCase();
                                        if (phpFilename === mainProductImageFilename) {
                                            isMainImage = true;
                                        }
                                        // Also check base name
                                        if (!isMainImage && mainProductImageBaseName) {
                                            var phpBaseNameMatch = phpFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                            if (phpBaseNameMatch && phpBaseNameMatch[1].toLowerCase() === mainProductImageBaseName) {
                                                isMainImage = true;
                                            }
                                        }
                                    }
                                }
                                
                                // Skip if this is the main product image
                                if (isMainImage) {
                                    return;
                                }
                                
                                if (!seenGalleryImages.has(normalizedSrc)) {
                                    seenGalleryImages.add(normalizedSrc);
                                    galleryImagesList.push({
                                        src: imageData.src,
                                        srcset: imageData.srcset || '',
                                        sizes: imageData.sizes || '',
                                        title: imageData.title || '',
                                        alt: imageData.alt || '',
                                        width: imageData.width || '',
                                        height: imageData.height || '',
                                        normalizedSrc: normalizedSrc,
                                        variation_id: 0
                                    });
                                }
                            }
                        });
                    }
                    
                    // Also get images from data attributes if available (for hidden gallery images)
                    var $galleryWrapper = $('.woocommerce-product-gallery__wrapper');
                    if ($galleryWrapper.length) {
                        // Check for data attributes that might contain gallery image info
                        $galleryWrapper.find('[data-thumb], [data-src], [data-large_image]').each(function() {
                            var $el = $(this);
                            var imgSrc = $el.attr('data-large_image') || $el.attr('data-src') || $el.attr('src') || '';
                            var normalizedSrc = imgSrc.split('?')[0];
                            
                            // Check if this is the main product image (by URL, filename, or basename)
                            var isMainImage = false;
                            if (mainProductImageSrc && normalizedSrc === mainProductImageSrc) {
                                isMainImage = true;
                            }
                            if (!isMainImage && mainProductImageFilename) {
                                var dataFilenameMatch = normalizedSrc.match(/\/([^\/]+)$/);
                                if (dataFilenameMatch) {
                                    var dataFilename = dataFilenameMatch[1].toLowerCase();
                                    if (dataFilename === mainProductImageFilename) {
                                        isMainImage = true;
                                    }
                                    // Also check base name
                                    if (!isMainImage && mainProductImageBaseName) {
                                        var dataBaseNameMatch = dataFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                        if (dataBaseNameMatch && dataBaseNameMatch[1].toLowerCase() === mainProductImageBaseName) {
                                            isMainImage = true;
                                        }
                                    }
                                }
                            }
                            
                            // Skip if this is the main product image
                            if (isMainImage) {
                                return;
                            }
                            
                            if (imgSrc && !seenGalleryImages.has(normalizedSrc)) {
                                seenGalleryImages.add(normalizedSrc);
                                galleryImagesList.push({
                                    src: imgSrc,
                                    srcset: $el.attr('data-srcset') || $el.attr('srcset') || '',
                                    sizes: $el.attr('data-sizes') || $el.attr('sizes') || '',
                                    title: $el.attr('title') || '',
                                    alt: $el.attr('alt') || '',
                                    width: $el.attr('data-width') || $el.attr('width') || '',
                                    height: $el.attr('data-height') || $el.attr('height') || '',
                                    normalizedSrc: normalizedSrc,
                                    variation_id: 0
                                });
                            }
                        });
                    }
                    
                    // Interleave: variation, gallery, variation, gallery, etc.
                    // Gallery images start at second position (positions 1, 3, 5, 7...)
                    // Pattern: [variation[0], gallery[0], variation[1], gallery[1], ...]
                    
                    
                    // Always interleave if we have gallery images, even if we have more variations
                    // This ensures gallery images start at position 1 (second image)
                    if (galleryImagesList.length > 0) {
                        // Calculate how many pairs we can make
                        var maxPairs = Math.max(variationImagesList.length, galleryImagesList.length);
                        for (var i = 0; i < maxPairs; i++) {
                            // Add variation image first (even positions: 0, 2, 4, 6...)
                            // First image (position 0) is always a variation image
                            if (i < variationImagesList.length) {
                                thumbnailImages.push(variationImagesList[i]);
                            }
                            // Add gallery image second (odd positions: 1, 3, 5, 7...)
                            // Gallery images start at position 1 (second image)
                            if (i < galleryImagesList.length) {
                                thumbnailImages.push(galleryImagesList[i]);
                            }
                        }
                    } else {
                        // If no gallery images, just add variation images
                        thumbnailImages = thumbnailImages.concat(variationImagesList);
                    }
                    
                    // Final check: Remove main product image if it somehow got added
                    // Check by normalized URL, filename, and base name (without size suffix)
                    if (mainProductImageSrc || mainProductImageFilename || mainProductImageBaseName) {
                        var beforeFilter = thumbnailImages.length;
                        thumbnailImages = thumbnailImages.filter(function(img) {
                            var imgSrc = (img.normalizedSrc || img.src || '').split('?')[0];
                            var imgFilename = '';
                            var imgBaseName = '';
                            var filenameMatch = imgSrc.match(/\/([^\/]+)$/);
                            if (filenameMatch) {
                                imgFilename = filenameMatch[1].toLowerCase();
                                var baseNameMatch = imgFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                if (baseNameMatch) {
                                    imgBaseName = baseNameMatch[1].toLowerCase();
                                }
                            }
                            
                            // Exclude if URL matches, filename matches, or base name matches
                            var isMainImage = false;
                            if (mainProductImageSrc && imgSrc === mainProductImageSrc) {
                                isMainImage = true;
                            }
                            if (!isMainImage && mainProductImageFilename && imgFilename && imgFilename === mainProductImageFilename) {
                                isMainImage = true;
                            }
                            if (!isMainImage && mainProductImageBaseName && imgBaseName && imgBaseName === mainProductImageBaseName) {
                                isMainImage = true;
                            }
                            
                            if (isMainImage) {
                                console.log('OA TFP: Filtering out main product image from final array:', imgFilename || imgSrc);
                            }
                            
                            return !isMainImage;
                        });
                        if (thumbnailImages.length < beforeFilter) {
                            console.log('OA TFP: Removed', beforeFilter - thumbnailImages.length, 'main product image(s) from final array');
                        }
                    }
                    
                    
                    // Verify main product image is excluded (check both URL, filename, and basename)
                    var mainImageInThumbnails = false;
                    var mainImagePositions = [];
                    thumbnailImages.forEach(function(img, idx) {
                        var imgSrc = (img.normalizedSrc || img.src || '').split('?')[0];
                        var imgFilename = '';
                        var imgBaseName = '';
                        var filenameMatch = imgSrc.match(/\/([^\/]+)$/);
                        if (filenameMatch) {
                            imgFilename = filenameMatch[1].toLowerCase();
                            var baseNameMatch = imgFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                            if (baseNameMatch) {
                                imgBaseName = baseNameMatch[1].toLowerCase();
                            }
                        }
                        
                        var isMain = false;
                        if (mainProductImageSrc && imgSrc === mainProductImageSrc) {
                            isMain = true;
                        }
                        if (!isMain && mainProductImageFilename && imgFilename && imgFilename === mainProductImageFilename) {
                            isMain = true;
                        }
                        if (!isMain && mainProductImageBaseName && imgBaseName && imgBaseName === mainProductImageBaseName) {
                            isMain = true;
                        }
                        
                        if (isMain) {
                            mainImageInThumbnails = true;
                            mainImagePositions.push(idx);
                            console.error('OA TFP: ERROR - Main product image found in thumbnailImages array at position', idx, '- filename:', imgFilename);
                        }
                    });
                    
                    if (mainImageInThumbnails) {
                        console.error('OA TFP: ERROR - Main product image found in thumbnails at positions:', mainImagePositions);
                    }
                    
                    // Find current image index
                    var $currentImage = $('.woocommerce-product-gallery__image:first .wp-post-image');
                    if ($currentImage.length && thumbnailImages.length > 0) {
                        var currentSrc = ($currentImage.attr('src') || $currentImage.attr('data-src') || '').split('?')[0];
                        var foundIndex = -1;
                        for (var i = 0; i < thumbnailImages.length; i++) {
                            var imgSrc = (thumbnailImages[i].normalizedSrc || thumbnailImages[i].src || '').split('?')[0];
                            if (imgSrc === currentSrc) {
                                foundIndex = i;
                                break;
                            }
                        }
                        currentThumbnailIndex = foundIndex >= 0 ? foundIndex : 0;
                        console.log('OA TFP: Current thumbnail index set to:', currentThumbnailIndex);
                    } else {
                        currentThumbnailIndex = 0;
                    }
                    
                    console.log('OA TFP: Collected thumbnail images:', thumbnailImages.length);
                }
                
                // Create thumbnail gallery
                function createThumbnailGallery() {
                    var $gallery = $('.woocommerce-product-gallery');
                    if (!$gallery.length) return;
                    
                    // Add class to gallery to indicate thumbnail mode
                    $gallery.addClass('oa-tfp-thumbnail-mode');
                    
                    // Remove existing thumbnail gallery
                    $('.oa-tfp-thumbnail-gallery-wrapper').remove();
                    
                    // Collect images
                    collectThumbnailImages();
                    
                    if (thumbnailImages.length <= 1) {
                        // Don't show thumbnails if only one image
                        return;
                    }
                    
                    // Calculate total pages
                    var totalPages = Math.ceil(thumbnailImages.length / thumbnailsPerPage);
                    // Always start at page 0 to show first 4 images (positions 0-3, which includes gallery at position 1)
                    currentPage = 0;
                    // But update currentThumbnailIndex to match the current image if found
                    if (currentThumbnailIndex > 0) {
                        // If current image is not at position 0, we'll navigate to its page after initial display
                        var targetPage = Math.floor(currentThumbnailIndex / thumbnailsPerPage);
                        // Only navigate if it's not the first page
                        if (targetPage > 0) {
                            // Set page but we'll show first page initially, then navigate
                            setTimeout(function() {
                                currentPage = targetPage;
                                updateThumbnailPosition();
                                updateThumbnailNavigation();
                            }, 300);
                        }
                    }
                    
                    // Get main image width to match thumbnail gallery
                    var $mainImage = $('.woocommerce-product-gallery__image:first');
                    var mainImageWidth = $mainImage.length ? $mainImage.width() : 0;
                    
                    // If main image width not available, try getting from gallery wrapper
                    if (mainImageWidth === 0) {
                        var $galleryWrapper = $('.woocommerce-product-gallery__wrapper');
                        mainImageWidth = $galleryWrapper.length ? $galleryWrapper.width() : 0;
                    }
                    
                    // Fallback to a reasonable default if still no width
                    if (mainImageWidth === 0) {
                        mainImageWidth = 600; // Default fallback
                    }
                    
                    // Create wrapper
                    var $wrapper = $('<div class="oa-tfp-thumbnail-gallery-wrapper"></div>');
                    
                    // Create thumbnail container
                    var $thumbnailContainer = $('<div class="oa-tfp-thumbnail-gallery"><div class="oa-tfp-thumbnail-gallery-container"></div></div>');
                    var $container = $thumbnailContainer.find('.oa-tfp-thumbnail-gallery-container');
                    
                    // Set thumbnail gallery width to 100% to fit within wrapper (which has padding)
                    // The wrapper padding will handle the spacing, so gallery should be 100% of wrapper's content area
                    $thumbnailContainer.css({
                        'width': '100%',
                        'max-width': '100%'
                    });
                    
                    // Calculate thumbnail size based on wrapper's content width (not full main image width)
                    // The wrapper has padding (40px left + 40px right = 80px total)
                    var wrapperPadding = 80;
                    var galleryContentWidth = mainImageWidth - wrapperPadding;
                    
                    // Account for 3 gaps between 4 thumbnails, plus a small buffer to prevent overlap
                    var isMobile = window.innerWidth <= 768;
                    var gap = isMobile ? 8 : 10;
                    var buffer = 2; // Small buffer to prevent overlap
                    var thumbWidth = (galleryContentWidth - (gap * 3) - buffer) / 4;
                    
                    // Use the ACTUAL featured product image from PHP (not from DOM, which may show a variation)
                    // The featured product image never changes - it's always the same regardless of variation selection
                    var currentMainImageSrc = '';
                    var currentMainImageFilename = '';
                    var currentMainImageBaseName = '';
                    
                    if (actualFeaturedProductImage && actualFeaturedProductImage.src) {
                        currentMainImageSrc = actualFeaturedProductImage.src.split('?')[0];
                        if (currentMainImageSrc) {
                            var currentFilenameMatch = currentMainImageSrc.match(/\/([^\/]+)$/);
                            if (currentFilenameMatch) {
                                currentMainImageFilename = currentFilenameMatch[1].toLowerCase();
                                var currentBaseNameMatch = currentMainImageFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                if (currentBaseNameMatch) {
                                    currentMainImageBaseName = currentBaseNameMatch[1].toLowerCase();
                                }
                            }
                        }
                        // Update module-level variables with actual featured image values
                        mainProductImageSrc = currentMainImageSrc;
                        mainProductImageFilename = currentMainImageFilename;
                        mainProductImageBaseName = currentMainImageBaseName;
                    } else {
                        // Fallback: if PHP data not available, use module-level variables set during collection
                        currentMainImageSrc = mainProductImageSrc;
                        currentMainImageFilename = mainProductImageFilename;
                        currentMainImageBaseName = mainProductImageBaseName;
                    }
                    
                    // Final safety check: Remove main product image one more time right before creating thumbnails
                    // Use the CURRENT main image info (may have changed after variation selection)
                    if (currentMainImageSrc || currentMainImageFilename || currentMainImageBaseName) {
                        var beforeFinalFilter = thumbnailImages.length;
                        thumbnailImages = thumbnailImages.filter(function(img, filterIdx) {
                            var imgSrc = (img.normalizedSrc || img.src || '').split('?')[0];
                            var imgFilename = '';
                            var imgBaseName = '';
                            var filenameMatch = imgSrc.match(/\/([^\/]+)$/);
                            if (filenameMatch) {
                                imgFilename = filenameMatch[1].toLowerCase();
                                var baseNameMatch = imgFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                if (baseNameMatch) {
                                    imgBaseName = baseNameMatch[1].toLowerCase();
                                }
                            }
                            
                            var isMainImage = false;
                            var matchReason = '';
                            // Check against CURRENT main image (not the old one)
                            if (currentMainImageSrc && imgSrc === currentMainImageSrc) {
                                isMainImage = true;
                                matchReason = 'URL match';
                            }
                            if (!isMainImage && currentMainImageFilename && imgFilename && imgFilename === currentMainImageFilename) {
                                isMainImage = true;
                                matchReason = 'filename match';
                            }
                            if (!isMainImage && currentMainImageBaseName && imgBaseName && imgBaseName === currentMainImageBaseName) {
                                isMainImage = true;
                                matchReason = 'basename match: ' + imgBaseName + ' === ' + currentMainImageBaseName;
                            }
                            
                            return !isMainImage;
                        });
                    }
                    
                    // Log summary of what we're about to create
                    // Set container width to fit all thumbnails
                    var totalThumbnailsWidth = (thumbWidth * thumbnailImages.length) + (gap * (thumbnailImages.length - 1));
                    $container.css('width', totalThumbnailsWidth + 'px');
                    thumbnailImages.forEach(function(imgData, index) {
                        var imgFilename = (imgData.normalizedSrc || imgData.src || '').split('/').pop();
                        var imgSrc = (imgData.normalizedSrc || imgData.src || '').split('?')[0];
                        
                        // Double-check this isn't the main image (use CURRENT main image info)
                        var isMainImage = false;
                        var matchDetails = '';
                        if (currentMainImageSrc || currentMainImageFilename || currentMainImageBaseName) {
                            var thumbFilename = '';
                            var thumbBaseName = '';
                            var filenameMatch = imgSrc.match(/\/([^\/]+)$/);
                            if (filenameMatch) {
                                thumbFilename = filenameMatch[1].toLowerCase();
                                var baseNameMatch = thumbFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                if (baseNameMatch) {
                                    thumbBaseName = baseNameMatch[1].toLowerCase();
                                }
                            }
                            
                            if (currentMainImageSrc && imgSrc === currentMainImageSrc) {
                                isMainImage = true;
                                matchDetails = 'URL: ' + imgSrc + ' === ' + currentMainImageSrc;
                                console.error('OA TFP: ERROR - Main product image match by URL at position', index, '-', matchDetails);
                            }
                            if (!isMainImage && currentMainImageFilename && thumbFilename && thumbFilename === currentMainImageFilename) {
                                isMainImage = true;
                                matchDetails = 'Filename: ' + thumbFilename + ' === ' + currentMainImageFilename;
                                console.error('OA TFP: ERROR - Main product image match by filename at position', index, '-', matchDetails);
                            }
                            if (!isMainImage && currentMainImageBaseName && thumbBaseName && thumbBaseName === currentMainImageBaseName) {
                                isMainImage = true;
                                matchDetails = 'Basename: ' + thumbBaseName + ' === ' + currentMainImageBaseName;
                                console.error('OA TFP: ERROR - Main product image match by basename at position', index, '-', matchDetails);
                            }
                            
                        }
                        
                        if (isMainImage) {
                            console.error('OA TFP: ERROR - Main product image found at position', index, 'when creating thumbnail! Skipping...', imgFilename);
                            // Add a special class to mark this as the main image for debugging
                            var $thumb = $('<div class="oa-tfp-thumbnail-item oa-tfp-main-image-error" data-thumb-index="' + index + '" data-thumb-type="' + (imgData.variation_id === 0 ? 'gallery' : 'variation') + '">' +
                                '<img src="' + imgData.src + '" alt="" data-thumb-index="' + index + '" data-thumb-filename="' + imgFilename + '" style="border: 3px solid red !important; opacity: 0.5;" />' +
                                '<span style="position: absolute; top: 0; left: 0; background: red; color: white; padding: 4px; font-weight: bold; z-index: 1001;">MAIN IMAGE ERROR</span>' +
                                '</div>');
                            $thumb.css({
                                'width': thumbWidth + 'px',
                                'height': thumbWidth + 'px',
                                'min-width': thumbWidth + 'px',
                                'position': 'relative'
                            });
                            $container.append($thumb);
                            return; // Don't create normal thumbnail for main image
                        }
                        
                        
                        // Add data attribute to help identify thumbnails
                        var imgFilename = (imgData.normalizedSrc || imgData.src || '').split('/').pop();
                        var $thumb = $('<div class="oa-tfp-thumbnail-item' + (index === currentThumbnailIndex ? ' active' : '') + '" data-thumb-index="' + index + '" data-thumb-type="' + (imgData.variation_id === 0 ? 'gallery' : 'variation') + '">' +
                            '<img src="' + imgData.src + '" alt="" data-thumb-index="' + index + '" data-thumb-filename="' + imgFilename + '" />' +
                            '</div>');
                        
                        
                        // Set explicit width and height for thumbnail
                        $thumb.css({
                            'width': thumbWidth + 'px',
                            'height': thumbWidth + 'px',
                            'min-width': thumbWidth + 'px'
                        });
                        
                        $thumb.on('click', function(e) {
                            e.preventDefault();
                            selectThumbnail(index);
                            return false;
                        });
                        
                        $container.append($thumb);
                    });
                    
                    
                    // Verify what was actually created in DOM - check multiple times to catch any changes
                    setTimeout(function() {
                        // Use the ACTUAL featured product image from PHP for verification (not DOM, which may show a variation)
                        var verifyMainSrc = '';
                        var verifyMainFilename = '';
                        var verifyMainBaseName = '';
                        if (actualFeaturedProductImage && actualFeaturedProductImage.src) {
                            verifyMainSrc = actualFeaturedProductImage.src.split('?')[0];
                            if (verifyMainSrc) {
                                var verifyFilenameMatch = verifyMainSrc.match(/\/([^\/]+)$/);
                                if (verifyFilenameMatch) {
                                    verifyMainFilename = verifyFilenameMatch[1].toLowerCase();
                                    var verifyBaseNameMatch = verifyMainFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                    if (verifyBaseNameMatch) {
                                        verifyMainBaseName = verifyBaseNameMatch[1].toLowerCase();
                                    }
                                }
                            }
                        } else {
                            // Fallback to module-level variables
                            verifyMainSrc = mainProductImageSrc;
                            verifyMainFilename = mainProductImageFilename;
                            verifyMainBaseName = mainProductImageBaseName;
                        }
                        
                        var $createdThumbs = $container.find('.oa-tfp-thumbnail-item');
                        $createdThumbs.slice(0, 4).each(function(idx) {
                            var $thumb = $(this);
                            var $img = $thumb.find('img');
                            var imgSrc = $img.attr('src') || '';
                            var imgFilename = imgSrc.split('/').pop().split('?')[0];
                            var thumbIndex = $thumb.attr('data-thumb-index');
                            var isMainImage = false;
                            // Check against verified current main image
                            if (verifyMainSrc || verifyMainFilename || verifyMainBaseName) {
                                var domImgSrc = imgSrc.split('?')[0];
                                var domImgFilename = imgFilename.toLowerCase();
                                var domImgBaseName = '';
                                var domFilenameMatch = domImgFilename.match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                if (domFilenameMatch) {
                                    domImgBaseName = domFilenameMatch[1].toLowerCase();
                                }
                                
                                if (verifyMainSrc && domImgSrc === verifyMainSrc) {
                                    isMainImage = true;
                                }
                                if (!isMainImage && verifyMainFilename && domImgFilename === verifyMainFilename) {
                                    isMainImage = true;
                                }
                                if (!isMainImage && verifyMainBaseName && domImgBaseName && domImgBaseName === verifyMainBaseName) {
                                    isMainImage = true;
                                }
                            }
                            if (isMainImage) {
                                console.error('OA TFP: ERROR - Main product image found in DOM at position', idx);
                            }
                            // Also check if the data attributes match
                            if (thumbIndex && parseInt(thumbIndex) !== idx) {
                                console.error('OA TFP: ERROR - Thumbnail index mismatch! DOM position', idx, 'has data-thumb-index', thumbIndex);
                            }
                        });
                    }, 200);
                    
                    // Check again after a longer delay to see if something changes it
                    setTimeout(function() {
                        var $createdThumbs = $container.find('.oa-tfp-thumbnail-item');
                        $createdThumbs.slice(0, 4).each(function(idx) {
                            var $img = $(this).find('img');
                            var imgSrc = $img.attr('src') || '';
                            var imgFilename = imgSrc.split('/').pop().split('?')[0];
                            var isMainImage = false;
                            if (mainProductImageBaseName) {
                                var filenameMatch = imgFilename.toLowerCase().match(/^(.+?)(-\d+x\d+)?(\.[^.]+)?$/);
                                if (filenameMatch && filenameMatch[1] === mainProductImageBaseName) {
                                    isMainImage = true;
                                }
                            }
                            if (isMainImage) {
                                console.error('OA TFP: ERROR - Main product image STILL in DOM at position', idx, 'after 1 second!');
                            }
                        });
                    }, 1000);
                    
                    // Create navigation arrows
                    var $prevArrow = $('<button class="oa-tfp-thumbnail-nav prev" type="button" aria-label="Previous thumbnails">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                        '<path d="M15 18l-6-6 6-6"/>' +
                        '</svg></button>');
                    
                    var $nextArrow = $('<button class="oa-tfp-thumbnail-nav next" type="button" aria-label="Next thumbnails">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                        '<path d="M9 18l6-6-6-6"/>' +
                        '</svg></button>');
                    
                    // Arrow click handlers
                    $prevArrow.on('click', function(e) {
                        e.preventDefault();
                        navigateThumbnails('prev');
                        return false;
                    });
                    
                    $nextArrow.on('click', function(e) {
                        e.preventDefault();
                        navigateThumbnails('next');
                        return false;
                    });
                    
                    // Append arrows to wrapper (not container) so they're positioned correctly
                    $wrapper.append($thumbnailContainer);
                    $wrapper.append($prevArrow);
                    $wrapper.append($nextArrow);
                    
                    // Insert after gallery wrapper
                    $gallery.append($wrapper);
                    
                    // Update navigation state
                    updateThumbnailNavigation();
                    
                    // Update position after a short delay to ensure width is calculated
                    setTimeout(function() {
                        updateThumbnailPosition();
                    }, 100);
                    
                    console.log('OA TFP: Created thumbnail gallery with', thumbnailImages.length, 'images');
                }
                
                // Navigate thumbnails (prev/next page)
                function navigateThumbnails(direction) {
                    var totalPages = Math.ceil(thumbnailImages.length / thumbnailsPerPage);
                    
                    if (direction === 'prev' && currentPage > 0) {
                        currentPage--;
                    } else if (direction === 'next' && currentPage < totalPages - 1) {
                        currentPage++;
                    }
                    
                    updateThumbnailPosition();
                    updateThumbnailNavigation();
                }
                
                // Update thumbnail container position
                function updateThumbnailPosition() {
                    var $container = $('.oa-tfp-thumbnail-gallery-container');
                    var $gallery = $('.oa-tfp-thumbnail-gallery');
                    if (!$container.length || !$gallery.length) return;
                    
                    // Get gallery width (actual rendered width within wrapper)
                    var galleryWidth = $gallery.width();
                    if (galleryWidth === 0) {
                        // Fallback: try to get from wrapper
                        var $wrapper = $('.oa-tfp-thumbnail-gallery-wrapper');
                        if ($wrapper.length) {
                            // Get wrapper width minus padding
                            var wrapperWidth = $wrapper.width();
                            var paddingLeft = parseInt($wrapper.css('padding-left')) || 40;
                            var paddingRight = parseInt($wrapper.css('padding-right')) || 40;
                            galleryWidth = wrapperWidth - paddingLeft - paddingRight;
                        }
                        if (galleryWidth === 0) {
                            galleryWidth = 600; // Final fallback
                        }
                    }
                    
                    // Calculate thumbnail width: (gallery width - 3 gaps - buffer) / 4
                    var isMobile = window.innerWidth <= 768;
                    var gap = isMobile ? 8 : 10;
                    var buffer = 2; // Small buffer to prevent overlap
                    var thumbWidth = (galleryWidth - (gap * 3) - buffer) / 4;
                    
                    // Calculate offset, but ensure we don't scroll too far
                    var totalPages = Math.ceil(thumbnailImages.length / thumbnailsPerPage);
                    var maxPage = Math.max(0, totalPages - 1);
                    currentPage = Math.min(currentPage, maxPage); // Ensure we don't exceed max page
                    
                    var offset = currentPage * (thumbWidth + gap) * thumbnailsPerPage;
                    
                    // Calculate maximum possible offset (container width - gallery width)
                    var containerWidth = $container.width();
                    var maxOffset = Math.max(0, containerWidth - galleryWidth);
                    
                    // Ensure offset doesn't exceed maximum
                    offset = Math.min(offset, maxOffset);
                    
                    $container.css('transform', 'translateX(-' + offset + 'px)');
                }
                
                // Update navigation arrow states
                function updateThumbnailNavigation() {
                    var totalPages = Math.ceil(thumbnailImages.length / thumbnailsPerPage);
                    var $prev = $('.oa-tfp-thumbnail-nav.prev');
                    var $next = $('.oa-tfp-thumbnail-nav.next');
                    
                    if (currentPage <= 0) {
                        $prev.addClass('disabled');
                    } else {
                        $prev.removeClass('disabled');
                    }
                    
                    if (currentPage >= totalPages - 1) {
                        $next.addClass('disabled');
                    } else {
                        $next.removeClass('disabled');
                    }
                }
                
                // Select thumbnail and update main image
                function selectThumbnail(index) {
                    if (index < 0 || index >= thumbnailImages.length) return;
                    
                    currentThumbnailIndex = index;
                    var imageData = thumbnailImages[index];
                    var $mainImage = $('.woocommerce-product-gallery__image:first .wp-post-image');
                    var $mainImageWrap = $mainImage.closest('.woocommerce-product-gallery__image');
                    var $mainImageLink = $mainImageWrap.find('a');
                    
                    if ($mainImage.length) {
                        // Update main image
                        if (imageData.src) {
                            $mainImage.attr('src', imageData.src);
                            $mainImage.attr('data-src', imageData.src);
                        }
                        if (imageData.srcset) {
                            $mainImage.attr('srcset', imageData.srcset);
                        }
                        if (imageData.sizes) {
                            $mainImage.attr('sizes', imageData.sizes);
                        }
                        if (imageData.title) {
                            $mainImage.attr('title', imageData.title);
                        }
                        if (imageData.alt) {
                            $mainImage.attr('alt', imageData.alt);
                        }
                        // Remove width/height attributes to let CSS control the size for square aspect ratio
                        $mainImage.removeAttr('width');
                        $mainImage.removeAttr('height');
                        
                        // Update link
                        if ($mainImageLink.length && imageData.src) {
                            $mainImageLink.attr('href', imageData.src);
                        }
                        
                        // Trigger zoom refresh
                        setTimeout(function() {
                            if (typeof $mainImageWrap.trigger === 'function') {
                                $mainImageWrap.trigger('woocommerce_gallery_init_zoom');
                            }
                        }, 50);
                    }
                    
                    // Update active thumbnail
                    $('.oa-tfp-thumbnail-item').removeClass('active');
                    var $activeThumb = $('.oa-tfp-thumbnail-item').eq(index);
                    $activeThumb.addClass('active');
                    
                    // Navigate to page containing this thumbnail
                    var newPage = Math.floor(index / thumbnailsPerPage);
                    if (newPage !== currentPage) {
                        currentPage = newPage;
                        updateThumbnailPosition();
                        updateThumbnailNavigation();
                    }
                }
                
                // Update thumbnails when variation changes
                $(document).on('oa_tfp_update_thumbnails', function() {
                    setTimeout(function() {
                        collectThumbnailImages();
                        // Update active thumbnail based on current main image
                        var $currentImage = $('.woocommerce-product-gallery__image:first .wp-post-image');
                        if ($currentImage.length && thumbnailImages.length > 0) {
                            var currentSrc = ($currentImage.attr('src') || $currentImage.attr('data-src') || '').split('?')[0];
                            var foundIndex = -1;
                            for (var i = 0; i < thumbnailImages.length; i++) {
                                var imgSrc = (thumbnailImages[i].normalizedSrc || thumbnailImages[i].src || '').split('?')[0];
                                if (imgSrc === currentSrc) {
                                    foundIndex = i;
                                    break;
                                }
                            }
                            if (foundIndex >= 0) {
                                $('.oa-tfp-thumbnail-item').removeClass('active');
                                var $activeThumb = $('.oa-tfp-thumbnail-item').eq(foundIndex);
                                $activeThumb.addClass('active');
                                currentThumbnailIndex = foundIndex;
                                
                                // Navigate to page containing this thumbnail
                                var newPage = Math.floor(foundIndex / thumbnailsPerPage);
                                if (newPage !== currentPage) {
                                    currentPage = newPage;
                                    updateThumbnailPosition();
                                    updateThumbnailNavigation();
                                }
                            }
                        }
                    }, 100);
                });
                
                // Add class to gallery immediately when thumbnail mode is detected
                var $gallery = $('.woocommerce-product-gallery');
                if ($gallery.length) {
                    $gallery.addClass('oa-tfp-thumbnail-mode');
                }
                
            // Initialize thumbnail gallery
                setTimeout(function() {
                    createThumbnailGallery();
                }, 500);
                
                // Re-create on variation changes
                $form.on('found_variation', function() {
                    setTimeout(function() {
                        createThumbnailGallery();
                    }, 200);
                });
                
                // Handle window resize to recalculate thumbnail positions
                var resizeTimer;
                $(window).on('resize', function() {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(function() {
                        var $gallery = $('.oa-tfp-thumbnail-gallery');
                        var $mainImage = $('.woocommerce-product-gallery__image:first');
                        if ($gallery.length && $mainImage.length) {
                            var mainImageWidth = $mainImage.width();
                            if (mainImageWidth > 0) {
                                $gallery.css('width', mainImageWidth + 'px');
                                
                                // Recalculate thumbnail sizes based on actual gallery width
                                var $gallery = $('.oa-tfp-thumbnail-gallery');
                                var galleryWidth = $gallery.length ? $gallery.width() : mainImageWidth;
                                
                                var isMobile = window.innerWidth <= 768;
                                var gap = isMobile ? 8 : 10;
                                var buffer = 2; // Small buffer to prevent overlap
                                var thumbWidth = (galleryWidth - (gap * 3) - buffer) / 4;
                                
                                // Update all thumbnail sizes
                                $('.oa-tfp-thumbnail-item').css({
                                    'width': thumbWidth + 'px',
                                    'height': thumbWidth + 'px',
                                    'min-width': thumbWidth + 'px'
                                });
                                
                                // Update container width
                                var $container = $('.oa-tfp-thumbnail-gallery-container');
                                if ($container.length) {
                                    var totalThumbnailsWidth = (thumbWidth * thumbnailImages.length) + (gap * (thumbnailImages.length - 1));
                                    $container.css('width', totalThumbnailsWidth + 'px');
                                }
                                
                                updateThumbnailPosition();
                            }
                        }
                    }, 250);
                });
            }
        });
        
        // Fix for blank images on mobile swipe in FlexSlider
        // Intercept FlexSlider initialization and modify the after callback
        jQuery(document).ready(function($) {
            // Function to fix images in a slide
            function fixSlideImages($slide) {
                if (!$slide || !$slide.length) return;
                
                $slide.find('img.wp-post-image').each(function() {
                    var $img = $(this);
                    var imgEl = this;
                    var currentSrc = $img.attr('src');
                    
                    // Check if image is blank or missing src
                    if (!currentSrc || currentSrc === '' || currentSrc === '#' || currentSrc === 'data:image/svg+xml' || imgEl.naturalWidth === 0) {
                        // Try data-large_image first (WooCommerce uses this)
                        var dataLargeImage = $img.attr('data-large_image');
                        if (dataLargeImage && dataLargeImage !== '' && dataLargeImage !== '#') {
                            $img.attr('src', dataLargeImage);
                            imgEl.src = dataLargeImage;
                            
                            // Ensure image loads and maintains layout
                            $(imgEl).on('load', function() {
                                // Trigger FlexSlider resize if available
                                var $gallery = $slide.closest('.woocommerce-product-gallery.flexslider');
                                if ($gallery.length) {
                                    var flexslider = $gallery.data('flexslider');
                                    if (flexslider && typeof flexslider.resize === 'function') {
                                        setTimeout(function() {
                                            flexslider.resize();
                                        }, 50);
                                    }
                                }
                            });
                            return;
                        }
                        
                        // Try data-src
                        var dataSrc = $img.attr('data-src');
                        if (dataSrc && dataSrc !== '' && dataSrc !== '#') {
                            $img.attr('src', dataSrc);
                            imgEl.src = dataSrc;
                            
                            $(imgEl).on('load', function() {
                                var $gallery = $slide.closest('.woocommerce-product-gallery.flexslider');
                                if ($gallery.length) {
                                    var flexslider = $gallery.data('flexslider');
                                    if (flexslider) {
                                        // Let FlexSlider recalculate everything
                                        setTimeout(function() {
                                            if (typeof flexslider.resize === 'function') {
                                                flexslider.resize();
                                            }
                                            // Also trigger a manual recalculation
                                            var $viewport = $gallery.find('.flex-viewport');
                                            var $wrapper = $gallery.find('.woocommerce-product-gallery__wrapper');
                                            var $slides = $gallery.find('.woocommerce-product-gallery__image');
                                            
                                            if ($viewport.length && $wrapper.length && $slides.length) {
                                                var viewportWidth = $viewport.width();
                                                if (viewportWidth > 0) {
                                                    // Set wrapper width based on number of slides
                                                    var totalWidth = viewportWidth * $slides.length;
                                                    $wrapper.css('width', totalWidth + 'px');
                                                    
                                                    // Ensure each slide has viewport width
                                                    $slides.css({
                                                        'width': viewportWidth + 'px',
                                                        'min-width': viewportWidth + 'px',
                                                        'max-width': viewportWidth + 'px'
                                                    });
                                                }
                                            }
                                        }, 100);
                                    }
                                }
                            });
                            return;
                        }
                        
                        // Try getting from parent link href
                        var $link = $img.closest('a');
                        if ($link.length) {
                            var href = $link.attr('href');
                            if (href && href !== '#' && href.indexOf('data:') !== 0) {
                                $img.attr('src', href);
                                imgEl.src = href;
                                
                                $(imgEl).on('load', function() {
                                    var $gallery = $slide.closest('.woocommerce-product-gallery.flexslider');
                                    if ($gallery.length) {
                                        var flexslider = $gallery.data('flexslider');
                                        if (flexslider && typeof flexslider.resize === 'function') {
                                            setTimeout(function() {
                                                flexslider.resize();
                                            }, 50);
                                        }
                                    }
                                });
                                return;
                            }
                        }
                        
                        // Try getting from slide data attributes
                        var slideDataThumb = $slide.attr('data-thumb');
                        if (slideDataThumb && slideDataThumb !== '' && slideDataThumb !== '#') {
                            $img.attr('src', slideDataThumb);
                            imgEl.src = slideDataThumb;
                            
                            $(imgEl).on('load', function() {
                                var $gallery = $slide.closest('.woocommerce-product-gallery.flexslider');
                                if ($gallery.length) {
                                    var flexslider = $gallery.data('flexslider');
                                    if (flexslider) {
                                        // Let FlexSlider recalculate everything
                                        setTimeout(function() {
                                            if (typeof flexslider.resize === 'function') {
                                                flexslider.resize();
                                            }
                                            // Also trigger a manual recalculation
                                            var $viewport = $gallery.find('.flex-viewport');
                                            var $wrapper = $gallery.find('.woocommerce-product-gallery__wrapper');
                                            var $slides = $gallery.find('.woocommerce-product-gallery__image');
                                            
                                            if ($viewport.length && $wrapper.length && $slides.length) {
                                                var viewportWidth = $viewport.width();
                                                if (viewportWidth > 0) {
                                                    // Set wrapper width based on number of slides
                                                    var totalWidth = viewportWidth * $slides.length;
                                                    $wrapper.css('width', totalWidth + 'px');
                                                    
                                                    // Ensure each slide has viewport width
                                                    $slides.css({
                                                        'width': viewportWidth + 'px',
                                                        'min-width': viewportWidth + 'px',
                                                        'max-width': viewportWidth + 'px'
                                                    });
                                                }
                                            }
                                        }, 100);
                                    }
                                }
                            });
                        }
                    }
                    
                    // Ensure srcset is set if available
                    var dataSrcset = $img.attr('data-srcset');
                    if (dataSrcset && !$img.attr('srcset')) {
                        $img.attr('srcset', dataSrcset);
                    }
                    
                    // Ensure slide maintains horizontal layout with proper width
                    // Get the viewport width to calculate slide width
                    var $gallery = $slide.closest('.woocommerce-product-gallery.flexslider');
                    if ($gallery.length) {
                        var $viewport = $gallery.find('.flex-viewport');
                        if ($viewport.length) {
                            var viewportWidth = $viewport.width();
                            if (viewportWidth > 0) {
                                // Set slide width to match viewport (one slide per view)
                                $slide.css({
                                    'float': 'left',
                                    'display': 'block',
                                    'width': viewportWidth + 'px',
                                    'margin-right': '0px',
                                    'min-width': viewportWidth + 'px',
                                    'max-width': viewportWidth + 'px'
                                });
                            }
                        }
                    }
                });
            }
            
            // Intercept WooCommerce gallery initialization
            $(document).on('wc-product-gallery-before-init', function(e, gallery, params) {
                // Modify flexslider options if they exist
                if (params && params.flexslider) {
                    // Disable touch/swipe functionality
                    params.flexslider.touch = false;
                    params.flexslider.mousewheel = false;
                    params.flexslider.keyboard = false;
                    
                    var originalAfter = params.flexslider.after;
                    params.flexslider.after = function(slider) {
                        // Call original after callback if it exists
                        if (originalAfter && typeof originalAfter === 'function') {
                            originalAfter.call(this, slider);
                        }
                        
                        // Fix images in the current slide
                        var $gallery = $(gallery);
                        var $slides = $gallery.find('.woocommerce-product-gallery__image');
                        var currentSlide = slider.currentSlide !== undefined ? slider.currentSlide : 0;
                        var $currentSlide = $slides.eq(currentSlide);
                        
                        // Fix current slide
                        fixSlideImages($currentSlide);
                        
                        // Preload next slide
                        var nextSlide = currentSlide + 1;
                        if (nextSlide < $slides.length) {
                            fixSlideImages($slides.eq(nextSlide));
                        }
                        
                        // Ensure FlexSlider wrapper maintains proper width in pixels
                        var $wrapper = $gallery.find('.woocommerce-product-gallery__wrapper');
                        var $viewport = $gallery.find('.flex-viewport');
                        if ($wrapper.length && $viewport.length) {
                            var viewportWidth = $viewport.width();
                            if (viewportWidth > 0) {
                                // Calculate total width needed in pixels (not percentage)
                                var totalWidth = viewportWidth * $slides.length;
                                
                                // Set wrapper width in pixels
                                $wrapper.css('width', totalWidth + 'px');
                                
                                // Ensure each slide has the correct width
                                $slides.each(function() {
                                    var $slide = $(this);
                                    $slide.css({
                                        'float': 'left',
                                        'display': 'block',
                                        'width': viewportWidth + 'px',
                                        'margin-right': '0px',
                                        'min-width': viewportWidth + 'px',
                                        'max-width': viewportWidth + 'px'
                                    });
                                });
                            }
                        }
                        
                        // Trigger FlexSlider resize to recalculate layout
                        var flexslider = $gallery.data('flexslider');
                        if (flexslider) {
                            // Use FlexSlider's internal resize method
                            if (typeof flexslider.resize === 'function') {
                                setTimeout(function() {
                                    flexslider.resize();
                                }, 100);
                            }
                            // Also trigger a manual update
                            if (typeof flexslider.update === 'function') {
                                setTimeout(function() {
                                    flexslider.update();
                                }, 150);
                            }
                        }
                    };
                }
            });
            
            // Also hook into FlexSlider events as backup
            function setupFlexSliderFix() {
                var $gallery = $('.woocommerce-product-gallery.flexslider');
                if ($gallery.length) {
                    var flexslider = $gallery.data('flexslider');
                    if (flexslider) {
                        // Disable touch/swipe functionality
                        if (flexslider.vars) {
                            flexslider.vars.touch = false;
                            flexslider.vars.mousewheel = false;
                            flexslider.vars.keyboard = false;
                        }
                        
                        // Disable touch events on the gallery - remove all touch handlers
                        $gallery.off('touchstart touchmove touchend');
                        $gallery.find('.flex-viewport').off('touchstart touchmove touchend');
                        $gallery.find('.woocommerce-product-gallery__wrapper').off('touchstart touchmove touchend');
                        
                        // Prevent touch events from working by stopping propagation
                        $gallery.on('touchstart touchmove touchend', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            return false;
                        });
                        
                        $gallery.find('.flex-viewport, .woocommerce-product-gallery__wrapper').on('touchstart touchmove touchend', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            return false;
                        });
                        
                        // Get original after callback
                        var originalAfter = flexslider.vars.after;
                        
                        // Override the after callback
                        flexslider.vars.after = function(slider) {
                            // Call original if it exists
                            if (originalAfter && typeof originalAfter === 'function') {
                                originalAfter.call(this, slider);
                            }
                            
                            // Fix images
                            var $slides = $gallery.find('.woocommerce-product-gallery__image');
                            var currentSlide = slider.currentSlide !== undefined ? slider.currentSlide : 0;
                            fixSlideImages($slides.eq(currentSlide));
                            
                            // Preload next
                            if (currentSlide + 1 < $slides.length) {
                                fixSlideImages($slides.eq(currentSlide + 1));
                            }
                        };
                        
                        // Fix all slides initially
                        $gallery.find('.woocommerce-product-gallery__image').each(function() {
                            fixSlideImages($(this));
                        });
                    }
                }
            }
            
            // Try to setup fix after gallery initialization
            $(document).on('wc-product-gallery-after-init', function() {
                setTimeout(setupFlexSliderFix, 100);
            });
            
            // Also try on page load
            setTimeout(function() {
                setupFlexSliderFix();
                
                // Fix all images in gallery immediately
                $('.woocommerce-product-gallery__image').each(function() {
                    fixSlideImages($(this));
                });
                
                // Force disable touch/swipe on FlexSlider - more aggressive approach
                var $gallery = $('.woocommerce-product-gallery.flexslider');
                console.log('OA TFP: Attempting to disable swipe on gallery:', $gallery.length, 'galleries found');
                
                if ($gallery.length) {
                    var flexslider = $gallery.data('flexslider');
                    console.log('OA TFP: FlexSlider instance found:', !!flexslider);
                    
                    if (flexslider) {
                        // Disable touch in vars
                        if (flexslider.vars) {
                            flexslider.vars.touch = false;
                            flexslider.vars.mousewheel = false;
                            flexslider.vars.keyboard = false;
                            console.log('OA TFP: Disabled touch in vars:', flexslider.vars.touch);
                        }
                        
                        // Get the actual DOM element (FlexSlider uses native events)
                        var galleryEl = $gallery[0];
                        var viewportEl = $gallery.find('.flex-viewport')[0];
                        var wrapperEl = $gallery.find('.woocommerce-product-gallery__wrapper')[0];
                        
                        // Remove native event listeners by cloning and replacing (nuclear option)
                        function disableTouchOnElement(el, name) {
                            if (!el) return;
                            
                            // Prevent touch events using capture phase
                            var preventTouch = function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                e.stopImmediatePropagation();
                                return false;
                            };
                            
                            el.addEventListener('touchstart', preventTouch, {capture: true, passive: false});
                            el.addEventListener('touchmove', preventTouch, {capture: true, passive: false});
                            el.addEventListener('touchend', preventTouch, {capture: true, passive: false});
                            
                            console.log('OA TFP: Disabled touch on', name);
                        }
                        
                        disableTouchOnElement(galleryEl, 'gallery');
                        if (viewportEl) disableTouchOnElement(viewportEl, 'viewport');
                        if (wrapperEl) disableTouchOnElement(wrapperEl, 'wrapper');
                        
                        // Also use jQuery as backup
                        $gallery.on('touchstart touchmove touchend', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            return false;
                        });
                        
                        $gallery.find('.flex-viewport, .woocommerce-product-gallery__wrapper').on('touchstart touchmove touchend', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            return false;
                        });
                    } else {
                        console.log('OA TFP: FlexSlider instance not found - gallery may not be initialized yet');
                    }
                } else {
                    console.log('OA TFP: No gallery found with selector .woocommerce-product-gallery.flexslider');
                }
            }, 500);
            
            // Monitor for slide changes via MutationObserver as additional backup
            if (typeof MutationObserver !== 'undefined') {
                var $gallery = $('.woocommerce-product-gallery.flexslider');
                if ($gallery.length) {
                    var observer = new MutationObserver(function(mutations) {
                        // Check if slide changed by looking at transform
                        var $viewport = $gallery.find('.flex-viewport');
                        if ($viewport.length) {
                            var $wrapper = $viewport.find('.woocommerce-product-gallery__wrapper');
                            if ($wrapper.length) {
                                var transform = $wrapper.css('transform') || $wrapper[0].style.transform;
                                if (transform && transform !== 'none') {
                                    // Extract translateX value to determine current slide
                                    var matches = transform.match(/translate3d\(([^,]+)/);
                                    if (matches) {
                                        var translateX = parseInt(matches[1]);
                                        var $slides = $gallery.find('.woocommerce-product-gallery__image');
                                        if ($slides.length) {
                                            var slideWidth = $slides.first().outerWidth(true) || 305;
                                            var currentSlide = Math.round(Math.abs(translateX) / slideWidth);
                                            if (currentSlide < $slides.length) {
                                                fixSlideImages($slides.eq(currentSlide));
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    });
                    
                    setTimeout(function() {
                        var $viewport = $gallery.find('.flex-viewport');
                        if ($viewport.length) {
                            observer.observe($viewport[0], {
                                attributes: true,
                                attributeFilter: ['style', 'class'],
                                subtree: true
                            });
                        }
                    }, 1000);
                }
            }
        });
        </script>
        <?php
    }
    
    /**
     * Add quote form auto-population JavaScript
     */
    public function add_quote_form_js() {
        // Only add this script on the quote page
        $quote_page_id = get_option('oa_tfp_quote_page');
        if (!$quote_page_id || !is_page($quote_page_id)) {
            return;
        }
        
        // Check if debug mode is enabled
        $debug_mode = get_option('oa_tfp_debug_mode', false);
        ?>
        <?php if ($debug_mode): ?>
        <div id="oa-tfp-debug-panel" style="position: fixed; top: 10px; right: 10px; width: 400px; max-height: 500px; background: #fff; border: 2px solid #0073aa; border-radius: 5px; padding: 15px; z-index: 9999; font-family: monospace; font-size: 12px; overflow-y: auto; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
            <h4 style="margin: 0 0 10px 0; color: #0073aa;">Quote Form Debug</h4>
            <div id="oa-tfp-debug-content">Loading...</div>
            <button onclick="document.getElementById('oa-tfp-debug-panel').style.display='none'" style="margin-top: 10px; padding: 5px 10px; background: #dc3232; color: white; border: none; border-radius: 3px; cursor: pointer;">Close</button>
        </div>
        <?php endif; ?>
        <div id="oa-tfp-populating-overlay" style="position:fixed;inset:0;z-index:100000;background:rgba(255,255,255,0.85);display:none;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;">
            <div class="oa-tfp-populating-center" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;color:#222;">
                <div id="oa-tfp-populating-content" style="margin-bottom:12px;">
                    <div style="width:40px;height:40px;border:4px solid #d0d7de;border-top-color:#0073aa;border-radius:50%;margin:0 auto;animation:oaSpin 1s linear infinite;"></div>
                </div>
                <div style="font-size:18px;font-weight:600;">Preparing quote options…</div>
            </div>
        </div>
        <style>
            @keyframes oaSpin{to{transform:rotate(360deg)}}
            .gform_required_legend{display:none!important}
            .oa-loader-background{background-color:transparent!important}
            #oa-site-loader{display:none!important}
            /* Ensure variable option labels are never greyed out */
            .oa-tfp-option.disabled, .oa-tfp-option.unavailable { opacity: 1 !important; filter:none !important; pointer-events:auto !important; }
            .oa-tfp-timber-label-text.disabled, .oa-tfp-timber-label-text.unavailable,
            .oa-tfp-metal-label-text.disabled, .oa-tfp-metal-label-text.unavailable,
            .oa-tfp-label-text.disabled, .oa-tfp-label-text.unavailable { opacity:1 !important; color:inherit !important; }
            
            /* Gravity Forms Popup/Modal Styling - Ensure it loads for dynamically loaded popups */
            .gpnf-dialog.ui-dialog,
            .gpnf-modal {
                font-family: inherit !important;
                z-index: 1000000 !important;
            }
            .gpnf-dialog .ui-dialog-titlebar {
                background: #222 !important;
                color: #fff !important;
                border: 0 !important;
                padding: 15px 20px !important;
                font-weight: 600 !important;
            }
            .gpnf-dialog .ui-dialog-title {
                color: #fff !important;
                font-size: 18px !important;
                font-weight: 600 !important;
            }
            .gpnf-dialog .ui-dialog-content {
                padding: 25px !important;
                background: #fff !important;
            }
            .gpnf-dialog .gform_wrapper {
                max-width: 100% !important;
            }
            .gpnf-dialog .gform_wrapper .gfield {
                margin-bottom: 20px !important;
            }
            .gpnf-dialog .gform_wrapper label {
                font-weight: 600 !important;
                color: #222 !important;
            }
            .gpnf-dialog .gform_wrapper input[type="text"],
            .gpnf-dialog .gform_wrapper input[type="email"],
            .gpnf-dialog .gform_wrapper input[type="number"],
            .gpnf-dialog .gform_wrapper select,
            .gpnf-dialog .gform_wrapper textarea {
                width: 100% !important;
                padding: 10px !important;
                border: 1px solid #ddd !important;
                border-radius: 4px !important;
                font-size: 14px !important;
            }
            .gpnf-dialog .gform_wrapper .gform_button,
            .gpnf-dialog .gform_wrapper input[type="submit"] {
                background: #222 !important;
                color: #fff !important;
                padding: 12px 24px !important;
                border: 0 !important;
                border-radius: 4px !important;
                font-weight: 600 !important;
                cursor: pointer !important;
                transition: background 0.3s ease !important;
            }
            .gpnf-dialog .gform_wrapper .gform_button:hover,
            .gpnf-dialog .gform_wrapper input[type="submit"]:hover {
                background: #444 !important;
            }
            .gpnf-dialog .gform_wrapper .gfield_radio label,
            .gpnf-dialog .gform_wrapper .gfield_checkbox label {
                font-weight: normal !important;
                margin-left: 8px !important;
            }
            .gpnf-dialog .gform_wrapper .gfield_radio input[type="radio"],
            .gpnf-dialog .gform_wrapper .gfield_checkbox input[type="checkbox"] {
                width: auto !important;
                margin-right: 5px !important;
            }
            .gpnf-dialog .ui-dialog-titlebar-close {
                color: #fff !important;
                opacity: 0.7 !important;
                transition: opacity 0.3s ease !important;
            }
            .gpnf-dialog .ui-dialog-titlebar-close:hover {
                opacity: 1 !important;
            }
            .gpnf-dialog .gform_wrapper .gfield_required {
                color: #c00 !important;
            }
            .gpnf-dialog .gform_wrapper .gfield_description {
                font-size: 13px !important;
                color: #666 !important;
                margin-top: 5px !important;
            }
        </style>
        <script>
        jQuery(function($) {
            <?php if ($debug_mode): ?>
            function updateDebugPanel(message, data) {}
            <?php endif; ?>
            
            // Check if we have quote data from product page
            var quoteData = localStorage.getItem('timberfans_quote_data');
            if (quoteData) {
                try {
                    var data = JSON.parse(quoteData);
                    
                    
                    
                    // Update debug panel if enabled
                    <?php if ($debug_mode): ?>
                    updateDebugPanel('Data received from product page', data);
                    <?php endif; ?>
                    
                    // Auto-populate Gravity Forms fields with retry mechanism
                    function attemptFormPopulation() {
                        console.log('=== ATTEMPTING TO POPULATE FORM ===');
                        console.log('All form inputs found:', $('input[name^="input_"]').length);
                        console.log('Form inputs:', $('input[name^="input_"]').map(function() { return $(this).attr('name'); }).get());
                        
                        // Update debug panel if enabled
                        <?php if ($debug_mode): ?>
                        var formInputs = $('input[name^="input_"]').length;
                        var fieldNames = $('input[name^="input_"]').map(function() { return $(this).attr('name'); }).get();
                        updateDebugPanel('Attempting form population', {
                            'Form inputs found': formInputs,
                            'Field names': fieldNames.join(', '),
                            'Data to populate': {
                                'fan_size': data.fan_size,
                                'timber_finish': data.timber_finish,
                                'metal_finish': data.metal_finish,
                                'product_name': data.product_name
                            }
                        });
                        <?php endif; ?>
                        
                        // Debug: Show all input_1 options
                        console.log('All input_1 options:');
                        $('input[name="input_1"]').each(function() {
                            console.log('Value:', $(this).val(), 'Text:', $(this).next('label').text());
                        });
                        
                        // Debug: Show all input_4 options (fan size)
                        console.log('All input_4 options (Fan Size):');
                        $('input[name^="input_4"]').each(function() {
                            console.log('Field:', $(this).attr('name'), 'Value:', $(this).val(), 'Label:', $(this).next('label').text());
                        });
                        
                        // Debug: Show all input_5 options (timber finish)
                        console.log('All input_5 options (Timber Finish):');
                        $('input[name^="input_5"]').each(function() {
                            console.log('Field:', $(this).attr('name'), 'Value:', $(this).val(), 'Label:', $(this).next('label').text());
                        });
                        
                        // Debug: Show all input_6 options (metal finish)
                        console.log('All input_6 options (Metal Finish):');
                        $('input[name^="input_6"]').each(function() {
                            console.log('Field:', $(this).attr('name'), 'Value:', $(this).val(), 'Label:', $(this).next('label').text());
                        });
                        
                        // Debug: Show all form elements
                        console.log('All form elements:');
                        $('form').each(function() {
                            console.log('Form found:', $(this).attr('id'), $(this).attr('class'));
                        });
                        
                        // Fan Range field (ID: 1) - Handle nested form
                        if (data.fan_range) {
                            console.log('Trying to select Fan Range:', data.fan_range);
                            // Try exact value (ID/slug) first
                            var fanRangeInput = $('input[name="input_1"][value="' + data.fan_range + '"]');
                            // Try nested form field pattern exact match
                            if (fanRangeInput.length === 0) {
                                fanRangeInput = $('input[name^="input_1"][value="' + data.fan_range + '"]');
                            }
                            console.log('Found Fan Range inputs:', fanRangeInput.length);
                            if (fanRangeInput.length === 0) {
                                console.log('No exact match found, trying partial match...');
                                fanRangeInput = $('input[name^="input_1"]').filter(function() {
                                    return $(this).val().toLowerCase().includes(data.fan_range.toLowerCase());
                                });
                                console.log('Partial match inputs:', fanRangeInput.length);
                            }
                            fanRangeInput.prop('checked', true);
                        }
                        
                        // Fan Size field (ID: 4) - Handle nested form
                        if (data.fan_size) {
                            console.log('Trying to select Fan Size:', data.fan_size);
                            var fanSizeInput = $('input[name^="input_4"][value="' + data.fan_size + '"]');
                            console.log('Found Fan Size inputs:', fanSizeInput.length);
                            if (fanSizeInput.length > 0) {
                                fanSizeInput.prop('checked', true);
                                
                                <?php if ($debug_mode): ?>
                                updateDebugPanel('Fan Size populated', {'value': data.fan_size, 'success': true});
                                <?php endif; ?>
                            } else {
                                
                                <?php if ($debug_mode): ?>
                                updateDebugPanel('Fan Size NOT populated', {'value': data.fan_size, 'success': false, 'reason': 'No matching field'});
                                <?php endif; ?>
                            }
                        }
                        
                        // Timber Finish field (ID: 5) - Handle nested form (try label, slug, and pa_timber-finish)
                        if (data.timber_finish || data['pa_timber-finish']) {
                            var timberCandidates = [];
                            if (data.timber_finish) timberCandidates.push(data.timber_finish);
                            if (data['pa_timber-finish']) timberCandidates.push(data['pa_timber-finish']);
                            if (data.timber_finish) timberCandidates.push(String(data.timber_finish).toLowerCase().replace(/\s+/g,'-'));
                            console.log('Trying Timber Finish candidates:', timberCandidates);
                            var timberInput = $();
                            for (var i=0;i<timberCandidates.length;i++) {
                                var c = timberCandidates[i];
                                if (!c) continue;
                                timberInput = $('input[name^="input_5"][value="' + c + '"]');
                                if (timberInput.length) break;
                            }
                            console.log('Found Timber inputs:', timberInput.length);
                            if (timberInput.length > 0) {
                                timberInput.prop('checked', true);
                                
                                <?php if ($debug_mode): ?>
                                updateDebugPanel('Timber Finish populated', {'value': timberInput.val(), 'success': true});
                                <?php endif; ?>
                            } else {
                                
                                <?php if ($debug_mode): ?>
                                updateDebugPanel('Timber Finish NOT populated', {'value': timberCandidates.join(', '), 'success': false});
                                <?php endif; ?>
                            }
                        }
                        
                        // Metal Finish field (ID: 6) - Handle nested form (try label, slug, and pa_metal-finish)
                        if (data.metal_finish || data['pa_metal-finish']) {
                            var metalCandidates = [];
                            if (data.metal_finish) metalCandidates.push(data.metal_finish);
                            if (data['pa_metal-finish']) metalCandidates.push(data['pa_metal-finish']);
                            if (data.metal_finish) metalCandidates.push(String(data.metal_finish).toLowerCase().replace(/\s+/g,'-'));
                            console.log('Trying Metal Finish candidates:', metalCandidates);
                            var metalInput = $();
                            for (var j=0;j<metalCandidates.length;j++) {
                                var mc = metalCandidates[j];
                                if (!mc) continue;
                                metalInput = $('input[name^="input_6"][value="' + mc + '"]');
                                if (metalInput.length) break;
                            }
                            console.log('Found Metal inputs:', metalInput.length);
                            if (metalInput.length > 0) {
                                metalInput.prop('checked', true);
                                
                                <?php if ($debug_mode): ?>
                                updateDebugPanel('Metal Finish populated', {'value': metalInput.val(), 'success': true});
                                <?php endif; ?>
                            } else {
                                
                                <?php if ($debug_mode): ?>
                                updateDebugPanel('Metal Finish NOT populated', {'value': metalCandidates.join(', '), 'success': false});
                                <?php endif; ?>
                            }
                        }
                        
                        // Speed Regulator field (ID: 7) - Handle nested form, auto-select first option
                        var speedInput = $('input[name^="input_7"]:first');
                        if (speedInput.length > 0) {
                            speedInput.prop('checked', true);
                            
                        }
                        
                        // Product name field (if exists) - try multiple field IDs
                        if (data.product_name) {
                            var productNameInput = $('input[name^="input_2"]');
                            if (productNameInput.length === 0) {
                                productNameInput = $('input[name^="input_"][type="text"]:first');
                            }
                            productNameInput.val(data.product_name);
                            
                        }
                        
                        // Quantity field (ID: 3) - Handle nested form
                        if (data.quantity) {
                            var quantityInput = $('input[name^="input_3"]');
                            quantityInput.val(data.quantity);
                            
                        }
                        
                        // Price field - removed (populated automatically)
                        
                        // Add-ons field (if exists)
                        if (data.addons && data.addons.length > 0) {
                            var addonsText = data.addons.map(function(addon) {
                                return addon.name + (addon.price ? ' (R' + addon.price + ')' : '');
                            }).join(', ');
                            $('textarea[name="input_9"]').val(addonsText);
                        }
                        
                        // Clear the stored data after use
                        localStorage.removeItem('timberfans_quote_data');
                    }
                    
                    // Show overlay while we work (and swap in site loader if available)
                    var $overlay = $('#oa-tfp-populating-overlay');
                    var $content = $('#oa-tfp-populating-content');
                    var $siteLoader = $('#oa-site-loader').first();
                    if ($siteLoader.length) {
                        try {
                            var $clone = $siteLoader.clone();
                            $clone.attr('id','');
                            $clone.css({display:'block', margin:'0 auto'});
                            $content.empty().append($clone);
                        } catch(e) { /* keep default spinner */ }
                    }
                    $overlay.show();

                    // Try immediately
                    attemptFormPopulation();

                    // Ensure Gravity Forms popup styles are applied when modal opens
                    function ensurePopupStyles() {
                        var $modal = $('.gpnf-modal, .gpnf-dialog');
                        if ($modal.length) {
                            // Force styles to be applied by adding a class or inline styles
                            $modal.addClass('oa-tfp-styled-popup');
                            
                            // Ensure the stylesheet is loaded
                            if (!$('link[href*="oa-tfp-styles.css"]').length) {
                                $('head').append('<link rel="stylesheet" href="<?php echo esc_url(OA_TFP_PLUGIN_URL . "assets/oa-tfp-styles.css?v=" . OA_TFP_PLUGIN_VERSION); ?>" type="text/css" media="all">');
                            }
                        }
                    }
                    
                    // Watch for modal opening
                    var modalObserver = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                            if (mutation.addedNodes.length) {
                                mutation.addedNodes.forEach(function(node) {
                                    if (node.nodeType === 1) {
                                        var $node = $(node);
                                        if ($node.hasClass('gpnf-modal') || $node.hasClass('gpnf-dialog') || $node.find('.gpnf-modal, .gpnf-dialog').length) {
                                            setTimeout(ensurePopupStyles, 100);
                                        }
                                    }
                                });
                            }
                        });
                    });
                    
                    // Start observing the document body for modal additions
                    modalObserver.observe(document.body, {
                        childList: true,
                        subtree: true
                    });
                    
                    // Also check periodically for modals
                    setInterval(function() {
                        if ($('.gpnf-modal, .gpnf-dialog').length) {
                            ensurePopupStyles();
                        }
                    }, 500);
                    
                    // Auto-open and auto-add Nested Form entry if present (no manual Add Entry click required)
                    (function autoAddNestedEntry(){
                        // Capture current count of nested entries so we know when a new one is attached
                        var initialEntryCount = $('.gpnf-nested-entries .gpnf-row, .gpnf-nested-entries tbody tr').length;
                        var attemptsForButton = 0, maxButtonAttempts = 25;
                        var buttonFinder = setInterval(function(){
                            attemptsForButton++;
                            var addBtn = $(document).find('.gpnf-add-entry, button.gpnf-add-entry, [data-action="gpnf-add"], .gpnf-add-entry-wrap button, .gfield.gpnf .gpnf-add-entry');
                            if (addBtn.length) {
                                console.log('Auto-opening nested form entry…');
                                addBtn.first().trigger('click');
                                clearInterval(buttonFinder);
                                // After opening, poll for modal and populate
                                var tries = 0, max = 20;
                                var timer = setInterval(function(){
                                    tries++;
                                    var modal = $('.gpnf-modal, .gpnf-dialog');
                                    if (!modal.length) return;
                                    
                                    // Ensure styles are applied when modal is found
                                    ensurePopupStyles();
                                    
                                    var hasFields = modal.find('input[name^="input_"], select[name^="input_"], textarea[name^="input_"]').length > 0;
                                    if (!hasFields) return;
                            // Populate inside modal
                            if (data.fan_range) {
                                var fr = modal.find('input[name="input_1"][value="' + data.fan_range + '"]');
                                if (!fr.length) fr = modal.find('input[name^="input_1"][value="' + data.fan_range + '"]');
                                if (fr.length) fr.prop('checked', true).trigger('change');
                            }
                            if (data.fan_size) {
                                var fs = modal.find('input[name^="input_4"][value="' + data.fan_size + '"]');
                                if (fs.length) fs.prop('checked', true).trigger('change');
                            }
                            var timberCandidates = [];
                            if (data.timber_finish) timberCandidates.push(data.timber_finish);
                            if (data['pa_timber-finish']) timberCandidates.push(data['pa_timber-finish']);
                            if (data.timber_finish) timberCandidates.push(String(data.timber_finish).toLowerCase().replace(/\s+/g,'-'));
                            for (var i=0;i<timberCandidates.length;i++){ var c=timberCandidates[i]; if(!c) continue; var tf = modal.find('input[name^="input_5"][value="'+c+'"]'); if(tf.length){ tf.prop('checked', true).trigger('change'); break; } }
                            var metalCandidates = [];
                            if (data.metal_finish) metalCandidates.push(data.metal_finish);
                            if (data['pa_metal-finish']) metalCandidates.push(data['pa_metal-finish']);
                            if (data.metal_finish) metalCandidates.push(String(data.metal_finish).toLowerCase().replace(/\s+/g,'-'));
                            for (var j=0;j<metalCandidates.length;j++){ var mc=metalCandidates[j]; if(!mc) continue; var mf = modal.find('input[name^="input_6"][value="'+mc+'"]'); if(mf.length){ mf.prop('checked', true).trigger('change'); break; } }
                            var sp = modal.find('input[name^="input_7"]:first'); if (sp.length) sp.prop('checked', true);
                            var q = modal.find('input[name^="input_3"]'); if (q.length && data.quantity) q.val(data.quantity).trigger('input');
                            var submit = modal.find('.gform_footer .gform_button, .gpnf-modal .gform_button');
                            if (submit.length){
                                console.log('Submitting nested entry automatically…');
                                submit.first()[0].click();
                                clearInterval(timer);
                                // After submit, poll for: modal closed AND entry count increased
                                var confirmTries = 0, confirmMax = 25;
                                var confirmInterval = setInterval(function(){
                                    confirmTries++;
                                    var modalGone = ($('.gpnf-modal, .gpnf-dialog').length === 0);
                                    var currentCount = $('.gpnf-nested-entries .gpnf-row, .gpnf-nested-entries tbody tr').length;
                                    var added = currentCount > initialEntryCount;
                                    if (modalGone && added) {
                                        $('#oa-tfp-populating-overlay').fadeOut(180);
                                        clearInterval(confirmInterval);
                                    }
                                    if (confirmTries >= confirmMax) {
                                        // Safety hide to prevent overlay sticking
                                        $('#oa-tfp-populating-overlay').fadeOut(240);
                                        clearInterval(confirmInterval);
                                    }
                                }, 250);
                            }
                                    if (tries>=max) clearInterval(timer);
                                }, 400);
                            }
                            if (attemptsForButton >= maxButtonAttempts) {
                                clearInterval(buttonFinder);
                            }
                        }, 300);
                    })();
                    
                    // Retry after 3 seconds if form wasn't ready (overlay stays until auto-submit)
                    setTimeout(function() {
                        if ($('input[name^="input_"]').length === 0) {
                            console.log('Form not ready, retrying...');
                            attemptFormPopulation();
                        }
                    }, 3000);
                    
                } catch (e) {
                    console.log('Error parsing quote data:', e);
                }
            }
        });
        </script>
        <?php
    }
    

    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'OA TFP Product Mods Settings',
            'OA TFP Product Mods',
            'manage_options',
            'oa-tfp-product-mods-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('oa_tfp_settings', 'oa_tfp_faqs_page');
        register_setting('oa_tfp_settings', 'oa_tfp_spares_page');
        register_setting('oa_tfp_settings', 'oa_tfp_custom_design_page');
        register_setting('oa_tfp_settings', 'oa_tfp_quote_page');
        register_setting('oa_tfp_settings', 'oa_tfp_fan_size_guide_url');
        register_setting('oa_tfp_settings', 'oa_tfp_down_rod_guide_url');
        register_setting('oa_tfp_settings', 'oa_tfp_debug_mode');
    }
    

    

    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>OA Timberfans Product Mods Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('oa_tfp_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">FAQs Page</th>
                        <td>
                            <?php
                            wp_dropdown_pages(array(
                                'name' => 'oa_tfp_faqs_page',
                                'selected' => get_option('oa_tfp_faqs_page'),
                                'show_option_none' => '-- Select a page --',
                                'option_none_value' => '',
                                'sort_column' => 'post_title',
                                'echo' => 1,
                                'post_status' => array('publish'),
                                'id' => 'oa_tfp_faqs_page',
                            ));
                            ?>
                            <p class="description">Select the page to use for the FAQs link.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Spares Page</th>
                        <td>
                            <?php
                            wp_dropdown_pages(array(
                                'name' => 'oa_tfp_spares_page',
                                'selected' => get_option('oa_tfp_spares_page'),
                                'show_option_none' => '-- Select a page --',
                                'option_none_value' => '',
                                'sort_column' => 'post_title',
                                'echo' => 1,
                                'post_status' => array('publish'),
                                'id' => 'oa_tfp_spares_page',
                            ));
                            ?>
                            <p class="description">Select the page to use for the Spares link.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Custom Design Page</th>
                        <td>
                            <?php
                            wp_dropdown_pages(array(
                                'name' => 'oa_tfp_custom_design_page',
                                'selected' => get_option('oa_tfp_custom_design_page'),
                                'show_option_none' => '-- Select a page --',
                                'option_none_value' => '',
                                'sort_column' => 'post_title',
                                'echo' => 1,
                                'post_status' => array('publish'),
                                'id' => 'oa_tfp_custom_design_page',
                            ));
                            ?>
                            <p class="description">Select the page to use for the Custom Design link.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Quote Page</th>
                        <td>
                            <?php
                            wp_dropdown_pages(array(
                                'name' => 'oa_tfp_quote_page',
                                'selected' => get_option('oa_tfp_quote_page'),
                                'show_option_none' => '-- Select a page --',
                                'option_none_value' => '',
                                'sort_column' => 'post_title',
                                'echo' => 1,
                                'post_status' => array('publish'),
                                'id' => 'oa_tfp_quote_page',
                            ));
                            ?>
                            <p class="description">Select the page to use for the quote button when products are out of stock or unavailable.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Fan Size Guide URL</th>
                        <td>
                            <input type="url" name="oa_tfp_fan_size_guide_url" value="<?php echo esc_attr(get_option('oa_tfp_fan_size_guide_url')); ?>" class="regular-text" />
                            <p class="description">Enter the URL for the Fan Size Guide button (e.g., https://example.com/fan-size-guide).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Down-Rod Guide URL</th>
                        <td>
                            <input type="url" name="oa_tfp_down_rod_guide_url" value="<?php echo esc_attr(get_option('oa_tfp_down_rod_guide_url')); ?>" class="regular-text" />
                            <p class="description">Enter the URL for the Down-Rod Guide button (e.g., https://example.com/down-rod-guide).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="oa_tfp_debug_mode" value="1" <?php checked(get_option('oa_tfp_debug_mode'), 1); ?> />
                                Enable debug panel on quote page
                            </label>
                            <p class="description">Shows a debug panel on the quote page with real-time information about form population. <strong>Turn off when not needed.</strong></p>
                        </td>
                    </tr>
                </table>

                
                <h2>Available Shortcodes</h2>
                <ul>
                    <li><code>[oa_tfp_product_banner]</code> - Displays the product banner section.</li>
                    <li><code>[oa_tfp_product_gallery]</code> - Displays the product gallery.</li>
                    <li><code>[oa_tfp_product_details]</code> - Displays the Timberfans product details section (accordion/buttons).</li>
                    <li><code>[oa_tfp_timber_options_catalog]</code> - Displays the timber options catalog.</li>
                    <li><code>[oa_tfp_metal_finishes_catalog]</code> - Displays the metal finishes catalog.</li>
                    <li><code>[oa_tfp_quote_debug]</code> - Displays debug information for the quote system (helpful for testing).</li>
                </ul>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
?>
