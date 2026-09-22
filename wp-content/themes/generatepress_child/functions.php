<?php
/**
 * GeneratePress child theme functions and definitions.
 *
 * Add your custom PHP in this file.
 * Only edit this file if you have direct access to it on your server (to fix errors if they happen).
 */

 // Fix for missing oa_debug_class_loading function
function oa_debug_class_loading() {
    // Empty function to prevent fatal error
    return;
}

 // Replace WooCommerce default placeholder with a custom image in your theme
function custom_woocommerce_placeholder_img_src( $src ) {
    return get_stylesheet_directory_uri() . '/assets/images/product-placeholder.png';
}
add_filter( 'woocommerce_placeholder_img_src', 'custom_woocommerce_placeholder_img_src', 10 );

add_filter( 'woocommerce_placeholder_img', function ( $image_html ) {
    $image_url = get_stylesheet_directory_uri() . '/assets/images/product-placeholder.png';
    return '<img src="' . esc_url( $image_url ) . '" alt="Placeholder" class="woocommerce-placeholder wp-post-image" />';
});

/**
 * WP 7.1 prints core block CSS on demand. Classic themes then skip
 * columns/layout CSS, so Gutenberg columns stack on the frontend
 * while the editor still looks correct.
 */
add_filter( 'should_load_separate_core_block_assets', '__return_false' );
add_filter( 'should_load_block_assets_on_demand', '__return_false' );

add_action( 'wp_enqueue_scripts', 'gp_child_enqueue_core_block_library', 5 );
function gp_child_enqueue_core_block_library() {
	if ( is_admin() ) {
		return;
	}

	wp_enqueue_style( 'wp-block-library' );
	wp_enqueue_style( 'global-styles' );
}

// Enqueue Owl Carousel assets (only once)
function gp_enqueue_owl_carousel() {
    static $done = false;
    if ( $done ) return;
    wp_enqueue_script('gp-owl-carousel','https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js',['jquery'],'2.3.4',true);
    wp_enqueue_style('gp-owl-carousel-style','https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css',[],'2.3.4');
    wp_enqueue_style('gp-owl-carousel-theme','https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.theme.default.min.css',[],'2.3.4');
    $done = true;
}

// Currency widget
function highlight_current_currency_menu_item($classes, $item) {
    if (strpos($item->url, home_url()) !== false) {
        $classes[] = 'current-currency';
    }
    return $classes;
}
add_filter('nav_menu_css_class', 'highlight_current_currency_menu_item', 10, 2);

// Shortcode: [menu name="Footer Menu"]
function shortcode_menu( $atts ) {
    $atts = shortcode_atts( [
        'name' => '',
        'class' => 'shortcode-menu'
    ], $atts );

    return wp_nav_menu( [
        'menu' => $atts['name'],
        'container' => 'div',
        'container_class' => $atts['class'],
        'echo' => false
    ]);
}
add_shortcode( 'menu', 'shortcode_menu' );


// Output a simple currency switcher next to the Woo Mini Cart block
function gp_child_output_currency_switcher() {
    if ( is_admin() ) return;
    ?>
    <style>
    .gp-currency-switcher{display:inline-flex;align-items:center;margin-left:8px}
    .gp-currency-switcher select{font-size:12px;line-height:1.4;padding:2px 6px}
    </style>
    <script>
    (function(){
        function onReady(fn){if(document.readyState!=='loading'){fn()}else{document.addEventListener('DOMContentLoaded',fn)}}
        onReady(function(){
            var miniCart = document.querySelector('.wp-block-woocommerce-mini-cart, .wc-block-mini-cart');
            if(!miniCart || !miniCart.parentNode) return;
            // Avoid duplicates
            if(miniCart.nextElementSibling && miniCart.nextElementSibling.classList && miniCart.nextElementSibling.classList.contains('gp-currency-switcher')) return;

            var wrapper = document.createElement('div');
            wrapper.className = 'gp-currency-switcher';

            var select = document.createElement('select');
            select.setAttribute('aria-label','Currency');
            var optZAR = document.createElement('option'); optZAR.value = 'ZAR'; optZAR.textContent = 'ZAR';
            var optUSD = document.createElement('option'); optUSD.value = 'USD'; optUSD.textContent = 'USD';
            select.appendChild(optZAR); select.appendChild(optUSD);

            var path = window.location.pathname || '/';
            var inUSD = /^\/usd(\/|$)/i.test(path);
            select.value = inUSD ? 'USD' : 'ZAR';

            select.addEventListener('change', function(){
                var loc = new URL(window.location.href);
                var p = loc.pathname || '/';
                if(this.value === 'USD'){
                    if(!/^\/usd(\/|$)/i.test(p)){
                        // Ensure single slash when prefixing
                        var newPath = '/usd' + (p.charAt(0)==='/' ? '' : '/') + p;
                        loc.pathname = newPath.replace(/\/+/, '/');
                    }
                } else {
                    // Remove leading /usd segment
                    loc.pathname = p.replace(/^\/usd(\/)?/i, '/');
                }
                window.location.href = loc.toString();
            });

            wrapper.appendChild(select);
            miniCart.parentNode.insertBefore(wrapper, miniCart.nextSibling);
        });
    })();
    </script>
    <?php
}
// Disabled currency switcher dropdown
// add_action( 'wp_footer', 'gp_child_output_currency_switcher' );

// Disable zoom on product images
add_filter( 'woocommerce_single_product_zoom_enabled', '__return_false' );

// Blog: always show "Read article" for every post (excerpt or full content), regardless of length
add_action( 'generate_after_entry_content', 'gp_child_output_read_article_link', 5 );
function gp_child_output_read_article_link() {
    if ( ! is_main_query() || ! in_the_loop() ) {
        return;
    }
    if ( ! ( is_home() || is_archive() || is_search() ) ) {
        return;
    }
    $id = get_the_ID();
    if ( ! $id ) {
        return;
    }
    $title_attr = the_title_attribute( array( 'echo' => false ) );
    $aria      = sprintf(
        _x( 'Read more about %s', 'read more about post title', 'generatepress_child' ),
        the_title_attribute( array( 'echo' => false ) )
    );
    printf(
        '<p class="read-more-container"><a title="%1$s" class="read-more button" href="%2$s" aria-label="%3$s">%4$s</a></p>',
        esc_attr( $title_attr ),
        esc_url( get_permalink( $id ) ),
        esc_attr( $aria ),
        esc_html__( 'Read article', 'generatepress_child' )
    );
}

// On blog/archive: use "…" only in excerpt (no link inside excerpt); the link is output by the hook above
add_filter( 'excerpt_more', 'gp_child_excerpt_more_no_link', 20 );
function gp_child_excerpt_more_no_link( $more ) {
    if ( ! is_main_query() || ! in_the_loop() || ! ( is_home() || is_archive() || is_search() ) ) {
        return $more;
    }
    return ' …';
}

// Disable Gravity Forms quote email custom styling
// Remove the custom email styling filters after the plugin initializes
add_action( 'init', function() {
    global $wp_filter;
    
    // Remove gform_notification filter from OA_TFP_Gravity_Forms class
    if ( isset( $wp_filter['gform_notification'] ) ) {
        foreach ( $wp_filter['gform_notification']->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $callback_id => $callback ) {
                if ( is_array( $callback['function'] ) && 
                     is_object( $callback['function'][0] ) && 
                     get_class( $callback['function'][0] ) === 'OA_TFP_Gravity_Forms' &&
                     $callback['function'][1] === 'add_email_styles' ) {
                    remove_filter( 'gform_notification', $callback['function'], $priority );
                }
            }
        }
    }
    
    // Remove gform_pre_send_email filter from OA_TFP_Gravity_Forms class
    if ( isset( $wp_filter['gform_pre_send_email'] ) ) {
        foreach ( $wp_filter['gform_pre_send_email']->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $callback_id => $callback ) {
                if ( is_array( $callback['function'] ) && 
                     is_object( $callback['function'][0] ) && 
                     get_class( $callback['function'][0] ) === 'OA_TFP_Gravity_Forms' &&
                     $callback['function'][1] === 'inject_email_styles' ) {
                    remove_filter( 'gform_pre_send_email', $callback['function'], $priority );
                }
            }
        }
    }
}, 99 ); // High priority to run after plugins are loaded

/**
 * Gravity Forms: remove images from Fan options (nested form) section in one notification.
 * - "Admin Notification" = Fan options section has no images (rest of email unchanged).
 * - "Timber Fans Client" = keeps images.
 * Form ID 1 = Request a quote; nested Fan options form ID = 3.
 */
add_filter( 'gform_pre_send_email', 'gp_child_gf_remove_images_from_notification', 15, 4 );
function gp_child_gf_remove_images_from_notification( $email, $message_format, $notification, $entry ) {
	// Only Request a quote form (ID 1)
	if ( (int) rgar( $entry, 'form_id' ) !== 1 ) {
		return $email;
	}
	// Only the notification that should have no images in the Fan options part
	$notification_name_no_images = 'Admin Notification';
	if ( empty( $notification['name'] ) || $notification['name'] !== $notification_name_no_images ) {
		return $email;
	}
	// Only HTML emails
	if ( $message_format !== 'html' || empty( $email['message'] ) ) {
		return $email;
	}

	$email['message'] = gp_child_gf_strip_images_in_fan_options_section( $email['message'] );
	return $email;
}

/**
 * Remove <img> tags only inside the Fan options (nested) section of the email HTML.
 * Nested section is wrapped in a table with style containing "faebd2" (GP Nested Forms markup).
 */
function gp_child_gf_strip_images_in_fan_options_section( $html ) {
	if ( strpos( $html, '<img' ) === false ) {
		return $html;
	}
	if ( strpos( $html, 'faebd2' ) === false ) {
		return $html;
	}

	$libxml_prev = libxml_use_internal_errors( true );
	$doc = new DOMDocument();
	$doc->loadHTML( '<?xml encoding="UTF-8"><div id="gf-email-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_use_internal_errors( $libxml_prev );

	$xpath = new DOMXPath( $doc );
	$tables = $xpath->query( '//table[contains(@style, "faebd2")]' );
	if ( ! $tables || $tables->length === 0 ) {
		return $html;
	}

	foreach ( $tables as $table ) {
		$imgs = $xpath->query( './/img', $table );
		if ( ! $imgs ) {
			continue;
		}
		for ( $i = $imgs->length - 1; $i >= 0; $i-- ) {
			$img = $imgs->item( $i );
			if ( $img && $img->parentNode ) {
				$img->parentNode->removeChild( $img );
			}
		}
	}

	$root = $doc->getElementById( 'gf-email-root' );
	if ( ! $root ) {
		return $html;
	}
	$out = '';
	foreach ( $root->childNodes as $child ) {
		$out .= $doc->saveHTML( $child );
	}
	return $out;
}

/**
 * ---------------------------------------------------------------------------
 * Google Fonts performance: font-display: swap + trim Montserrat weights.
 *
 * GeneratePress was loading all 18 Montserrat variants (100–900 + italics)
 * with display=auto, which blocks text rendering. We force display=swap and
 * keep only the weights the site actually uses.
 *
 * To change which weights load, edit $keep_weights below. Each number maps to:
 *   400 = Regular, 500 = Medium, 600 = SemiBold, 700 = Bold, 800 = ExtraBold.
 * Add italics with e.g. '700italic' if a design needs italic text.
 * ---------------------------------------------------------------------------
 */
add_filter( 'generate_google_font_display', 'gp_child_google_font_display', 99 );
function gp_child_google_font_display() {
	return 'swap';
}

add_filter( 'generate_typography_google_fonts', 'gp_child_trim_montserrat_weights', 99 );
function gp_child_trim_montserrat_weights( $fonts ) {
	if ( empty( $fonts ) ) {
		return $fonts;
	}

	// Weights to keep for Montserrat. Adjust to match your design.
	$keep_weights = array( '400', '500', '600', '700' );

	// GeneratePress joins multiple families with a pipe: "Montserrat:100,...|Other:400".
	$families = explode( '|', $fonts );

	foreach ( $families as $index => $family ) {
		// Only touch the Montserrat family; leave everything else untouched.
		if ( strpos( $family, 'Montserrat:' ) === 0 ) {
			$families[ $index ] = 'Montserrat:' . implode( ',', $keep_weights );
		}
	}

	return implode( '|', $families );
}

/**
 * ---------------------------------------------------------------------------
 * Gotham (self-hosted) fallback stack.
 *
 * The Font Library defines --gp-font--gotham as just "Gotham" with no fallback,
 * so while the font loads the browser shows Times (serif) — a jarring flash,
 * most visible on the non-bold part of the hero H1 ("ceiling fans").
 *
 * Gotham here is actually Gotham *Condensed*, so we fall back to Arial Narrow
 * (similar width) and then generic sans-serif. This removes the Times flash.
 * ---------------------------------------------------------------------------
 */
add_action( 'wp_head', 'gp_child_gotham_font_fallback', 100 );
function gp_child_gotham_font_fallback() {
	echo '<style id="gp-child-gotham-fallback">:root{--gp-font--gotham:"Gotham","Arial Narrow","Helvetica Neue",Arial,sans-serif !important;--gp-font-gotham:"Gotham","Arial Narrow","Helvetica Neue",Arial,sans-serif !important;}</style>' . "\n";
}

// Maintenance Mode
// Set to true to enable maintenance mode, false to disable
define( 'MAINTENANCE_MODE', false );

function show_maintenance_page() {
    // Allow admins to access the site even in maintenance mode
    if ( current_user_can( 'administrator' ) ) {
        return;
    }
    
    // Allow access to wp-admin and wp-login.php
    if ( is_admin() || strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false || strpos( $_SERVER['REQUEST_URI'], 'wp-admin' ) !== false ) {
        return;
    }
    
    // Load the maintenance page
    $maintenance_file = ABSPATH . 'maintenance.php';
    if ( file_exists( $maintenance_file ) ) {
        include $maintenance_file;
        exit;
    } else {
        // Fallback maintenance message if file doesn't exist
        wp_die( 
            '<h1>Site Under Maintenance</h1><p>We are currently performing scheduled maintenance. Please check back soon.</p>',
            'Site Under Maintenance',
            array( 'response' => 503 )
        );
    }
}

if ( defined( 'MAINTENANCE_MODE' ) && MAINTENANCE_MODE === true ) {
    add_action( 'template_redirect', 'show_maintenance_page', 1 );
}

/**
 * ---------------------------------------------------------------------------
 * AJAX add-to-cart on single product pages, then open the *visible* mini-cart.
 *
 * There are two mini-cart instances in the header. Woo's open_drawer event only
 * targets the first one (often blank). The filled drawer opens when we click
 * the visible header cart button — same as a real user click.
 * ---------------------------------------------------------------------------
 */
add_filter( 'render_block_woocommerce/mini-cart', 'gp_child_mini_cart_disable_auto_open', 10, 2 );
function gp_child_mini_cart_disable_auto_open( $content, $block ) {
	// Prevent Woo from auto-opening the first (often hidden) mini-cart instance.
	if ( strpos( $content, 'data-add-to-cart-behaviour=' ) === false ) {
		$content = preg_replace(
			'/<div([^>]*class="[^"]*wc-block-mini-cart[^"]*"[^>]*)>/',
			'<div$1 data-add-to-cart-behaviour="none">',
			$content,
			1
		);
	} else {
		$content = preg_replace(
			'/data-add-to-cart-behaviour="[^"]*"/',
			'data-add-to-cart-behaviour="none"',
			$content
		);
	}
	return $content;
}

/**
 * WooCommerce 10+/11 split block CSS and WordPress 7.1 on-demand block assets.
 *
 * Cart/checkout/mini-cart block.json have no "style" handle, and
 * AbstractBlock::enqueue_assets() no longer prints styles. Frontend then loads
 * scripts without checkout.css / packages-style.css / mini-cart.css — collapsed
 * layout, tiny inputs, and an unstyled header mini-cart drawer.
 */
add_action( 'wp_enqueue_scripts', 'gp_child_enqueue_wc_blocks_frontend_styles', 30 );
function gp_child_enqueue_wc_blocks_frontend_styles() {
	if ( is_admin() ) {
		return;
	}

	$handles = array(
		'wc-blocks-style',
		'wc-blocks-packages-style',
		'wc-blocks-style-mini-cart',
		'wc-blocks-style-mini-cart-contents',
	);

	if ( function_exists( 'is_cart' ) && is_cart() ) {
		$handles[] = 'wc-blocks-style-cart';
		$handles[] = 'wc-blocks-style-all-products';
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		$handles[] = 'wc-blocks-style-checkout';
		$handles[] = 'wc-blocks-style-all-products';
	}

	foreach ( $handles as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
		}
	}

	gp_child_enqueue_wc_block_css_file( 'wc-blocks-packages-style', 'packages-style.css' );
	gp_child_enqueue_wc_block_css_file( 'wc-blocks-style-mini-cart', 'mini-cart.css' );
	gp_child_enqueue_wc_block_css_file( 'wc-blocks-style-mini-cart-contents', 'mini-cart-contents.css' );

	if ( function_exists( 'is_cart' ) && is_cart() ) {
		gp_child_enqueue_wc_block_css_file( 'wc-blocks-style-cart', 'cart.css' );
	}
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		gp_child_enqueue_wc_block_css_file( 'wc-blocks-style-checkout', 'checkout.css' );
	}

	// Mini-cart drawer width is added by MiniCartContents during render, after
	// wp_head, so it never prints. Attach the fallback to styles that already
	// enqueue in wp_head.
	$drawer_css = ':root{--drawer-width:480px;--neg-drawer-width:calc(var(--drawer-width)*-1);}'
		. '.wc-block-mini-cart__badge{left:100%!important;margin-left:-4px!important;transform:translateY(-50%)!important;}';
	foreach ( array( 'generate-child', 'wc-blocks-style', 'wc-blocks-style-mini-cart' ) as $inline_handle ) {
		if ( wp_style_is( $inline_handle, 'enqueued' ) ) {
			wp_add_inline_style( $inline_handle, $drawer_css );
		}
	}
}

/**
 * Register + enqueue a WooCommerce Blocks CSS file when WP never registered the handle.
 *
 * @param string $handle Style handle.
 * @param string $file   Filename under woocommerce/assets/client/blocks/.
 */
function gp_child_enqueue_wc_block_css_file( $handle, $file ) {
	if ( wp_style_is( $handle, 'enqueued' ) ) {
		return;
	}

	if ( wp_style_is( $handle, 'registered' ) ) {
		wp_enqueue_style( $handle );
		return;
	}

	$relative = 'woocommerce/assets/client/blocks/' . ltrim( $file, '/' );
	$path     = WP_PLUGIN_DIR . '/' . $relative;
	if ( ! is_readable( $path ) ) {
		return;
	}

	wp_enqueue_style(
		$handle,
		plugins_url( $relative ),
		array( 'wc-blocks-style' ),
		(string) filemtime( $path )
	);
}

/**
 * WC Checkout block tries to dequeue classic checkout.js during render, which is
 * after wp_enqueue_scripts has already run — so checkout.min.js still prints.
 */
add_action( 'wp_enqueue_scripts', 'gp_child_dequeue_classic_checkout_assets', 100 );
function gp_child_dequeue_classic_checkout_assets() {
	if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	wp_dequeue_script( 'wc-checkout' );
	wp_dequeue_script( 'wc-address-autocomplete' );
	wp_dequeue_style( 'wc-address-autocomplete' );
	wp_dequeue_script( 'selectWoo' );
	wp_dequeue_style( 'select2' );
}

add_action( 'wp_enqueue_scripts', 'gp_child_enqueue_ajax_add_to_cart', 25 );
function gp_child_enqueue_ajax_add_to_cart() {
	if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	wp_enqueue_script( 'wc-add-to-cart' );

	$js = <<<'JS'
(function ($) {
	function disableAutoOpenOnAllMiniCarts() {
		document.querySelectorAll('.wc-block-mini-cart').forEach(function (el) {
			el.dataset.addToCartBehaviour = 'none';
		});
	}

	function visibleMiniCartButton() {
		var buttons = document.querySelectorAll('.wc-block-mini-cart__button');
		for (var i = 0; i < buttons.length; i++) {
			if (buttons[i].offsetParent !== null) {
				return buttons[i];
			}
		}
		return buttons[0] || null;
	}

	function openVisibleMiniCart() {
		var btn = visibleMiniCartButton();
		if (btn) {
			btn.click();
		}
	}

	function getCartItemsCount() {
		return fetch('/wp-json/wc/store/v1/cart', {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' }
		}).then(function (r) { return r.json(); }).then(function (cart) {
			return cart && typeof cart.items_count !== 'undefined' ? cart.items_count : 0;
		}).catch(function () { return 0; });
	}

	disableAutoOpenOnAllMiniCarts();

	$(document).on('submit', 'form.cart', function (e) {
		var $form = $(this);
		var submitter = e.originalEvent && e.originalEvent.submitter ? e.originalEvent.submitter : null;

		if (submitter && !$(submitter).hasClass('single_add_to_cart_button')) {
			return;
		}
		if ($form.closest('.product-type-external').length) {
			return;
		}

		var $button = $form.find('.single_add_to_cart_button');
		if (!$button.length || $button.hasClass('disabled') || $button.prop('disabled')) {
			return;
		}

		var $variationId = $form.find('input.variation_id, input[name="variation_id"]');
		if ($variationId.length && !parseInt($variationId.val(), 10)) {
			return;
		}

		e.preventDefault();
		disableAutoOpenOnAllMiniCarts();

		$('.woocommerce-notices-wrapper .woocommerce-error, .woocommerce-notices-wrapper .woocommerce-message, .woocommerce-error, .woocommerce-message').filter(function () {
			return /choose product options|added to your cart/i.test($(this).text());
		}).remove();

		$button.removeClass('added');
		$button.prop('disabled', true);

		var beforeCountPromise = getCartItemsCount();
		var postUrl = ($form.attr('action') && $form.attr('action').length) ? $form.attr('action') : window.location.href;

		$.ajax({
			type: 'POST',
			url: postUrl,
			data: $form.serialize(),
			success: function (html) {
				$button.prop('disabled', false);

				var $response = $('<div>').append($.parseHTML(html, document, true));

				if ($response.find('.woocommerce-error').length) {
					var $target = $('.woocommerce-notices-wrapper').first();
					if (!$target.length) {
						$target = $form.closest('.summary, .product').first();
					}
					$target.prepend($response.find('.woocommerce-error').first());
					return;
				}

				beforeCountPromise.then(function (beforeCount) {
					return getCartItemsCount().then(function (afterCount) {
						return { beforeCount: beforeCount, afterCount: afterCount };
					});
				}).then(function (counts) {
					var added = counts.afterCount > counts.beforeCount;
					var successNotice = $response.find('.woocommerce-message').filter(function () {
						return /added to your cart/i.test($(this).text());
					}).length > 0;

					if (!added && !successNotice) {
						$form.off('submit');
						$form.get(0).submit();
						return;
					}

					$button.addClass('added');

					// Refresh cart data without auto-opening the first (blank) instance.
					disableAutoOpenOnAllMiniCarts();
					document.body.dispatchEvent(new CustomEvent('wc-blocks_adding_to_cart', { bubbles: true }));
					$(document.body).trigger('added_to_cart', [null, null, $button]);

					// Open only the visible header cart button (the one that works when clicked).
					setTimeout(openVisibleMiniCart, 300);
				});
			},
			error: function () {
				$button.prop('disabled', false);
				$form.off('submit');
				$form.get(0).submit();
			}
		});
	});
})(jQuery);
JS;

	$handle = wp_script_is( 'wc-add-to-cart', 'enqueued' ) ? 'wc-add-to-cart' : 'jquery';
	wp_add_inline_script( $handle, $js, 'after' );
}

// Clear any leftover auto-open cookie from earlier experiments.
add_action( 'init', 'gp_child_clear_legacy_open_mini_cart_cookie', 1 );
function gp_child_clear_legacy_open_mini_cart_cookie() {
	if ( empty( $_COOKIE['gp_open_mini_cart'] ) || headers_sent() ) {
		return;
	}
	wc_setcookie( 'gp_open_mini_cart', '', time() - YEAR_IN_SECONDS );
	unset( $_COOKIE['gp_open_mini_cart'] );
}

/**
 * ---------------------------------------------------------------------------
 * Front-end performance: dequeue assets that are not needed on the current page.
 *
 * Keeps WooCommerce Blocks mini-cart (header cart) everywhere.
 * Strips shipping / variation / addons / quote scripts off pages that only
 * display products (e.g. homepage carousels with no Add to cart).
 * ---------------------------------------------------------------------------
 */
add_action( 'wp_enqueue_scripts', 'gp_child_dequeue_unused_assets', 99999 );
add_action( 'wp_print_scripts', 'gp_child_dequeue_unused_assets', 99999 );
add_action( 'wp_print_styles', 'gp_child_dequeue_unused_assets', 99999 );

function gp_child_dequeue_unused_assets() {
	if ( is_admin() ) {
		return;
	}

	$is_cart_checkout = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );
	$is_product       = function_exists( 'is_product' ) && is_product();
	$quote_page_id    = absint( get_option( 'oa_tfp_quote_page' ) );
	$is_quote         = is_page( 'quote' ) || ( $quote_page_id && is_page( $quote_page_id ) );
	$is_catalog       = function_exists( 'is_shop' ) && (
		is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag()
	);

	// Shipping / checkout plugins: only on cart, checkout, account.
	if ( ! $is_cart_checkout ) {
		gp_child_dequeue_handles(
			array(
				// The Courier Guy
				'the-courier-guy',
				'the-courier-guy-main',
				'the-courier-guy-notice',
				'tcg-main',
				'tcg-notice',
				// ELEX DHL Express
				'elex-dhl-express',
				'dhl_cart_checkout_scripts',
				'elex-woo-dhl-express-shipping',
				// Datepicker often pulled in for shipping rate forms
				'jquery-ui-datepicker',
			),
			array(
				// Styles
				'the-courier-guy',
				'tcg-main',
				'elex-woo-dhl-express-shipping',
			)
		);

		gp_child_dequeue_by_src_contains(
			array(
				'/the-courier-guy/',
				'/elex-woo-dhl-express-shipping/',
				'/woocommerce-dhlexpress-services/',
			)
		);
	}

	// Single-product variation / add-ons tooling: not needed on homepage carousels.
	if ( ! $is_product ) {
		gp_child_dequeue_handles(
			array(
				'wc-add-to-cart-variation',
				'woocommerce-add-to-cart-variation',
				'jquery-blockui',
				'accounting',
				// Product Add-Ons
				'woocommerce-addons',
				'woocommerce-addons-validation',
				'pao-validation',
				'wc-product-addons',
			),
			array(
				'woocommerce-addons-css',
				'woocommerce-product-addons',
			)
		);

		gp_child_dequeue_by_src_contains(
			array(
				'/woocommerce-product-addons/',
				'/add-to-cart-variation',
			)
		);
	}

	// Nested-form quote popup (image swatches + size chips). Needed on product
	// pages and /quote/; strip it from marketing pages that only list products.
	if ( ! $is_product && ! $is_quote ) {
		gp_child_dequeue_handles(
			array(
				'oa-timberfans-gf-mod',
				'oa-tf-gf-mod',
			),
			array(
				'oa-timberfans-gf-mod',
			)
		);

		gp_child_dequeue_by_src_contains(
			array(
				'/oa-timberfans-gf-mod/',
			)
		);
	}

	// Classic add-to-cart JS: homepage products link through; keep on shop/catalog/product.
	if ( ! $is_product && ! $is_catalog && ! $is_cart_checkout ) {
		gp_child_dequeue_handles(
			array(
				'wc-add-to-cart',
				'woocommerce-add-to-cart',
			),
			array()
		);

		gp_child_dequeue_by_src_contains(
			array(
				'/frontend/add-to-cart.min.js',
				'/frontend/add-to-cart.js',
			)
		);
	}
}

/**
 * Dequeue/deregister known script and style handles.
 *
 * @param string[] $scripts Script handles.
 * @param string[] $styles  Style handles.
 */
function gp_child_dequeue_handles( $scripts, $styles = array() ) {
	foreach ( (array) $scripts as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
	foreach ( (array) $styles as $handle ) {
		wp_dequeue_style( $handle );
		wp_deregister_style( $handle );
	}
}

/**
 * Dequeue any enqueued script/style whose src contains one of the needles.
 * Catches plugins that use unpredictable handles.
 *
 * @param string[] $needles Path fragments to match in src URLs.
 */
function gp_child_dequeue_by_src_contains( $needles ) {
	$needles = array_filter( array_map( 'strval', (array) $needles ) );
	if ( ! $needles ) {
		return;
	}

	$matches = static function ( $src ) use ( $needles ) {
		if ( ! $src ) {
			return false;
		}
		foreach ( $needles as $needle ) {
			if ( strpos( $src, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	};

	global $wp_scripts, $wp_styles;

	if ( $wp_scripts instanceof WP_Scripts ) {
		foreach ( $wp_scripts->queue as $handle ) {
			$src = isset( $wp_scripts->registered[ $handle ]->src ) ? $wp_scripts->registered[ $handle ]->src : '';
			if ( $matches( $src ) ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}
		}
	}

	if ( $wp_styles instanceof WP_Styles ) {
		foreach ( $wp_styles->queue as $handle ) {
			$src = isset( $wp_styles->registered[ $handle ]->src ) ? $wp_styles->registered[ $handle ]->src : '';
			if ( $matches( $src ) ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}
}

/**
 * ---------------------------------------------------------------------------
 * Delay Instagram Feed + WhatsApp widget assets until first interaction or idle.
 * Keeps markup in place; CSS/JS load after scroll/touch/mouse or ~4s fallback.
 * ---------------------------------------------------------------------------
 */
function gp_child_delay_widget_script_handles() {
	return array( 'sbi_scripts', 'nta-wa-libs', 'nta-js-global', 'nta-js-popup' );
}

function gp_child_delay_widget_style_handles() {
	return array( 'sbi_styles', 'nta-css-popup' );
}

add_filter( 'script_loader_tag', 'gp_child_delay_widget_script_tag', 20, 3 );
function gp_child_delay_widget_script_tag( $tag, $handle, $src ) {
	if ( is_admin() || ! in_array( $handle, gp_child_delay_widget_script_handles(), true ) ) {
		return $tag;
	}

	// Force browsers to ignore until our loader rewrites type back to JS.
	$tag = preg_replace( '/\s+type=(["\'])[^"\']*\1/', '', $tag, 1 );
	$tag = preg_replace( '/<script\b/', '<script type="text/plain" data-gp-delay="1"', $tag, 1 );
	return $tag;
}

add_filter( 'wp_inline_script_attributes', 'gp_child_delay_widget_inline_attrs', 20, 2 );
function gp_child_delay_widget_inline_attrs( $attributes, $data ) {
	if ( is_admin() || empty( $attributes['id'] ) || ! is_string( $attributes['id'] ) ) {
		return $attributes;
	}

	foreach ( gp_child_delay_widget_script_handles() as $handle ) {
		if ( $attributes['id'] === $handle . '-js-extra' ) {
			$attributes['type']          = 'text/plain';
			$attributes['data-gp-delay'] = '1';
			break;
		}
	}

	return $attributes;
}

add_filter( 'style_loader_tag', 'gp_child_delay_widget_style_tag', 20, 2 );
function gp_child_delay_widget_style_tag( $html, $handle ) {
	if ( is_admin() || ! in_array( $handle, gp_child_delay_widget_style_handles(), true ) ) {
		return $html;
	}

	$html = str_replace( "media='all'", "media='print' data-gp-delay-style='1'", $html );
	$html = str_replace( 'media="all"', 'media="print" data-gp-delay-style="1"', $html );
	return $html;
}

add_action( 'wp_footer', 'gp_child_print_delayed_widget_loader', 99 );
function gp_child_print_delayed_widget_loader() {
	if ( is_admin() ) {
		return;
	}
	?>
<script data-no-minify="1">
(function () {
	var done = false;
	function activateDelayedWidgets() {
		if (done) return;
		done = true;
		document.querySelectorAll('link[data-gp-delay-style]').forEach(function (link) {
			link.media = 'all';
		});
		var nodes = Array.prototype.slice.call(document.querySelectorAll('script[data-gp-delay="1"]'));
		function next(i) {
			if (i >= nodes.length) return;
			var old = nodes[i];
			var neu = document.createElement('script');
			Array.prototype.forEach.call(old.attributes, function (attr) {
				if (attr.name === 'type' || attr.name === 'data-gp-delay') return;
				neu.setAttribute(attr.name, attr.value);
			});
			if (!old.getAttribute('src')) {
				neu.text = old.textContent;
				old.parentNode.insertBefore(neu, old);
				old.parentNode.removeChild(old);
				next(i + 1);
				return;
			}
			neu.onload = neu.onerror = function () { next(i + 1); };
			old.parentNode.insertBefore(neu, old);
			old.parentNode.removeChild(old);
		}
		next(0);
	}

	// Do not use mousemove/keydown — those fire immediately and hide the benefit.
	['scroll', 'touchstart'].forEach(function (evt) {
		window.addEventListener(evt, activateDelayedWidgets, { once: true, passive: true });
	});

	// Load when Instagram block nears the viewport (home), otherwise wait for scroll/fallback.
	var feed = document.getElementById('sb_instagram');
	if (feed && 'IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					activateDelayedWidgets();
					io.disconnect();
				}
			});
		}, { rootMargin: '200px 0px' });
		io.observe(feed);
	}

	// Long fallback so first paint / Lighthouse stay clear of these widgets.
	setTimeout(activateDelayedWidgets, 10000);
})();
</script>
	<?php
}

/**
 * Hide the featured image on the covered-patio blog post.
 * Keep the thumbnail for archives, cards, and social/OG.
 */
add_action( 'wp', 'gp_child_hide_patio_featured_image' );
function gp_child_hide_patio_featured_image() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	if ( get_post_field( 'post_name' ) !== 'wooden-ceiling-fans-covered-patios' ) {
		return;
	}

	remove_action( 'generate_before_content', 'generate_featured_page_header_inside_single', 10 );
	add_filter( 'body_class', 'gp_child_hide_featured_image_body_class' );
}

function gp_child_hide_featured_image_body_class( $classes ) {
	$classes[] = 'hide-featured-image';
	return $classes;
}

/**
 * Disable comments on blog posts. Product reviews stay intact.
 */
add_filter( 'comments_open', 'gp_child_disable_blog_comments', 20, 2 );
add_filter( 'pings_open', 'gp_child_disable_blog_comments', 20, 2 );
function gp_child_disable_blog_comments( $open, $post_id ) {
	$post = get_post( $post_id );
	if ( $post && 'post' === $post->post_type ) {
		return false;
	}
	return $open;
}

add_filter( 'get_comments_number', 'gp_child_hide_blog_comment_count', 20, 2 );
function gp_child_hide_blog_comment_count( $count, $post_id ) {
	if ( is_admin() ) {
		return $count;
	}

	$post = get_post( $post_id );
	if ( $post && 'post' === $post->post_type ) {
		return 0;
	}

	return $count;
}

require_once get_stylesheet_directory() . '/inc/seed-patio-blog-post.php';

