<?php
/**
 * OA TFP Shortcodes Class
 * 
 * Handles all shortcode functionality
 */
class OA_TFP_Shortcodes {
    
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
        add_shortcode('oa_tfp_product_banner', array($this, 'product_banner_shortcode'));
        add_shortcode('oa_tfp_product_gallery', array($this, 'product_gallery_shortcode'));
        add_shortcode('oa_tfp_product_details', array($this, 'product_details_shortcode'));
        add_shortcode('oa_tfp_timber_options_catalog', array($this, 'timber_options_catalog_shortcode'));
        add_shortcode('oa_tfp_metal_finishes_catalog', array($this, 'metal_finishes_catalog_shortcode'));
        add_shortcode('oa_tfp_quote_debug', array($this, 'quote_debug_shortcode'));
    }
    
    /**
     * Product Banner Shortcode
     */
    public function product_banner_shortcode() {
        if (!is_singular('product')) {
            return '';
        }
        
        global $post;
        $banner_id = get_post_meta($post->ID, 'product_banner', true);
        $banner_url = $banner_id ? wp_get_attachment_url($banner_id) : '';
        $banner_height = get_post_meta($post->ID, 'product_banner_height', true);
        $custom_title = get_post_meta($post->ID, 'product_new_title', true);
        $title = $custom_title ? $custom_title : get_the_title($post->ID);
        $short_description = apply_filters('woocommerce_short_description', $post->post_excerpt);
        
        $classes = array('oa-tfp-product-banner');
        if ($custom_title) $classes[] = 'has-new-title';
        if ($short_description) $classes[] = 'has-subtitle';
        if ($banner_url) $classes[] = 'has-banner';
        $class_attr = implode(' ', $classes);
        
        $output = '<div class="' . $class_attr . '">';
        
        if ($banner_url) {
            $height = $banner_height ? $banner_height : '500px';
            if (strpos($height, '%') !== false) {
                $height = rtrim($height, '%') . 'vh';
            }
            $output .= '<div class="oa-tfp-product-banner-img" style="background-image:url(' . esc_url($banner_url) . ');height:' . esc_attr($height) . ';background-size:cover;background-position:bottom center;width:100%;"></div>';
        }
        
        $output .= '<div class="grid-container">';
        $output .= '<div class="site-content">';
        $output .= '<h1 class="oa-tfp-product-banner-title product_title entry-title">' . $title . '</h1>';
        
        if ($short_description) {
            $output .= '<div class="oa-tfp-product-banner-desc">' . $short_description . '</div>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Product Gallery Shortcode
     * Displays only the secondary/custom gallery images
     */
    public function product_gallery_shortcode() {
        if (!is_singular('product')) {
            return '';
        }
        
        global $post;
        
        // Get secondary gallery images only
        $secondary_gallery = get_post_meta($post->ID, 'oa_tfp_secondary_gallery', true);
        $secondary_gallery_ids = array();
        if ($secondary_gallery) {
            $secondary_gallery_ids = array_map('intval', explode(',', $secondary_gallery));
            $secondary_gallery_ids = array_filter($secondary_gallery_ids); // Remove empty values
        }
        
        if (empty($secondary_gallery_ids)) {
            return '';
        }
        
        // Build items first so scroll/arrows use valid rendered images only
        // (meta can contain stale IDs that never output a tile).
        $gallery_items_html = '';
        $total_images = 0;
        foreach ($secondary_gallery_ids as $image_id) {
            if (!wp_attachment_is_image($image_id)) {
                continue;
            }
            
            $img = wp_get_attachment_image($image_id, 'large');
            if ($img) {
                $gallery_items_html .= '<div class="oa-tfp-product-gallery-item">' . $img . '</div>';
                $total_images++;
            }
        }
        
        if ($total_images === 0) {
            return '';
        }
        
        $has_scroll = $total_images > 3;
        
        $output = '<div class="oa-tfp-product-gallery-wrapper' . ($has_scroll ? ' oa-tfp-gallery-scrollable' : '') . '">';
        
        if ($has_scroll) {
            $output .= '<button class="oa-tfp-gallery-nav oa-tfp-gallery-prev" type="button" aria-label="Previous images">';
            $output .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
            $output .= '<path d="M15 18l-6-6 6-6"/>';
            $output .= '</svg></button>';
        }

        // Viewport clips; the inner track is what we translate.
        if ($has_scroll) {
            $output .= '<div class="oa-tfp-gallery-viewport">';
        }
        
        $output .= '<div class="oa-tfp-product-gallery' . ($has_scroll ? ' oa-tfp-gallery-scroller' : '') . '">';
        $output .= $gallery_items_html;
        $output .= '</div>';

        if ($has_scroll) {
            $output .= '</div>'; // .oa-tfp-gallery-viewport
        }
        
        if ($has_scroll) {
            $output .= '<button class="oa-tfp-gallery-nav oa-tfp-gallery-next" type="button" aria-label="Next images">';
            $output .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
            $output .= '<path d="M9 18l6-6-6-6"/>';
            $output .= '</svg></button>';
        }
        
        $output .= '</div>';
        
        // Add JavaScript for scrolling if more than 3 images
        if ($has_scroll) {
            $output .= '<script>
            (function() {
                var gallery = document.querySelector(".oa-tfp-product-gallery.oa-tfp-gallery-scroller");
                var viewport = document.querySelector(".oa-tfp-gallery-viewport");
                var prevBtn = document.querySelector(".oa-tfp-gallery-prev");
                var nextBtn = document.querySelector(".oa-tfp-gallery-next");
                if (!gallery || !prevBtn || !nextBtn) return;
                
                var items = gallery.querySelectorAll(".oa-tfp-product-gallery-item");
                var gap = 20; // CSS gap value
                var currentIndex = 0;
                var visibleCount = 3;
                var maxIndex = Math.max(0, items.length - visibleCount);
                
                function getItemWidth() {
                    if (items.length === 0) return 0;
                    // Measure the visible viewport, not the transformed track.
                    var widthSource = viewport || gallery.parentElement || gallery;
                    var galleryWidth = widthSource.clientWidth;
                    return (galleryWidth - (gap * (visibleCount - 1))) / visibleCount;
                }
                
                function getScrollDistance() {
                    return getItemWidth() + gap;
                }

                function applyTransform() {
                    var scrollDistance = currentIndex * getScrollDistance();
                    gallery.style.transform = "translateX(-" + scrollDistance + "px)";
                }
                
                function updateButtons() {
                    prevBtn.style.opacity = currentIndex > 0 ? "1" : "0.3";
                    prevBtn.style.pointerEvents = currentIndex > 0 ? "auto" : "none";
                    nextBtn.style.opacity = currentIndex < maxIndex ? "1" : "0.3";
                    nextBtn.style.pointerEvents = currentIndex < maxIndex ? "auto" : "none";
                }
                
                function scrollGallery(direction) {
                    if (direction === "prev" && currentIndex > 0) {
                        currentIndex--;
                    } else if (direction === "next" && currentIndex < maxIndex) {
                        currentIndex++;
                    }
                    applyTransform();
                    updateButtons();
                }
                
                prevBtn.addEventListener("click", function() { scrollGallery("prev"); });
                nextBtn.addEventListener("click", function() { scrollGallery("next"); });
                
                // Handle window resize
                var resizeTimer;
                window.addEventListener("resize", function() {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(function() {
                        maxIndex = Math.max(0, items.length - visibleCount);
                        currentIndex = Math.min(currentIndex, maxIndex);
                        applyTransform();
                        updateButtons();
                    }, 250);
                });
                
                // Start at the first slide (clears any stale inline transform).
                currentIndex = 0;
                applyTransform();
                updateButtons();
            })();
            </script>';
        }
        
        return $output;
    }
    
    /**
     * Product Details Shortcode
     */
    public function product_details_shortcode() {
        ob_start();
        $this->render_product_details_section();
        return ob_get_clean();
    }
    
    /**
     * Timber Options Catalog Shortcode
     */
    public function timber_options_catalog_shortcode() {
        $taxonomy = 'pa_timber-finish';
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'orderby'    => 'menu_order',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($terms) || empty($terms)) {
            return '<p>No timber options found.</p>';
        }
        
        $output = '<div class="oa-tfp-variation-group oa-tfp-timber-finish-group" data-attribute_name="' . esc_attr($taxonomy) . '">';
        $output .= '<div class="oa-tfp-options-grid oa-tfp-timber-options-grid">';
        
        foreach ($terms as $term) {
            $image_id = get_term_meta($term->term_id, 'tfp_term_image', true);
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
            
            $output .= '<div class="oa-tfp-item oa-tfp-timber-item oa-tfp-' . esc_attr($term->slug) . '">';
            $style = $image_url ? ' style="background-image:url(' . esc_url($image_url) . ');"' : '';
            $placeholder_class = $image_url ? '' : ' oa-tfp-timber-img-placeholder';
            $output .= '<div class="oa-tfp-option oa-tfp-timber-option' . $placeholder_class . '"' . $style . '"></div>';
            $output .= '<div class="oa-tfp-timber-label-text">' . esc_html($term->name) . '</div>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Metal Finishes Catalog Shortcode
     */
    public function metal_finishes_catalog_shortcode() {
        $taxonomy = 'pa_metal-finish';
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'orderby'    => 'menu_order',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($terms) || empty($terms)) {
            return '<p>No metal finishes found.</p>';
        }
        
        $output = '<div class="oa-tfp-variation-group oa-tfp-metal-finish-group" data-attribute_name="' . esc_attr($taxonomy) . '">';
        $output .= '<div class="oa-tfp-options-grid oa-tfp-metal-options-grid">';
        
        foreach ($terms as $term) {
            $image_id = get_term_meta($term->term_id, 'tfp_term_image', true);
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
            
            $output .= '<div class="oa-tfp-item oa-tfp-metal-item oa-tfp-' . esc_attr($term->slug) . '">';
            $style = $image_url ? ' style="background-image:url(' . esc_url($image_url) . ');"' : '';
            $placeholder_class = $image_url ? '' : ' oa-tfp-metal-img-placeholder';
            $output .= '<div class="oa-tfp-option oa-tfp-metal-option' . $placeholder_class . '"' . $style . '"></div>';
            $output .= '<div class="oa-tfp-metal-label-text">' . esc_html($term->name) . '</div>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render product details section
     */
    public function render_product_details_section() {
        if (!is_singular('product')) return;
        
        global $post, $product;
        $brochure_id = get_post_meta($post->ID, 'product_brochure_pdf', true);
        $brochure_url = $brochure_id ? wp_get_attachment_url($brochure_id) : '';
        $faqs_url = '';
        $faqs_page_id = get_option('oa_tfp_faqs_page');
        if ($faqs_page_id) {
            $faqs_url = get_permalink($faqs_page_id);
        }
        $spares_url = '';
        $spares_page_id = get_option('oa_tfp_spares_page');
        if ($spares_page_id) {
            $spares_url = get_permalink($spares_page_id);
        }
        $custom_design_url = '';
        $custom_design_page_id = get_option('oa_tfp_custom_design_page');
        if ($custom_design_page_id) {
            $custom_design_url = get_permalink($custom_design_page_id);
        }
        
        // Always use the full description (post content) for the accordion description
        $accordion_desc = apply_filters('the_content', $post->post_content);
        $fan_size_guide_page_id = get_option('oa_tfp_fan_size_guide_page');
        $down_rod_guide_page_id = get_option('oa_tfp_down_rod_guide_page');
        $fan_size_guide = '';
        $down_rod_guide = '';
        // Product Features will use the product description (post content)
        $product_features = apply_filters('the_content', $post->post_content);
        
        if ($fan_size_guide_page_id) {
            $fan_page = get_post($fan_size_guide_page_id);
            if ($fan_page && $fan_page->post_status === 'publish') {
                $fan_size_guide = apply_filters('the_content', $fan_page->post_content);
            }
        }
        if ($down_rod_guide_page_id) {
            $rod_page = get_post($down_rod_guide_page_id);
            if ($rod_page && $rod_page->post_status === 'publish') {
                $down_rod_guide = apply_filters('the_content', $rod_page->post_content);
            }
        }
        
        ob_start();
        wc_display_product_attributes($product);
        $product_details_table = ob_get_clean();
        
        // Timber Options catalog grid (all terms, no click)
        $timber_options_catalog = $this->get_timber_options_catalog();
        
        // Metal Finishes catalog grid (all terms, no click)
        $metal_finishes_catalog = $this->get_metal_finishes_catalog();
        
        // Quote button is now handled by the woocommerce_single_product_summary hook
        
        ?>
        <div class="oa-tfp-product-details-section">
            <div class="oa-tfp-product-details-buttons" style="display:flex;flex-wrap: wrap;gap:1em;margin-bottom:1em;">
                <?php if ($brochure_url): ?>
                    <a href="<?php echo esc_url($brochure_url); ?>" class="button oa-button oa-button-outline" target="_blank">
                        Brochure
                        <span class="oa-tfp-brochure-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 8.81 11.77" aria-hidden="true" focusable="false"><polygon fill="currentColor" points="7.95 6.51 5.01 9.45 5.01 0 3.8 0 3.8 9.45 .85 6.51 0 7.36 4.4 11.77 8.81 7.36 7.95 6.51"/></svg>
                        </span>
                    </a>
                <?php endif; ?>
                
                <?php if ($faqs_url): ?>
                    <a href="<?php echo esc_url($faqs_url); ?>" class="button oa-button oa-button-outline" target="_self">
                        FAQ's
                        <span class="oa-tfp-faq-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 19.08 19.08" aria-hidden="true" focusable="false"><path fill="currentColor" d="M19.08,9.54c0,5.27-4.27,9.54-9.54,9.54S0,14.81,0,9.54,4.27,0,9.54,0s9.54,4.27,9.54,9.54ZM17.7,9.54c0-4.5-3.65-8.15-8.15-8.15S1.39,5.04,1.39,9.54s3.65,8.15,8.15,8.15,8.15-3.65,8.15-8.15ZM10.05,10.07c.15-.72,1.02-1.13,1.47-1.7,2.38-2.97-2.58-4.88-4.26-2.77-.34.43-.57,1.3.11,1.52.89.29.81-.58,1.44-.82.52-.2,1.58-.09,1.67.59.12.9-1.04,1.34-1.5,1.96-.37.49-.52,1.3-.42,1.9s1.11.59,1.37.26c.1-.13.08-.72.13-.94ZM9.26,12.04c-.54,0-.99.44-.99.99s.44.99.99.99.99-.44.99-.99-.44-.99-.99-.99Z"/></svg>
                        </span>
                    </a>
                <?php endif; ?>
                
                <?php if ($spares_url): ?>
                    <a href="<?php echo esc_url($spares_url); ?>" class="button oa-button oa-button-outline" target="_self">
                        Buy Spares
                        <span class="oa-tfp-spares-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 7.37 19.21" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7.37,19.21H0V0h7.37v19.21ZM1,18.21h5.37V1H1v17.21ZM3.72,6.89c-1.11,0-2.01-.9-2.01-2.02s.9-2.01,2.01-2.01,2.01.9,2.01,2.01-.9,2.02-2.01,2.02ZM3.72,3.86c-.56,0-1.01.46-1.01,1.01s.46,1.02,1.01,1.02,1.01-.46,1.01-1.02-.46-1.01-1.01-1.01Z"/></svg>
                        </span>
                    </a>
                <?php endif; ?>
                
                <?php if ($custom_design_url): ?>
                    <a href="<?php echo esc_url($custom_design_url); ?>" class="button oa-button oa-button-outline" target="_self">
                        Custom Design
                        <span class="oa-tfp-custom-design-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4M12,6A6,6 0 0,0 6,12A6,6 0 0,0 12,18A6,6 0 0,0 18,12A6,6 0 0,0 12,6M12,8A4,4 0 0,1 16,12A4,4 0 0,1 12,16A4,4 0 0,1 8,12A4,4 0 0,1 12,8Z"/></svg>
                        </span>
                    </a>
                <?php endif; ?>
                
                <?php 
                $fan_size_guide_url = get_option('oa_tfp_fan_size_guide_url');
                if ($fan_size_guide_url): ?>
                    <a href="<?php echo esc_url($fan_size_guide_url); ?>" class="button oa-button oa-button-outline" target="_blank" rel="noopener noreferrer">
                        Fan Size Guide
                        <span class="oa-tfp-fan-size-guide-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1.2em" height="1.2em" viewBox="0 0 50 55" aria-hidden="true" focusable="false"><path fill="currentColor" d="M25.44,29.31c.16-.49.02-.73-.4-.96-1.38-.74-2.09-2.21-1.88-3.79.05-.36.08-.63-.46-.67-.23.41-.47.86-.72,1.3-.35.61-.71.74-1.33.43-4.85-2.38-9.7-4.77-14.54-7.17-.8-.4-1.11-1.06-.96-1.95.21-1.28.34-2.61,1.28-3.63.51-.56,1.06-1.1,1.65-1.58.63-.51,1.32-.45,2.01-.05,3.01,1.73,6.03,3.46,9.04,5.19,1.58.9,3.15,1.81,4.72,2.71.87.51.95.78.48,1.67-.14.28-.29.55-.42.82.39.49.76.42,1.23.16,1.17-.64,2.4-.65,3.53.05.58.36.96.3,1.46-.17-.21-.36-.44-.73-.65-1.11-.34-.6-.26-1,.32-1.38,4.09-2.64,8.19-5.27,12.28-7.9.43-.28.86-.57,1.3-.84.76-.47,1.47-.41,2.19.11,1.71,1.24,2.75,2.85,2.99,4.97.1.9-.13,1.52-.93,1.97-4.7,2.63-9.4,5.25-14.1,7.87-.64.36-1.02.25-1.41-.38-.24-.39-.47-.8-.69-1.18-.69.17-.88.5-.82,1.17.13,1.54-.79,2.9-2.27,3.56q-.44.2-.35.77c.46,0,.93,0,1.4,0,1,0,1.22.2,1.26,1.22.18,4.38.35,8.75.53,13.13.04.9.08,1.79.11,2.69.03,1.01-.39,1.55-1.33,1.94-1.8.76-3.58.87-5.42.1-1.11-.46-1.51-.91-1.48-2.1.12-5.23.24-10.46.36-15.69.02-1.07.2-1.23,1.28-1.25.25,0,.49-.02.77-.02Z"/></svg>
                        </span>
                    </a>
                <?php endif; ?>
                
                <?php 
                $down_rod_guide_url = get_option('oa_tfp_down_rod_guide_url');
                if ($down_rod_guide_url): ?>
                    <a href="<?php echo esc_url($down_rod_guide_url); ?>" class="button oa-button oa-button-outline" target="_blank" rel="noopener noreferrer">
                        Down-Rod Guide
                        <span class="oa-tfp-down-rod-guide-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1.4em" height="1.4em" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <rect fill="currentColor" x="6" y="4" width="2" height="8" rx="1"/>
                                <rect fill="currentColor" x="11" y="4" width="2" height="14" rx="1"/>
                                <rect fill="currentColor" x="16" y="4" width="2" height="16" rx="1"/>
                            </svg>
                        </span>
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="oa-tfp-product-details-content">
                <?php if (trim(strip_tags($product_features)) !== ''): ?>
                <div class="oa-tfp-product-features" style="border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 20px 0; margin: 20px 0;">
                    <h3>Product Features</h3>
                    <div class="oa-tfp-product-features-content"><?php echo $product_features; ?></div>
                </div>
                <?php endif; ?>
                
                <?php 
                // Dimensions & Other Information section is hidden
                // if (trim(strip_tags($product_details_table)) !== ''): ?>
                <!-- <div class="oa-tfp-accordion-item" id="oa-tfp-accordion-dimensions">
                    <button class="oa-tfp-accordion-toggle">Dimensions & Other Information</button>
                    <div class="oa-tfp-accordion-panel"><?php echo $product_details_table; ?></div>
                </div> -->
                <?php // endif; ?>
                
                <?php 
                // Fan Size Guide section is hidden
                // if (trim(strip_tags($fan_size_guide)) !== ''): ?>
                <!-- <div class="oa-tfp-accordion-item" id="oa-tfp-accordion-fan-size-guide">
                    <button class="oa-tfp-accordion-toggle">Fan Size Guide</button>
                    <div class="oa-tfp-accordion-panel"><?php echo $fan_size_guide; ?></div>
                </div> -->
                <?php // endif; ?>
                
                <?php 
                // Down Rod Length Guide section is hidden
                // if (trim(strip_tags($down_rod_guide)) !== ''): ?>
                <!-- <div class="oa-tfp-accordion-item" id="oa-tfp-accordion-down-rod-guide">
                    <button class="oa-tfp-accordion-toggle">Down Rod Length Guide</button>
                    <div class="oa-tfp-accordion-panel"><?php echo $down_rod_guide; ?></div>
                </div> -->
                <?php // endif; ?>
            </div>
        </div>
        <!-- Product Diagram Display -->
        <?php
        $diagram_id = get_post_meta($post->ID, 'product_diagram', true);
        $diagram_url = $diagram_id ? wp_get_attachment_url($diagram_id) : '';
        if ($diagram_url) {
            echo '<div class="oa-tfp-product-diagram" style="margin-top: 40px; text-align: center;"><img src="' . esc_url($diagram_url) . '" alt="Product Diagram" style="max-width:100%;height:auto;" /></div>';
        }
        ?>
        
        <!-- Quote button is now handled by the woocommerce_single_product_summary hook -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var toggles = document.querySelectorAll('.oa-tfp-accordion-toggle');
            toggles.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (btn.hasAttribute('disabled')) return;
                    var panel = btn.nextElementSibling;
                    var open = btn.classList.contains('active');
                    document.querySelectorAll('.oa-tfp-accordion-toggle.active').forEach(function(b) {
                        b.classList.remove('active');
                        if (b.nextElementSibling) b.nextElementSibling.style.display = 'none';
                    });
                    if (!open) {
                        btn.classList.add('active');
                        panel.style.display = 'block';
                    } else {
                        btn.classList.remove('active');
                        panel.style.display = 'none';
                    }
                });
            });
            // Close all accordions except the one with 'active' class
            document.querySelectorAll('.oa-tfp-accordion-panel').forEach(function(panel) {
                var toggle = panel.previousElementSibling;
                if (!toggle.classList.contains('active')) {
                    panel.style.display = 'none';
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get timber options catalog HTML
     */
    private function get_timber_options_catalog() {
        $taxonomy = 'pa_timber-finish';
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'orderby'    => 'menu_order',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($terms) || empty($terms)) {
            return '<p>No timber options found.</p>';
        }
        
        $output = '<div class="oa-tfp-variation-group oa-tfp-timber-finish-group" data-attribute_name="' . esc_attr($taxonomy) . '">';
        $output .= '<div class="oa-tfp-options-grid oa-tfp-timber-options-grid">';
        
        foreach ($terms as $term) {
            $image_id = get_term_meta($term->term_id, 'tfp_term_image', true);
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
            
            $output .= '<div class="oa-tfp-item oa-tfp-timber-item oa-tfp-' . esc_attr($term->slug) . '">';
            $style = $image_url ? ' style="background-image:url(' . esc_url($image_url) . ');"' : '';
            $placeholder_class = $image_url ? '' : ' oa-tfp-timber-img-placeholder';
            $output .= '<div class="oa-tfp-option oa-tfp-timber-option' . $placeholder_class . '"' . $style . '"></div>';
            $output .= '<div class="oa-tfp-timber-label-text">' . esc_html($term->name) . '</div>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Get metal finishes catalog HTML
     */
    private function get_metal_finishes_catalog() {
        $taxonomy = 'pa_metal-finish';
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'orderby'    => 'menu_order',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($terms) || empty($terms)) {
            return '<p>No metal finishes found.</p>';
        }
        
        $output = '<div class="oa-tfp-variation-group oa-tfp-metal-finish-group" data-attribute_name="' . esc_attr($taxonomy) . '">';
        $output .= '<div class="oa-tfp-options-grid oa-tfp-metal-options-grid">';
        
        foreach ($terms as $term) {
            $image_id = get_term_meta($term->term_id, 'tfp_term_image', true);
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
            
            $output .= '<div class="oa-tfp-item oa-tfp-metal-item oa-tfp-' . esc_attr($term->slug) . '">';
            $style = $image_url ? ' style="background-image:url(' . esc_url($image_url) . ');"' : '';
            $placeholder_class = $image_url ? '' : ' oa-tfp-metal-img-placeholder';
            $output .= '<div class="oa-tfp-option oa-tfp-metal-option' . $placeholder_class . '"' . $style . '"></div>';
            $output .= '<div class="oa-tfp-metal-label-text">' . esc_html($term->name) . '</div>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Quote Debug Shortcode
     * Shows current localStorage data for debugging quote form population
     */
    public function quote_debug_shortcode() {
        ob_start();
        ?>
        <div class="oa-tfp-quote-debug" style="background: #f0f0f1; padding: 20px; border-radius: 5px; margin: 20px 0;">
            <h3 style="margin-top: 0;">Quote System Debug Tool</h3>
            <div id="oa-tfp-debug-output" style="background: white; padding: 15px; border-radius: 3px; font-family: monospace; font-size: 13px;">
                Loading...
            </div>
            <button id="oa-tfp-debug-refresh" class="button" style="margin-top: 10px;">Refresh Data</button>
            <button id="oa-tfp-debug-clear" class="button" style="margin-top: 10px;">Clear localStorage</button>
        </div>
        <script>
        jQuery(function($) {
            function displayDebugData() {
                var output = '';
                var quoteData = localStorage.getItem('timberfans_quote_data');
                
                output += '<strong>localStorage Status:</strong><br>';
                output += '====================<br>';
                
                if (quoteData) {
                    output += 'Quote data found in localStorage<br><br>';
                    
                    try {
                        var data = JSON.parse(quoteData);
                        output += '<strong>Parsed Data:</strong><br>';
                        output += 'Product Name: ' + (data.product_name || 'N/A') + '<br>';
                        output += 'Fan Range: ' + (data.fan_range || 'N/A') + '<br>';
                        output += 'Fan Size: ' + (data.fan_size || 'N/A') + '<br>';
                        output += 'Timber Finish: ' + (data.timber_finish || 'N/A') + '<br>';
                        output += 'Metal Finish: ' + (data.metal_finish || 'N/A') + '<br>';
                        output += 'Quantity: ' + (data.quantity || 'N/A') + '<br>';
                        output += 'Price: ' + (data.price || 'N/A') + '<br>';
                        
                        if (data.addons && data.addons.length > 0) {
                            output += 'Add-ons: <br>';
                            data.addons.forEach(function(addon) {
                                output += '  - ' + addon.name + (addon.price ? ' (R' + addon.price + ')' : '') + '<br>';
                            });
                        } else {
                            output += 'Add-ons: None<br>';
                        }
                        
                        output += '<br><strong>Raw JSON:</strong><br>';
                        output += '<pre style="overflow-x: auto; background: #f5f5f5; padding: 10px; border-radius: 3px;">' + JSON.stringify(data, null, 2) + '</pre>';
                    } catch (e) {
                        output += '<span style="color: red;">Error parsing data: ' + e.message + '</span><br>';
                        output += '<strong>Raw data:</strong><br>' + quoteData;
                    }
                } else {
                    output += '<span style="color: orange;">No quote data found in localStorage</span><br><br>';
                    output += 'This is normal if:<br>';
                    output += '1. You haven\'t clicked the quote button yet<br>';
                    output += '2. The data has already been cleared after form population<br>';
                    output += '3. localStorage is not available in your browser<br>';
                }
                
                output += '<br><strong>Gravity Form Fields on This Page:</strong><br>';
                var formInputs = $('input[name^="input_"]').length;
                if (formInputs > 0) {
                    output += 'Found ' + formInputs + ' form inputs<br>';
                    output += 'Field Names: ';
                    var fieldNames = [];
                    $('input[name^="input_"]').each(function() {
                        var name = $(this).attr('name');
                        if (fieldNames.indexOf(name) === -1) {
                            fieldNames.push(name);
                        }
                    });
                    output += fieldNames.join(', ') + '<br>';
                } else {
                    output += '<span style="color: orange;">No Gravity Form fields found on this page</span><br>';
                }
                
                $('#oa-tfp-debug-output').html(output);
            }
            
            // Initial display
            displayDebugData();
            
            // Refresh button
            $('#oa-tfp-debug-refresh').on('click', function() {
                displayDebugData();
            });
            
            // Clear button
            $('#oa-tfp-debug-clear').on('click', function() {
                if (confirm('Are you sure you want to clear the quote data from localStorage?')) {
                    localStorage.removeItem('timberfans_quote_data');
                    displayDebugData();
                    alert('localStorage cleared!');
                }
            });
            
            // Auto-refresh every 5 seconds
            setInterval(displayDebugData, 5000);
        });
        </script>
        <?php
        return ob_get_clean();
    }
} 