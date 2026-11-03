<?php
/**
 * Plugin Name: OA TFP Inline Nested Forms
 * Description: Mockup-style inline fan cards for the quote form; syncs into GP Nested Forms entries.
 * Version: 2.0.48
 * Author: Open Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OA_TFP_Quote_Fan_Cards {

	const PARENT_FORM_ID = 1;
	const NESTED_FIELD_ID = 10;
	const CHILD_FORM_ID = 3;
	const VERSION = '2.0.48';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 999 );
		add_action( 'wp_ajax_oa_tfp_fan_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_nopriv_oa_tfp_fan_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_oa_tfp_fan_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_nopriv_oa_tfp_fan_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_oa_tfp_get_available_lights', array( __CLASS__, 'ajax_lights' ) );
		add_action( 'wp_ajax_nopriv_oa_tfp_get_available_lights', array( __CLASS__, 'ajax_lights' ) );
		add_action( 'wp_ajax_oa_tfp_get_fan_options', array( __CLASS__, 'ajax_fan_options' ) );
		add_action( 'wp_ajax_nopriv_oa_tfp_get_fan_options', array( __CLASS__, 'ajax_fan_options' ) );
		add_filter( 'gform_notification', array( __CLASS__, 'append_fan_email_fields' ), 20, 3 );
		add_filter( 'gform_pre_send_email', array( __CLASS__, 'strip_quote_email_images' ), 999, 4 );
		add_filter( 'gform_merge_tag_filter', array( __CLASS__, 'strip_quote_merge_tag_images' ), 999, 6 );
		// Product Mods passes "gpnf-modal oa-tfp-inline-nested" as one class token.
		// Tingle rejects the space and Nested Forms never finishes loading.
		add_filter( 'gpnf_init_script_args_1_10', array( __CLASS__, 'fix_nested_modal_class' ), 99 );
	}

	/**
	 * Keep Nested Forms modal init from crashing on a two-word class string.
	 *
	 * @param array $args Nested form init args.
	 * @return array
	 */
	public static function fix_nested_modal_class( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$args['modalClass'] = 'gpnf-modal';
		return $args;
	}

	private static function is_quote_context() {
		if ( is_admin() ) {
			return false;
		}
		if ( function_exists( 'is_page' ) && ( is_page( 'quote' ) || is_page( 78 ) ) ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return false !== strpos( $uri, '/quote' );
	}

	public static function assets() {
		if ( ! self::is_quote_context() ) {
			return;
		}

		wp_register_style( 'oa-tfp-fan-cards', false, array(), self::VERSION );
		wp_enqueue_style( 'oa-tfp-fan-cards' );
		wp_add_inline_style( 'oa-tfp-fan-cards', self::css() );

		wp_register_script( 'oa-tfp-fan-cards', false, array( 'jquery' ), self::VERSION, true );
		wp_enqueue_script( 'oa-tfp-fan-cards' );
		$options = self::get_options();
		wp_localize_script(
			'oa-tfp-fan-cards',
			'OA_TFP_FAN_CARDS',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'oa_tfp_fan_cards' ),
				'parentForm'   => self::PARENT_FORM_ID,
				'nestedField'  => self::NESTED_FIELD_ID,
				'childForm'    => self::CHILD_FORM_ID,
				'options'      => $options,
				'availability' => self::availability_map( $options['ranges'] ),
			)
		);
		wp_add_inline_script( 'oa-tfp-fan-cards', self::js() );
	}

	/**
	 * Build selectable options from child form (Form 3).
	 */
	private static function get_options() {
		$options = array(
			'ranges'  => array(),
			'sizes'   => array(),
			'timber'  => array(),
			'metal'   => array(),
			'speed'   => array(),
			'light'   => array(),
		);

		if ( ! class_exists( 'GFAPI' ) ) {
			return $options;
		}

		$form = GFAPI::get_form( self::CHILD_FORM_ID );
		if ( empty( $form['fields'] ) || is_wp_error( $form ) ) {
			return $options;
		}

		$map = array(
			1  => 'ranges',
			4  => 'sizes',
			5  => 'timber',
			6  => 'metal',
			7  => 'speed',
			10 => 'light',
		);

		foreach ( $form['fields'] as $field ) {
			$id = (int) $field->id;
			if ( ! isset( $map[ $id ] ) || empty( $field->choices ) ) {
				continue;
			}
			$key = $map[ $id ];
			foreach ( $field->choices as $index => $choice ) {
				$options[ $key ][] = array(
					'value' => (string) $choice['value'],
					'label' => wp_strip_all_tags( (string) $choice['text'] ),
					'image' => self::choice_image_url( $field, $choice, $index ),
				);
			}

			// Fill missing images from rendered field markup (GF image choices).
			if ( in_array( $key, array( 'timber', 'metal', 'ranges' ), true ) ) {
				$from_markup = self::images_from_field_markup( $form, $id );
				foreach ( $options[ $key ] as &$opt ) {
					if ( empty( $opt['image'] ) && ! empty( $from_markup[ $opt['value'] ] ) ) {
						$opt['image'] = $from_markup[ $opt['value'] ];
					}
				}
				unset( $opt );
			}
		}

		return $options;
	}

	/**
	 * Parse image URLs from rendered choice markup for a field.
	 *
	 * @param array $form     Form array.
	 * @param int   $field_id Field ID.
	 * @return array<string,string> value => image URL
	 */
	private static function images_from_field_markup( $form, $field_id ) {
		$map   = array();
		$field = GFAPI::get_field( $form, $field_id );
		if ( ! $field || ! is_object( $field ) || ! method_exists( $field, 'get_field_input' ) ) {
			return $map;
		}

		$html = $field->get_field_input( $form, '', '0' );
		if ( ! is_string( $html ) || '' === $html ) {
			return $map;
		}

		if ( preg_match_all(
			"/name='input_{$field_id}'[^>]*value='([^']+)'[\\s\\S]*?<img[^>]+src=[\"']([^\"']+)[\"']/i",
			$html,
			$matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $matches as $m ) {
				$map[ $m[1] ] = esc_url_raw( $m[2] );
			}
		}

		// Nested form fields often render as input_5 without form prefix in child context.
		if ( ! $map && preg_match_all(
			"/value='([^']+)'[\\s\\S]*?<img[^>]+src=[\"']([^\"']+)[\"'][\\s\\S]*?<span>/i",
			$html,
			$matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $matches as $m ) {
				$map[ $m[1] ] = esc_url_raw( $m[2] );
			}
		}

		return $map;
	}

	/**
	 * Resolve a Gravity Forms choice image URL when available.
	 *
	 * @param object $field  GF_Field instance.
	 * @param array  $choice Choice array.
	 * @param int    $index  Choice index.
	 * @return string
	 */
	private static function choice_image_url( $field, $choice, $index ) {
		$candidates = array();

		foreach ( array( 'image_choice_file', 'image', 'imageUrl', 'image_url', 'attachment_id' ) as $key ) {
			if ( ! empty( $choice[ $key ] ) ) {
				$candidates[] = $choice[ $key ];
			}
		}

		// Some GF image-choice builds store files on the field object.
		if ( ! empty( $field->imageChoices_images ) && is_array( $field->imageChoices_images ) && ! empty( $field->imageChoices_images[ $index ] ) ) {
			$candidates[] = $field->imageChoices_images[ $index ];
		}
		if ( ! empty( $field->choices[ $index ]['image_choice_file'] ) ) {
			$candidates[] = $field->choices[ $index ]['image_choice_file'];
		}

		foreach ( $candidates as $candidate ) {
			if ( is_numeric( $candidate ) ) {
				$url = wp_get_attachment_image_url( (int) $candidate, 'thumbnail' );
				if ( ! $url ) {
					$url = wp_get_attachment_url( (int) $candidate );
				}
				if ( $url ) {
					return esc_url_raw( $url );
				}
				continue;
			}
			$candidate = trim( (string) $candidate );
			if ( $candidate && filter_var( $candidate, FILTER_VALIDATE_URL ) ) {
				return esc_url_raw( $candidate );
			}
		}

		return '';
	}

	/**
	 * Quote notification emails: labels only, no choice/product images.
	 *
	 * @param array  $email           Email payload.
	 * @param string $message_format  html|text.
	 * @param array  $notification    Notification.
	 * @param array  $entry           Entry.
	 * @return array
	 */
	/**
	 * Nested Form emails only print the summary columns. Ceiling and light are not in that list.
	 *
	 * @param array $notification Notification.
	 * @param array $form         Form.
	 * @param array $entry        Entry.
	 * @return array
	 */
	public static function append_fan_email_fields( $notification, $form, $entry ) {
		if ( (int) rgar( $form, 'id' ) !== self::PARENT_FORM_ID || ! class_exists( 'GFAPI' ) ) {
			return $notification;
		}

		$ids = array_filter( array_map( 'absint', explode( ',', (string) rgar( $entry, (string) self::NESTED_FIELD_ID ) ) ) );
		if ( ! $ids ) {
			return $notification;
		}

		$child_form = GFAPI::get_form( self::CHILD_FORM_ID );
		if ( empty( $child_form['fields'] ) || is_wp_error( $child_form ) ) {
			return $notification;
		}

		$chunks = array();
		$index  = 0;
		foreach ( $ids as $id ) {
			$child = GFAPI::get_entry( $id );
			if ( is_wp_error( $child ) ) {
				continue;
			}
			$index++;
			$lines = array();
			foreach ( $child_form['fields'] as $field ) {
				if ( ! in_array( (int) $field->id, array( 9, 10 ), true ) ) {
					continue;
				}
				$raw_value = rgar( $child, (string) $field->id );
				if ( '' === $raw_value || null === $raw_value ) {
					continue;
				}
				$display = class_exists( 'GFCommon' )
					? GFCommon::get_lead_field_display( $field, $raw_value, rgar( $child, 'currency' ), false )
					: $raw_value;
				$display = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $display ) ) );
				if ( '' === $display ) {
					continue;
				}
				$label   = method_exists( $field, 'get_field_label' ) ? $field->get_field_label( false, $raw_value ) : $field->label;
				$lines[] = esc_html( wp_strip_all_tags( (string) $label ) ) . ': ' . esc_html( $display );
			}
			if ( $lines ) {
				$chunks[] = '<p><strong>Fan ' . $index . '</strong><br>' . implode( '<br>', $lines ) . '</p>';
			}
		}

		if ( $chunks && isset( $notification['message'] ) ) {
			$notification['message'] .= implode( '', $chunks );
		}

		return $notification;
	}

	public static function strip_quote_email_images( $email, $message_format, $notification, $entry ) {
		$form_id = isset( $entry['form_id'] ) ? (int) $entry['form_id'] : 0;
		if ( ! in_array( $form_id, array( self::PARENT_FORM_ID, self::CHILD_FORM_ID ), true ) ) {
			return $email;
		}
		if ( isset( $email['message'] ) ) {
			$email['message'] = self::strip_email_images( $email['message'] );
		}
		return $email;
	}

	/**
	 * @param string $value Merge tag value.
	 * @param string $merge_tag Merge tag.
	 * @param string $modifier Modifier.
	 * @param object $field Field.
	 * @return string
	 */
	public static function strip_quote_merge_tag_images( $value, $merge_tag, $modifier, $field ) {
		$form_id = 0;
		if ( is_object( $field ) && isset( $field->formId ) ) {
			$form_id = (int) $field->formId;
		}
		if ( ! in_array( $form_id, array( self::PARENT_FORM_ID, self::CHILD_FORM_ID ), true ) ) {
			return $value;
		}
		return self::strip_email_images( $value );
	}

	/**
	 * @param string $html HTML or text.
	 * @return string
	 */
	private static function strip_email_images( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$html = preg_replace( '/<img\b[^>]*>/i', '', $html );
		$html = preg_replace( '/<picture\b[^>]*>.*?<\/picture>/is', '', $html );
		return $html;
	}

	public static function ajax_save() {
		check_ajax_referer( 'oa_tfp_fan_cards', 'nonce' );

		if ( ! class_exists( 'GFAPI' ) ) {
			wp_send_json_error( array( 'message' => 'Gravity Forms missing' ), 500 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$session  = isset( $_POST['session_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['session_hash'] ) ) : '';
		$fan      = array(
			'1'  => isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : '',
			'3'  => isset( $_POST['qty'] ) ? sanitize_text_field( wp_unslash( $_POST['qty'] ) ) : '1',
			'4'  => isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '',
			'5'  => isset( $_POST['timber'] ) ? sanitize_text_field( wp_unslash( $_POST['timber'] ) ) : '',
			'6'  => isset( $_POST['metal'] ) ? sanitize_text_field( wp_unslash( $_POST['metal'] ) ) : '',
			'7'  => isset( $_POST['speed'] ) ? sanitize_text_field( wp_unslash( $_POST['speed'] ) ) : '',
			'9'  => isset( $_POST['ceiling'] ) ? sanitize_text_field( wp_unslash( $_POST['ceiling'] ) ) : '',
			'10' => isset( $_POST['light'] ) ? sanitize_text_field( wp_unslash( $_POST['light'] ) ) : '',
		);

		if ( '' === $fan['1'] ) {
			wp_send_json_error( array( 'message' => 'Fan range required' ), 400 );
		}

		// Do not array_merge(). Numeric field IDs would be renumbered
		// (range, size, and the other values land on the wrong fields).
		$entry             = $fan;
		$entry['form_id']  = self::CHILD_FORM_ID;

		if ( $entry_id ) {
			$entry['id'] = $entry_id;
			$result      = GFAPI::update_entry( $entry );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			}
		} else {
			$result = GFAPI::add_entry( $entry );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			}
			$entry_id = (int) $result;
		}

		$parent_key = $session ? $session : '0';
		gform_update_meta( $entry_id, 'gpnf_entry_parent', $parent_key );
		gform_update_meta( $entry_id, 'gpnf_entry_parent_form', self::PARENT_FORM_ID );
		gform_update_meta( $entry_id, 'gpnf_entry_nested_form_field', self::NESTED_FIELD_ID );

		wp_send_json_success( array( 'entry_id' => $entry_id ) );
	}

	public static function ajax_delete() {
		check_ajax_referer( 'oa_tfp_fan_cards', 'nonce' );

		if ( ! class_exists( 'GFAPI' ) ) {
			wp_send_json_error( array( 'message' => 'Gravity Forms missing' ), 500 );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			wp_send_json_success();
		}

		$result = GFAPI::delete_entry( $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success();
	}

	/**
	 * Sizes, finishes, speed, and light for one range, in a single request.
	 */
	public static function ajax_fan_options() {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$empty      = array(
			'sizes'  => array(),
			'timber' => array(),
			'metal'  => array(),
			'speed'  => array(),
			'light'  => array(),
		);
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			wp_send_json( $empty );
		}

		$cache_key = 'oa_tfp_fan_opts_' . self::VERSION . '_' . $product_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			wp_send_json( $cached );
		}

		$out = self::fan_options_for_product( $product_id );
		set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );
		wp_send_json( $out );
	}

	/**
	 * Options for every fan range, sent with the quote page so a selection does not wait on AJAX.
	 *
	 * @param array $ranges Range choices.
	 * @return array
	 */
	private static function availability_map( $ranges ) {
		$cache_key = 'oa_tfp_all_fan_opts_' . self::VERSION;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = array();
		foreach ( (array) $ranges as $range ) {
			$id = isset( $range['value'] ) ? absint( $range['value'] ) : 0;
			if ( ! $id ) {
				continue;
			}
			$map[ (string) $id ] = self::fan_options_for_product( $id );
		}

		set_transient( $cache_key, $map, 12 * HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private static function fan_options_for_product( $product_id ) {
		$empty = array(
			'sizes'  => array(),
			'timber' => array(),
			'metal'  => array(),
			'speed'  => array(),
			'light'  => array(),
		);
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $empty;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $empty;
		}

		$variation  = $product->is_type( 'variable' ) ? $product->get_variation_attributes() : array();
		$attributes = $product->get_attributes();

		return array(
			'sizes'  => self::slugs_from( $variation, $attributes, array( 'pa_fan-size', 'pa_size', 'fan-size', 'size' ) ),
			'timber' => self::slugs_from( $variation, $attributes, array( 'pa_timber-finish', 'timber-finish' ) ),
			'metal'  => self::slugs_from( $variation, $attributes, array( 'pa_metal-finish', 'metal-finish' ) ),
			'speed'  => self::slugs_from( $variation, $attributes, array( 'pa_speed-regulator', 'speed-regulator' ) ),
			'light'  => self::slugs_from( $variation, $attributes, array( 'pa_light', 'light' ) ),
		);
	}

	/**
	 * @param array $variation  Variation attribute map.
	 * @param array $attributes Product attributes.
	 * @param array $names      Attribute keys to try.
	 * @return array
	 */
	private static function slugs_from( $variation, $attributes, $names ) {
		$found = array();
		foreach ( $names as $name ) {
			if ( ! empty( $variation[ $name ] ) && is_array( $variation[ $name ] ) ) {
				$found = array_merge( $found, $variation[ $name ] );
			}
		}
		if ( $found ) {
			return array_values( array_unique( array_filter( array_map( 'strval', $found ) ) ) );
		}

		foreach ( $names as $name ) {
			if ( ! isset( $attributes[ $name ] ) || ! is_object( $attributes[ $name ] ) ) {
				continue;
			}
			$attr = $attributes[ $name ];
			if ( ! method_exists( $attr, 'get_options' ) ) {
				continue;
			}
			foreach ( (array) $attr->get_options() as $option ) {
				if ( method_exists( $attr, 'is_taxonomy' ) && $attr->is_taxonomy() && is_numeric( $option ) ) {
					$term = get_term( (int) $option, $attr->get_name() );
					if ( $term && ! is_wp_error( $term ) ) {
						$found[] = (string) $term->slug;
					}
					continue;
				}
				$found[] = (string) $option;
			}
		}

		return array_values( array_unique( array_filter( $found ) ) );
	}

	/**
	 * Return pa_light slugs for a product (empty = Light UI hidden).
	 */
	public static function ajax_lights() {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			wp_send_json( array() );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json( array() );
		}

		$slugs = array();

		if ( function_exists( 'wc_get_product_terms' ) ) {
			$terms = wc_get_product_terms( $product_id, 'pa_light', array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$slugs = array_values( array_map( 'strval', $terms ) );
			}
		}

		if ( empty( $slugs ) ) {
			$attributes = $product->get_attributes();
			if ( isset( $attributes['pa_light'] ) ) {
				$attr = $attributes['pa_light'];
				if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
					$options = $attr->get_options();
					if ( is_array( $options ) ) {
						foreach ( $options as $option ) {
							if ( is_numeric( $option ) && function_exists( 'get_term_by' ) ) {
								$term = get_term_by( 'id', (int) $option, 'pa_light' );
								if ( $term && ! is_wp_error( $term ) ) {
									$slugs[] = (string) $term->slug;
									continue;
								}
							}
							$slugs[] = (string) $option;
						}
					}
				}
			}
		}

		if ( empty( $slugs ) && $product->is_type( 'variable' ) ) {
			$variation_attrs = $product->get_variation_attributes();
			if ( ! empty( $variation_attrs['pa_light'] ) && is_array( $variation_attrs['pa_light'] ) ) {
				$slugs = array_values( array_map( 'strval', $variation_attrs['pa_light'] ) );
			}
		}

		$slugs = array_values( array_unique( array_filter( $slugs ) ) );
		wp_send_json( $slugs );
	}

	private static function css() {
		return <<<'CSS'
/* Hide all Nested Forms UI; our cards replace it. */
#field_1_10 .gpnf-nested-entries,
#field_1_10 .gpnf-nested-entries-container,
#field_1_10 .gpnf-add-entry-wrap,
#field_1_10 .gpnf-add-entry,
#field_1_10 button.gpnf-add-entry,
#gform_1 #field_1_10 .gpnf-add-entry,
#field_1_10 .gpnf-empty-message,
#field_1_10 table,
#field_1_10 .tingle-modal,
#field_1_10 .gpnf-modal,
body .tingle-modal.gpnf-modal-1-10,
body .tingle-modal.oa-tfp-inline-nested,
body .tingle-modal.gpnf-modal {
	display: none !important;
}
/* Legacy product-mods overlay; we use #oa-tfp-seed-loader instead. */
#oa-tfp-populating-overlay {
	display: none !important;
}
#oa-tfp-seed-loader {
	position: fixed;
	inset: 0;
	z-index: 100060;
	display: none;
	align-items: center;
	justify-content: center;
	background: rgba(255, 255, 255, 0.88);
}
#oa-tfp-seed-loader.is-visible {
	display: flex !important;
}
.oa-tfp-seed-loader__inner {
	text-align: center;
	color: #1e1c1b;
}
.oa-tfp-seed-loader__spinner {
	width: 40px;
	height: 40px;
	margin: 0 auto 14px;
	border: 3px solid #e0e0e0;
	border-top-color: #1e1c1b;
	border-radius: 50%;
	animation: oa-tfp-spin 0.8s linear infinite;
}
.oa-tfp-seed-loader__text {
	font-size: 16px;
	font-weight: 600;
}
@keyframes oa-tfp-spin {
	to { transform: rotate(360deg); }
}
/* Hide site footer on quote page */
.site-footer,
.site-info,
.footer-widgets,
.footer-bar,
#footer-widgets,
#colophon {
	display: none !important;
}
#field_1_10 .ginput_container {
	position: relative;
	display: block !important;
}
#field_1_10 .oa-tfp-cards-host {
	display: block !important;
	width: 100%;
	margin: 10px 0 0;
}
.oa-tfp-cards {
	display: flex;
	flex-direction: column;
	gap: 14px;
	width: 100%;
}
.oa-tfp-card {
	background: #fff;
	border: 2px solid #e0e0e0;
	border-radius: 5px;
	padding: 20px;
	box-sizing: border-box;
	box-shadow: none !important;
}
.oa-tfp-card-top {
	display: flex !important;
	flex-direction: row !important;
	flex-wrap: nowrap;
	align-items: flex-start;
	gap: 16px;
	width: 100%;
}
.oa-tfp-card-top .oa-tfp-field-range {
	flex: 1 1 auto;
	min-width: 0;
}
.oa-tfp-card-top .oa-tfp-field-size {
	flex: 0 0 110px;
	width: 110px;
	max-width: 110px;
}
.oa-tfp-card-top .oa-tfp-field-qty {
	flex: 0 0 auto;
	width: auto;
	max-width: none;
}
@media (max-width: 640px) {
	.oa-tfp-card-top {
		flex-wrap: wrap;
	}
	.oa-tfp-card-top .oa-tfp-field-range,
	.oa-tfp-card-top .oa-tfp-field-size {
		flex: 1 1 100%;
	}
}
.oa-tfp-field label,
.oa-tfp-finish-block label {
	display: block;
	font-size: 13px;
	font-weight: 600;
	font-family: inherit;
	color: #1e1c1b;
	margin: 0 0 8px;
	letter-spacing: normal;
	text-transform: none;
}
.oa-tfp-field select,
.oa-tfp-field input[type="text"],
.oa-tfp-cards-host select,
.oa-tfp-cards-host input[type="text"] {
	width: 100%;
	height: 46px !important;
	min-height: 46px !important;
	max-height: 46px !important;
	border: 2px solid #e0e0e0 !important;
	border-radius: 5px !important;
	padding: 0 12px !important;
	background-color: #fff !important;
	background-image: none !important;
	background-repeat: no-repeat !important;
	box-shadow: none !important;
	outline: none !important;
	color: #1e1c1b !important;
	font-size: 14px !important;
	font-weight: 400 !important;
	line-height: 46px !important;
	vertical-align: middle;
	box-sizing: border-box !important;
	-webkit-appearance: none !important;
	appearance: none !important;
}
.oa-tfp-field select:hover,
.oa-tfp-field select:focus,
.oa-tfp-field select:active,
.oa-tfp-field input[type="text"]:hover,
.oa-tfp-field input[type="text"]:focus,
.oa-tfp-cards-host select:hover,
.oa-tfp-cards-host select:focus,
.oa-tfp-cards-host select:active,
.oa-tfp-cards-host input[type="text"]:hover,
.oa-tfp-cards-host input[type="text"]:focus {
	background-color: #fff !important;
	background-image: none !important;
	box-shadow: none !important;
	border-color: #1e1c1b !important;
}
.oa-tfp-field select {
	padding: 0 36px 0 12px !important;
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%231e1c1b' d='M1 1l5 5 5-5'/%3E%3C/svg%3E") !important;
	background-repeat: no-repeat !important;
	background-position: right 12px center !important;
	background-size: 12px 8px !important;
}
.oa-tfp-field select:hover,
.oa-tfp-field select:focus,
.oa-tfp-field select:active {
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%231e1c1b' d='M1 1l5 5 5-5'/%3E%3C/svg%3E") !important;
	background-repeat: no-repeat !important;
	background-position: right 12px center !important;
	background-size: 12px 8px !important;
}
.oa-tfp-hint {
	margin: 8px 0 0;
	font-size: 12px;
	line-height: 1.35;
	color: #8a837a;
}
/* Match product-page WooCommerce quantity control */
.oa-tfp-qty {
	display: inline-flex !important;
	align-items: center !important;
	border-radius: 5px !important;
	overflow: hidden;
	height: 44px;
	min-height: 40px;
	border: 2px solid #e0e0e0 !important;
	background: #f7f6f5 !important;
	box-sizing: border-box;
	box-shadow: none !important;
}
.oa-tfp-qty button,
.oa-tfp-qty .oa-tfp-qty-minus,
.oa-tfp-qty .oa-tfp-qty-plus {
	display: inline-flex !important;
	align-items: center !important;
	justify-content: center !important;
	width: 30px !important;
	min-width: 30px !important;
	height: 100% !important;
	min-height: 40px !important;
	border: none !important;
	border-radius: 0 !important;
	background: transparent !important;
	background-color: transparent !important;
	color: inherit !important;
	font-size: 16px !important;
	font-weight: 400 !important;
	line-height: 1 !important;
	padding: 0 !important;
	margin: 0 !important;
	cursor: pointer;
	box-shadow: none !important;
	text-shadow: none !important;
}
.oa-tfp-qty button:hover,
.oa-tfp-qty .oa-tfp-qty-minus:hover,
.oa-tfp-qty .oa-tfp-qty-plus:hover {
	background: rgba(0, 0, 0, 0.04) !important;
	color: inherit !important;
}
.oa-tfp-qty input,
.oa-tfp-qty input[type="text"],
.oa-tfp-qty .oa-tfp-qty-input {
	width: 30px !important;
	min-width: 30px !important;
	max-width: 40px !important;
	height: 100% !important;
	min-height: 40px !important;
	max-height: none !important;
	border: none !important;
	border-radius: 0 !important;
	text-align: center !important;
	font-size: 14px !important;
	font-weight: 400 !important;
	line-height: 40px !important;
	padding: 0 !important;
	margin: 0 !important;
	background: transparent !important;
	background-color: transparent !important;
	color: inherit !important;
	box-shadow: none !important;
	-moz-appearance: textfield;
}
.oa-tfp-qty input::-webkit-outer-spin-button,
.oa-tfp-qty input::-webkit-inner-spin-button {
	-webkit-appearance: none;
	margin: 0;
}
.oa-tfp-finishes {
	margin-top: 18px;
	padding-top: 16px;
	border-top: 1px solid #ece7e1;
	display: none;
}
.oa-tfp-card.is-configured .oa-tfp-finishes {
	display: block;
}
.oa-tfp-finish-block {
	margin-bottom: 14px;
}
.oa-tfp-field-ceiling {
	max-width: 140px;
}
.oa-tfp-field-ceiling .oa-tfp-ceiling {
	width: 100%;
	max-width: 140px;
}
.oa-tfp-last-row {
	display: flex;
	flex-wrap: nowrap;
	align-items: flex-end;
	justify-content: space-between;
	gap: 12px 16px;
	margin-top: 4px;
	width: 100%;
}
.oa-tfp-last-row .oa-tfp-field-ceiling {
	flex: 0 0 auto;
	margin: 0;
}
.oa-tfp-last-row__actions {
	display: inline-flex;
	align-items: center;
	justify-content: flex-end;
	gap: 12px;
	flex: 0 0 auto;
	margin-left: auto;
}
.oa-tfp-last-row__actions .oa-tfp-confirm {
	margin: 0;
}
.oa-tfp-last-row__actions .oa-tfp-remove {
	margin: 0;
}
.oa-tfp-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
#field_1_10 .oa-tfp-chip,
.oa-tfp-cards .oa-tfp-chip {
	appearance: none !important;
	-webkit-appearance: none !important;
	display: inline-flex !important;
	align-items: center;
	gap: 8px;
	border: 2px solid #e0e0e0 !important;
	background: #fff !important;
	background-color: #fff !important;
	border-radius: 5px !important;
	padding: 8px 12px !important;
	font-size: 12px !important;
	font-weight: 500 !important;
	color: #1e1c1b !important;
	cursor: pointer;
	box-shadow: none !important;
	-webkit-box-shadow: none !important;
	text-shadow: none !important;
	outline: none !important;
	outline-offset: 0 !important;
}
#field_1_10 .oa-tfp-chip:hover,
.oa-tfp-cards .oa-tfp-chip:hover {
	background: #faf9f7 !important;
	background-color: #faf9f7 !important;
	color: #1e1c1b !important;
	border-color: #c8c8c8 !important;
	outline: none !important;
	box-shadow: none !important;
}
#field_1_10 .oa-tfp-chip:focus,
#field_1_10 .oa-tfp-chip:focus-visible,
#field_1_10 .oa-tfp-chip:active,
.oa-tfp-cards .oa-tfp-chip:focus,
.oa-tfp-cards .oa-tfp-chip:focus-visible,
.oa-tfp-cards .oa-tfp-chip:active {
	outline: none !important;
	outline-offset: 0 !important;
	box-shadow: none !important;
	-webkit-box-shadow: none !important;
}
#field_1_10 .oa-tfp-chip img,
.oa-tfp-cards .oa-tfp-chip img {
	width: 22px;
	height: 22px;
	border-radius: 5px;
	object-fit: cover;
	flex: 0 0 22px;
	display: block;
	background: #eee;
	border: 0 !important;
	box-shadow: none !important;
}
#field_1_10 .oa-tfp-chip.is-light-swatch img,
.oa-tfp-cards .oa-tfp-chip.is-light-swatch img {
	border: 1px solid #c8c8c8 !important;
}
#field_1_10 .oa-tfp-chip.is-selected,
#field_1_10 .oa-tfp-chip.is-selected:hover,
#field_1_10 .oa-tfp-chip.is-selected:focus,
#field_1_10 .oa-tfp-chip.is-selected:focus-visible,
.oa-tfp-cards .oa-tfp-chip.is-selected,
.oa-tfp-cards .oa-tfp-chip.is-selected:hover,
.oa-tfp-cards .oa-tfp-chip.is-selected:focus,
.oa-tfp-cards .oa-tfp-chip.is-selected:focus-visible {
	border: 2px solid #1e1c1b !important;
	background: #fff !important;
	background-color: #fff !important;
	color: #1e1c1b !important;
	box-shadow: none !important;
	-webkit-box-shadow: none !important;
	outline: none !important;
	outline-offset: 0 !important;
}
#field_1_10 .oa-tfp-chip span,
.oa-tfp-cards .oa-tfp-chip span {
	color: #1e1c1b !important;
}
.oa-tfp-remove,
.oa-tfp-edit {
	border: 0;
	background: transparent;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	padding: 0;
}
.oa-tfp-remove {
	color: #c0392b;
}
.oa-tfp-edit {
	color: #1e1c1b;
	text-decoration: underline;
	text-underline-offset: 2px;
	white-space: nowrap;
}
.oa-tfp-confirm {
	appearance: none !important;
	-webkit-appearance: none !important;
	display: inline-flex !important;
	align-items: center;
	justify-content: center;
	height: 46px !important;
	min-height: 46px !important;
	max-height: 46px !important;
	padding: 0 16px !important;
	border: 0 !important;
	border-radius: 5px !important;
	background: #1e1c1b !important;
	color: #fff !important;
	font-size: 13px !important;
	font-weight: 700 !important;
	letter-spacing: 0.03em;
	line-height: 1 !important;
	box-sizing: border-box !important;
	cursor: pointer;
	box-shadow: none !important;
	white-space: nowrap;
}
.oa-tfp-confirm:hover {
	background: #000 !important;
	color: #fff !important;
}
.oa-tfp-confirm:disabled {
	opacity: 0.55;
	cursor: not-allowed;
}
.oa-tfp-summary {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.oa-tfp-summary__title {
	margin: 0;
	font-size: 15px;
	font-weight: 700;
	color: #1e1c1b;
	line-height: 1.3;
}
.oa-tfp-summary__details {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 14px;
}
.oa-tfp-summary__meta {
	margin: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 6px 10px;
	font-size: 13px;
	line-height: 1.4;
	color: #5c574f;
	flex: 1 1 auto;
	min-width: 0;
}
.oa-tfp-summary__meta span {
	display: inline-flex;
	align-items: center;
}
.oa-tfp-summary__meta span:not(:last-child)::after {
	content: "·";
	margin-left: 10px;
	color: #c8c8c8;
}
.oa-tfp-summary__actions {
	display: inline-flex;
	align-items: center;
	gap: 12px;
	flex: 0 0 auto;
	margin-left: auto;
}
#field_1_10 .oa-tfp-add,
.oa-tfp-cards-host .oa-tfp-add {
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
	width: 100% !important;
	max-width: 100% !important;
	box-sizing: border-box !important;
	min-height: 48px;
	margin: 24px 0 0 !important;
	padding: 12px 18px !important;
	border: 2px dashed #1e1c1b !important;
	border-radius: 5px !important;
	background: #fff !important;
	color: #1e1c1b !important;
	font-weight: 600 !important;
	font-size: 14px !important;
	cursor: pointer;
	box-shadow: none !important;
	float: none !important;
}
#field_1_10 .oa-tfp-add:hover,
.oa-tfp-cards-host .oa-tfp-add:hover {
	border: 2px dashed #1e1c1b !important;
	background: #faf9f7 !important;
	color: #1e1c1b !important;
}
.oa-tfp-status {
	min-height: 0;
	margin-top: 0;
	font-size: 12px;
	color: #7a746c;
}
.oa-tfp-status:not(:empty) {
	min-height: 18px;
	margin-top: 6px;
}

/* 5px radius across quote form fields (exclude radio/checkbox) */
#gform_1 input[type="text"],
#gform_1 input[type="email"],
#gform_1 input[type="tel"],
#gform_1 input[type="url"],
#gform_1 input[type="number"],
#gform_1 input[type="password"],
#gform_1 textarea,
#gform_1 select,
#gform_1 .ginput_container textarea,
#gform_1 .ginput_container select,
#gform_1 .gform_button,
#gform_1 input[type="submit"],
#gform_1 .button {
	border-radius: 5px !important;
}

/* Native choice shapes: round radios, square multi-select */
#gform_1 input[type="radio"],
#gform_1 .gfield-choice-input[type="radio"] {
	border-radius: 50% !important;
}
#gform_1 input[type="checkbox"],
#gform_1 .gfield-choice-input[type="checkbox"] {
	border-radius: 0 !important;
}
#gform_1 .gfield--type-radio .gform-field-label--type-inline::before,
#gform_1 .gfield_radio .gform-field-label::before,
#gform_1 .gfield_radio label::before {
	border-radius: 50% !important;
}
#gform_1 .gfield--type-checkbox .gform-field-label--type-inline::before,
#gform_1 .gfield_checkbox .gform-field-label::before,
#gform_1 .gfield_checkbox label::before {
	border-radius: 0 !important;
}

/* Remove GF/theme drop shadows on quote form fields */
#gform_wrapper_1,
#gform_1,
.gform_wrapper {
	--gf-ctrl-shadow: none !important;
	--gf-ctrl-btn-shadow: none !important;
	--gf-ctrl-btn-shadow-hover: none !important;
	--gf-ctrl-btn-shadow-focus: none !important;
	--gf-ctrl-btn-shadow-disabled: none !important;
	--gf-local-shadow: none !important;
	--gf-form-validation-shadow: none !important;
	--gf-field-img-choice-shadow: none !important;
	--gf-field-img-choice-shadow-hover: none !important;
	--gf-ctrl-select-dropdown-shadow: none !important;
	--gf-ctrl-date-picker-shadow: none !important;
}
#gform_wrapper_1 input,
#gform_wrapper_1 textarea,
#gform_wrapper_1 select,
#gform_wrapper_1 .gform-theme-button,
#gform_wrapper_1 .button,
#gform_wrapper_1 .gfield,
#gform_wrapper_1 .ginput_container,
#gform_wrapper_1 .ginput_container > *,
#gform_1 input,
#gform_1 textarea,
#gform_1 select,
#gform_1 .ginput_container input,
#gform_1 .ginput_container textarea,
#gform_1 .ginput_container select,
#gform_1 input:hover,
#gform_1 input:focus,
#gform_1 input:focus-visible,
#gform_1 textarea:hover,
#gform_1 textarea:focus,
#gform_1 textarea:focus-visible,
#gform_1 select:hover,
#gform_1 select:focus,
#gform_1 select:focus-visible,
#gform_1 .gfield,
#field_1_10,
#field_1_10 .oa-tfp-card,
#field_1_10 .oa-tfp-chip,
#field_1_10 .oa-tfp-qty,
#field_1_10 .oa-tfp-add {
	box-shadow: none !important;
	-webkit-box-shadow: none !important;
	text-shadow: none !important;
	filter: none !important;
}

/* Hide native GF submit; floating bar replaces it */
#gform_1 .gform_footer,
#gform_wrapper_1 .gform_footer {
	position: absolute !important;
	width: 1px !important;
	height: 1px !important;
	padding: 0 !important;
	margin: -1px !important;
	overflow: hidden !important;
	clip: rect(0, 0, 0, 0) !important;
	border: 0 !important;
}

/* Floating submit bar */
body.oa-tfp-has-float-submit {
	padding-bottom: 88px !important;
}
.oa-tfp-float-submit {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 10050;
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 14px;
	padding: 14px 20px;
	padding-bottom: calc(14px + env(safe-area-inset-bottom, 0px));
	background: #f7f6f5;
	border-top: 2px solid #e0e0e0;
	box-shadow: none;
}
.oa-tfp-float-submit__summary {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
	min-width: 0;
	text-align: right;
	color: #1e1c1b;
	font-size: 14px;
	line-height: 1.3;
}
.oa-tfp-float-submit__summary strong {
	font-weight: 700;
}
.oa-tfp-float-submit__total {
	font-size: 13px;
	color: #5c574f;
}
.oa-tfp-float-submit__btn {
	appearance: none !important;
	-webkit-appearance: none !important;
	flex: 0 0 auto;
	display: inline-flex !important;
	align-items: center;
	justify-content: center;
	min-height: 48px;
	padding: 12px 22px !important;
	border: 0 !important;
	border-radius: 5px !important;
	background: #1e1c1b !important;
	color: #fff !important;
	font-size: 13px !important;
	font-weight: 700 !important;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	cursor: pointer;
	box-shadow: none !important;
}
.oa-tfp-float-submit__btn:hover {
	background: #000 !important;
	color: #fff !important;
}
@media (max-width: 640px) {
	.oa-tfp-float-submit {
		padding-left: 14px;
		padding-right: 14px;
		gap: 10px;
	}
	.oa-tfp-float-submit__btn {
		min-width: 132px;
		padding-left: 16px !important;
		padding-right: 16px !important;
	}
	/* Sit WhatsApp nearer the bottom edge on mobile */
	.wHelp {
		bottom: 12px !important;
	}
}
CSS;
	}

	private static function js() {
		return <<<'JS'
(function ($) {
	'use strict';

	var CFG = window.OA_TFP_FAN_CARDS || {};
	var FIELD = '#field_1_10';
	var cards = [];
	var syncing = false;
	var quoteSeed = null;
	var quoteSeedApplied = false;
	var availCache = {};

	// Capture product-page seed immediately so legacy GF populate code cannot clear it first.
	try {
		var rawSeed = window.localStorage ? localStorage.getItem('timberfans_quote_data') : null;
		if (rawSeed) {
			quoteSeed = JSON.parse(rawSeed);
			localStorage.removeItem('timberfans_quote_data');
		}
	} catch (e) {
		quoteSeed = null;
	}

	function opts(key) {
		return (CFG.options && CFG.options[key]) || [];
	}

	function matchOption(optKey, candidates) {
		var list = opts(optKey);
		var cand = (candidates || []).filter(function (c) { return c !== undefined && c !== null && String(c) !== ''; }).map(String);
		var i, c, hit, slug;
		for (i = 0; i < cand.length; i++) {
			c = cand[i];
			hit = list.filter(function (item) { return String(item.value) === c; })[0];
			if (hit) return hit.value;
			hit = list.filter(function (item) { return String(item.value).toLowerCase() === c.toLowerCase(); })[0];
			if (hit) return hit.value;
			hit = list.filter(function (item) { return String(item.label).toLowerCase() === c.toLowerCase(); })[0];
			if (hit) return hit.value;
			slug = c.toLowerCase().replace(/\s+/g, '-');
			hit = list.filter(function (item) {
				return String(item.value).toLowerCase() === slug ||
					String(item.label).toLowerCase().replace(/\s+/g, '-') === slug;
			})[0];
			if (hit) return hit.value;
			hit = list.filter(function (item) {
				return String(item.label).toLowerCase().indexOf(c.toLowerCase()) !== -1 ||
					String(item.value).toLowerCase().indexOf(c.toLowerCase()) !== -1;
			})[0];
			if (hit) return hit.value;
		}
		return '';
	}

	function filterList(optKey, availableValues) {
		var all = opts(optKey);
		if (!Array.isArray(availableValues)) {
			return [];
		}
		var set = availableValues.map(String);
		return all.filter(function (item) {
			return set.indexOf(String(item.value)) !== -1;
		});
	}

	function normalizeAvail(data) {
		var out = { sizes: [], timber: [], metal: [], speed: [], light: [] };
		['sizes', 'timber', 'metal', 'speed', 'light'].forEach(function (key) {
			out[key] = (data && Array.isArray(data[key])) ? data[key].map(String) : [];
		});
		return out;
	}

	function peekAvailability(productId) {
		var key = String(productId);
		if (availCache[key]) {
			return availCache[key];
		}
		if (CFG.availability && CFG.availability[key]) {
			availCache[key] = normalizeAvail(CFG.availability[key]);
			return availCache[key];
		}
		return null;
	}

	function fetchAvailability(productId) {
		var ready = peekAvailability(productId);
		if (ready) {
			return $.Deferred().resolve(ready).promise();
		}
		return $.post(CFG.ajaxUrl, { action: 'oa_tfp_get_fan_options', product_id: productId }).then(function (data) {
			var out = normalizeAvail(data);
			availCache[String(productId)] = out;
			return out;
		}, function () {
			return { sizes: [], timber: [], metal: [], speed: [], light: [] };
		});
	}

	function lightOptionsFor(avail) {
		// Form choice values (e.g. "With LED") do not match WC slugs (with-led).
		// If the product has any pa_light terms, show the form Light choices; otherwise hide.
		if (!avail || !Array.isArray(avail.light) || !avail.light.length) {
			return [];
		}
		return opts('light');
	}

	function sessionHash() {
		var $el = $('#gpnf_session_hash_1, input[name="gpnf_session_hash"]').first();
		return $el.length ? String($el.val() || '') : '';
	}

	function hiddenInput() {
		return $('#input_1_10');
	}

	function writeEntryIds() {
		var ids = cards
			.filter(function (c) { return c.confirmed && c.entryId; })
			.map(function (c) { return c.entryId; });
		hiddenInput().val(ids.join(','));
		hiddenInput().trigger('change');
	}

	function optionHtml(list, placeholder) {
		var html = '<option value="">' + placeholder + '</option>';
		list.forEach(function (item) {
			html += '<option value="' + escapeAttr(item.value) + '">' + escapeHtml(item.label) + '</option>';
		});
		return html;
	}

	function isLightSwatch(item) {
		var label = String(item.label || '').toLowerCase().trim();
		var value = String(item.value || '').toLowerCase().trim().replace(/\s+/g, '-');
		return label === 'white' || label === 'satin white' ||
			value === 'white' || value === 'satin-white';
	}

	function chipsHtml(list, name) {
		if (!list.length) {
			return '<p class="oa-tfp-hint">No options for this range.</p>';
		}
		return list.map(function (item) {
			var icon = item.image
				? '<img src="' + escapeAttr(item.image) + '" alt="" width="22" height="22" loading="lazy" />'
				: '';
			var extra = (item.image ? ' has-icon' : '') + (isLightSwatch(item) ? ' is-light-swatch' : '');
			return '<button type="button" class="oa-tfp-chip' + extra + '" data-name="' + name + '" data-value="' + escapeAttr(item.value) + '">' + icon + '<span>' + escapeHtml(item.label) + '</span></button>';
		}).join('');
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escapeAttr(str) {
		return escapeHtml(str).replace(/'/g, '&#39;');
	}

	function uid() {
		return 'c' + Math.random().toString(36).slice(2, 9);
	}

	function createCardModel(seed) {
		seed = seed || {};
		return {
			id: uid(),
			entryId: seed.entryId || 0,
			range: seed.range || '',
			size: seed.size || '',
			qty: seed.qty || '1',
			timber: seed.timber || '',
			metal: seed.metal || '',
			speed: seed.speed || '',
			ceiling: seed.ceiling || '',
			light: seed.light || '',
			available: seed.available || null,
			loading: false,
			dirty: false,
			confirmed: !!seed.confirmed
		};
	}

	function optionLabel(optKey, value) {
		if (!value) return '';
		var hit = opts(optKey).filter(function (item) { return String(item.value) === String(value); })[0];
		return hit ? hit.label : String(value);
	}

	function cardIncomplete(model) {
		if (!model.range) return 'Choose a fan range.';
		if (!model.available) return 'Wait for options to finish loading.';
		if (!model.size) return 'Select a size.';
		if (!model.timber) return 'Select a timber finish.';
		if (!model.metal) return 'Select a metal finish.';
		if (filterList('speed', model.available.speed).length && !model.speed) return 'Select a speed control.';
		if (lightOptionsFor(model.available).length && !model.light) return 'Select a light option.';
		if (!model.qty) return 'Enter a quantity.';
		return '';
	}

	function summaryBits(model) {
		var bits = [];
		if (model.size) bits.push(escapeHtml(optionLabel('sizes', model.size)));
		bits.push('Qty ' + escapeHtml(model.qty || '1'));
		if (model.timber) bits.push(escapeHtml(optionLabel('timber', model.timber)));
		if (model.metal) bits.push(escapeHtml(optionLabel('metal', model.metal)));
		if (model.speed) bits.push(escapeHtml(optionLabel('speed', model.speed)));
		if (model.light) bits.push(escapeHtml(optionLabel('light', model.light)));
		if (model.ceiling) bits.push('Ceiling ' + escapeHtml(model.ceiling) + ' m');
		return bits;
	}

	function renderSummary(model, index) {
		var bits = summaryBits(model);
		var showRemove = cards.length > 1;
		return (
			'<div class="oa-tfp-card is-configured is-summary" data-card="' + model.id + '">' +
				'<div class="oa-tfp-summary">' +
					'<p class="oa-tfp-summary__title">Fan ' + (index + 1) + ' · ' + escapeHtml(optionLabel('ranges', model.range)) + '</p>' +
					'<div class="oa-tfp-summary__details">' +
						(bits.length ? '<p class="oa-tfp-summary__meta">' + bits.map(function (b) { return '<span>' + b + '</span>'; }).join('') + '</p>' : '<p class="oa-tfp-summary__meta"></p>') +
						'<div class="oa-tfp-summary__actions">' +
							'<button type="button" class="oa-tfp-edit">Edit</button>' +
							(showRemove ? '<button type="button" class="oa-tfp-remove">Remove</button>' : '') +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div class="oa-tfp-status"></div>' +
			'</div>'
		);
	}

	function renderCard(model, index) {
		if (model.confirmed && model.range) {
			return renderSummary(model, index);
		}
		var showRemove = cards.length > 1;
		var configured = !!model.range;
		var avail = model.available;
		var sizeList = configured ? filterList('sizes', avail && avail.sizes) : [];
		var timberList = configured ? filterList('timber', avail && avail.timber) : [];
		var metalList = configured ? filterList('metal', avail && avail.metal) : [];
		var speedList = configured ? filterList('speed', avail && avail.speed) : [];
		var lightList = configured ? lightOptionsFor(avail) : [];
		var ready = !!(configured && avail);
		var finishesHtml = '';
		if (configured && !avail) {
			finishesHtml = '<p class="oa-tfp-hint">Loading options for this range…</p>';
		} else if (ready) {
			finishesHtml =
				'<div class="oa-tfp-finish-block">' +
					'<label>Timber finish</label>' +
					'<div class="oa-tfp-chips oa-tfp-timber">' + chipsHtml(timberList, 'timber') + '</div>' +
				'</div>' +
				'<div class="oa-tfp-finish-block">' +
					'<label>Metal finish</label>' +
					'<div class="oa-tfp-chips oa-tfp-metal">' + chipsHtml(metalList, 'metal') + '</div>' +
				'</div>' +
				'<div class="oa-tfp-finish-block">' +
					'<label>Speed control</label>' +
					'<div class="oa-tfp-chips oa-tfp-speed">' + chipsHtml(speedList, 'speed') + '</div>' +
				'</div>' +
				(lightList.length ? (
					'<div class="oa-tfp-finish-block">' +
						'<label>Light</label>' +
						'<div class="oa-tfp-chips oa-tfp-light">' + chipsHtml(lightList, 'light') + '</div>' +
					'</div>'
				) : '') +
				'<div class="oa-tfp-last-row">' +
					'<div class="oa-tfp-field oa-tfp-field-ceiling">' +
						'<label>Ceiling height (meters)</label>' +
						'<input type="text" class="oa-tfp-ceiling" placeholder="e.g. 2.7" value="' + escapeAttr(model.ceiling) + '" />' +
					'</div>' +
					'<div class="oa-tfp-last-row__actions">' +
						(showRemove ? '<button type="button" class="oa-tfp-remove">Remove</button>' : '') +
						'<button type="button" class="oa-tfp-confirm">' + (model.entryId ? 'Save fan' : 'Add Entry') + '</button>' +
					'</div>' +
				'</div>';
		}
		return (
			'<div class="oa-tfp-card' + (configured ? ' is-configured' : '') + '" data-card="' + model.id + '">' +
				'<div class="oa-tfp-card-top">' +
					'<div class="oa-tfp-field oa-tfp-field-range">' +
						'<label>Fan range</label>' +
						'<select class="oa-tfp-range">' + optionHtml(opts('ranges'), 'Choose a range') + '</select>' +
						(!configured ? '<p class="oa-tfp-hint">Choose a range and the finishes appear, with defaults already selected.</p>' : '') +
					'</div>' +
					'<div class="oa-tfp-field oa-tfp-field-size">' +
						'<label>Size</label>' +
						'<select class="oa-tfp-size">' + optionHtml(sizeList, '-') + '</select>' +
					'</div>' +
					'<div class="oa-tfp-field oa-tfp-field-qty">' +
						'<label>Qty</label>' +
						'<div class="oa-tfp-qty">' +
							'<button type="button" class="oa-tfp-qty-minus" aria-label="Decrease">−</button>' +
							'<input type="text" class="oa-tfp-qty-input" value="' + escapeAttr(model.qty) + '" inputmode="numeric" />' +
							'<button type="button" class="oa-tfp-qty-plus" aria-label="Increase">+</button>' +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div class="oa-tfp-finishes">' + finishesHtml + '</div>' +
				((!ready && showRemove) ? '<div class="oa-tfp-last-row"><button type="button" class="oa-tfp-remove">Remove</button></div>' : '') +
				'<div class="oa-tfp-status"></div>' +
			'</div>'
		);
	}

	function applyValues($card, model) {
		$card.find('.oa-tfp-range').val(model.range);
		$card.find('.oa-tfp-size').val(model.size);
		$card.find('.oa-tfp-qty-input').val(model.qty);
		$card.find('.oa-tfp-ceiling').val(model.ceiling);
		['timber', 'metal', 'speed', 'light'].forEach(function (name) {
			$card.find('.oa-tfp-chip[data-name="' + name + '"]').removeClass('is-selected');
			if (model[name]) {
				$card.find('.oa-tfp-chip[data-name="' + name + '"][data-value="' + model[name].replace(/"/g, '\\"') + '"]').addClass('is-selected');
			}
		});
	}

	function pickDefault(list, current) {
		if (current && list.some(function (item) { return String(item.value) === String(current); })) {
			return current;
		}
		return list[0] ? list[0].value : '';
	}

	function defaultFinishes(model) {
		var avail = model.available || {};
		model.size = pickDefault(filterList('sizes', avail.sizes), model.size);
		model.timber = pickDefault(filterList('timber', avail.timber), model.timber);
		model.metal = pickDefault(filterList('metal', avail.metal), model.metal);
		model.speed = pickDefault(filterList('speed', avail.speed), model.speed);
		var lightList = lightOptionsFor(avail);
		model.light = lightList.length ? pickDefault(lightList, model.light) : '';
		if (!model.qty) model.qty = '1';
	}

	function loadAvailability(model) {
		if (!model.range || model.loading || model.available) {
			return $.Deferred().resolve().promise();
		}
		var ready = peekAvailability(model.range);
		if (ready) {
			model.available = ready;
			model.loading = false;
			defaultFinishes(model);
			model.dirty = true;
			paint();
			return $.Deferred().resolve().promise();
		}
		model.loading = true;
		paint();
		return fetchAvailability(model.range).then(function (avail) {
			model.available = avail;
			model.loading = false;
			defaultFinishes(model);
			model.dirty = true;
			paint();
		});
	}

	function paint() {
		var $field = $(FIELD);
		if (!$field.length) return;
		$field.find('.gpnf-add-entry, .gpnf-add-entry-wrap, .gpnf-nested-entries, .gpnf-nested-entries-container, .gpnf-empty-message').hide();
		var $host = $field.children('.oa-tfp-cards-host');
		if (!$host.length) {
			$host = $('<div class="oa-tfp-cards-host" />');
			// Mount as direct child of the field so Nested Forms chrome can be hidden safely.
			$field.append($host);
		}
		var html = '<div class="oa-tfp-cards">';
		cards.forEach(function (card, i) { html += renderCard(card, i); });
		html += '</div><button type="button" class="oa-tfp-add">+ Add another fan</button>';
		$host.html(html);
		cards.forEach(function (card) {
			if (card.confirmed) return;
			var $card = $host.find('[data-card="' + card.id + '"]');
			applyValues($card, card);
		});
		updateFloatSubmit();
		setTimeout(function () {
			cards.forEach(function (card) {
				if (card.range && !card.available && !card.loading && !card.confirmed) {
					loadAvailability(card);
				}
			});
		}, 0);
	}

	function findModel(id) {
		for (var i = 0; i < cards.length; i++) {
			if (cards[i].id === id) return cards[i];
		}
		return null;
	}

	function setStatus($card, msg) {
		$card.find('.oa-tfp-status').text(msg || '');
	}

	function saveCard(model, $card) {
		if (!model.range) return $.Deferred().resolve().promise();
		if (model.available) {
			defaultFinishes(model);
		}
		if ($card && $card.length) {
			setStatus($card, 'Saving…');
		}
		return $.post(CFG.ajaxUrl, {
			action: 'oa_tfp_fan_save',
			nonce: CFG.nonce,
			entry_id: model.entryId || 0,
			session_hash: sessionHash(),
			range: model.range,
			size: model.size,
			qty: model.qty,
			timber: model.timber,
			metal: model.metal,
			speed: model.speed,
			ceiling: model.ceiling,
			light: model.light
		}).then(function (resp) {
			if (!resp || !resp.success) {
				if ($card && $card.length) {
					setStatus($card, (resp && resp.data && resp.data.message) || 'Could not save fan');
				}
				return $.Deferred().reject(resp).promise();
			}
			model.entryId = resp.data.entry_id;
			model.dirty = false;
			writeEntryIds();
			if ($card && $card.length) {
				setStatus($card, '');
			}
		}).fail(function () {
			if ($card && $card.length) {
				setStatus($card, 'Could not save fan');
			}
			return $.Deferred().reject().promise();
		});
	}

	function confirmCard(model, $card) {
		var err = cardIncomplete(model);
		if (err) {
			setStatus($card, err);
			return $.Deferred().reject().promise();
		}
		$card.find('.oa-tfp-confirm').prop('disabled', true);
		return saveCard(model, $card).then(function () {
			model.confirmed = true;
			model.dirty = false;
			paint();
		}).fail(function () {
			$card.find('.oa-tfp-confirm').prop('disabled', false);
		});
	}

	function deleteCard(model) {
		if (!model.entryId) return $.Deferred().resolve().promise();
		return $.post(CFG.ajaxUrl, {
			action: 'oa_tfp_fan_delete',
			nonce: CFG.nonce,
			entry_id: model.entryId
		});
	}

	function ensureOneCard() {
		if (!cards.length) {
			cards.push(createCardModel());
		}
	}

	function fanSummary() {
		var confirmed = cards.filter(function (c) { return c.confirmed && c.range; });
		var fanCount = confirmed.length;
		var totalQty = 0;
		confirmed.forEach(function (c) {
			totalQty += Math.max(1, parseInt(c.qty, 10) || 1);
		});
		return {
			fanCount: fanCount,
			totalQty: totalQty,
			fanLabel: fanCount === 1 ? '1 fan' : fanCount + ' fans',
			totalLabel: totalQty === 1 ? '1 in total' : totalQty + ' in total'
		};
	}

	function ensureFloatSubmit() {
		var $bar = $('.oa-tfp-float-submit');
		if (!$bar.length) {
			$bar = $(
				'<div class="oa-tfp-float-submit" role="region" aria-label="Submit quote">' +
					'<div class="oa-tfp-float-submit__summary">' +
						'<strong class="oa-tfp-float-submit__fans"></strong>' +
						'<span class="oa-tfp-float-submit__total"></span>' +
					'</div>' +
					'<button type="button" class="oa-tfp-float-submit__btn">Send request</button>' +
				'</div>'
			);
			$('body').append($bar);
			$bar.on('click', '.oa-tfp-float-submit__btn', function () {
				var $real = $('#gform_submit_button_1');
				if ($real.length) {
					$real.trigger('click');
				}
			});
		}
		$('body').addClass('oa-tfp-has-float-submit');
		updateFloatSubmit();
	}

	function updateFloatSubmit() {
		var $bar = $('.oa-tfp-float-submit');
		if (!$bar.length) return;
		var summary = fanSummary();
		$bar.find('.oa-tfp-float-submit__fans').text(summary.fanLabel);
		$bar.find('.oa-tfp-float-submit__total').text(summary.totalLabel);
	}

	function ensureFansChecked() {
		var $fans = $('#choice_1_14_1, #field_1_14 input[value="Fans"]');
		if ($fans.length && !$fans.is(':checked')) {
			$fans.prop('checked', true).trigger('change');
		}
	}

	function showSeedLoader(message) {
		var $el = $('#oa-tfp-seed-loader');
		if (!$el.length) {
			$el = $(
				'<div id="oa-tfp-seed-loader" aria-live="polite" aria-busy="true">' +
					'<div class="oa-tfp-seed-loader__inner">' +
						'<div class="oa-tfp-seed-loader__spinner" aria-hidden="true"></div>' +
						'<div class="oa-tfp-seed-loader__text"></div>' +
					'</div>' +
				'</div>'
			);
			$('body').append($el);
		}
		$el.find('.oa-tfp-seed-loader__text').text(message || 'Preparing your quote…');
		$el.addClass('is-visible');
	}

	function hideSeedLoader() {
		$('#oa-tfp-seed-loader').removeClass('is-visible').attr('aria-busy', 'false');
	}

	function applyQuoteSeedOnce() {
		if (quoteSeedApplied || !quoteSeed) {
			return;
		}
		showSeedLoader('Preparing your quote…');
		var data = quoteSeed;
		var range = matchOption('ranges', [data.fan_range, data.product_name]);
		if (!range && data.fan_range) {
			range = String(data.fan_range);
		}
		if (!range) {
			quoteSeedApplied = true;
			quoteSeed = null;
			hideSeedLoader();
			return;
		}

		quoteSeedApplied = true;
		ensureFansChecked();
		ensureOneCard();

		var model = cards[0];
		model.range = range;
		model.qty = String(data.quantity || model.qty || '1');
		model.size = matchOption('sizes', [data.fan_size, data['pa_fan-size'], data['pa_size']]) || String(data.fan_size || '');
		model.timber = matchOption('timber', [data.timber_finish, data['pa_timber-finish']]) || '';
		model.metal = matchOption('metal', [data.metal_finish, data['pa_metal-finish']]) || '';
		model.speed = matchOption('speed', [data.speed_regulator, data['pa_speed-regulator'], data['pa_speed-control']]) || '';
		model.available = null;
		model.loading = false;
		model.dirty = true;
		model.confirmed = false;
		quoteSeed = null;

		paint();
		loadAvailability(model).always(function () {
			hideSeedLoader();
		});
	}

	function bind() {
		var $field = $(FIELD);
		if (!$field.length || $field.data('oaCardsBound')) return;
		$field.data('oaCardsBound', true);

		$field.on('change', '.oa-tfp-range', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			model.range = $(this).val();
			model.size = '';
			model.timber = '';
			model.metal = '';
			model.speed = '';
			model.light = '';
			model.available = null;
			model.dirty = true;
			model.confirmed = false;
			if (model.range) {
				loadAvailability(model);
			} else {
				paint();
			}
		});

		$field.on('change', '.oa-tfp-size', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			model.size = $(this).val();
			model.dirty = true;
			setStatus($card, '');
		});

		$field.on('click', '.oa-tfp-qty-minus', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			var n = Math.max(1, parseInt(model.qty, 10) || 1) - 1;
			model.qty = String(Math.max(1, n));
			model.dirty = true;
			$card.find('.oa-tfp-qty-input').val(model.qty);
		});

		$field.on('click', '.oa-tfp-qty-plus', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			model.qty = String((parseInt(model.qty, 10) || 1) + 1);
			model.dirty = true;
			$card.find('.oa-tfp-qty-input').val(model.qty);
		});

		$field.on('change', '.oa-tfp-qty-input', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			var n = parseInt($(this).val(), 10);
			model.qty = String(isNaN(n) || n < 1 ? 1 : n);
			model.dirty = true;
			$(this).val(model.qty);
		});

		$field.on('click', '.oa-tfp-chip', function () {
			var $btn = $(this);
			var $card = $btn.closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			var name = $btn.data('name');
			model[name] = String($btn.data('value'));
			model.dirty = true;
			$card.find('.oa-tfp-chip[data-name="' + name + '"]').removeClass('is-selected');
			$btn.addClass('is-selected').trigger('blur');
			setStatus($card, '');
		});

		$field.on('change', '.oa-tfp-ceiling', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			model.ceiling = $(this).val();
			model.dirty = true;
		});

		$field.on('click', '.oa-tfp-confirm', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			confirmCard(model, $card);
		});

		$field.on('click', '.oa-tfp-edit', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model) return;
			model.confirmed = false;
			model.dirty = true;
			paint();
			if (model.range && !model.available) {
				loadAvailability(model);
			}
		});

		$field.on('click', '.oa-tfp-add', function () {
			var open = cards.filter(function (c) { return !c.confirmed; })[0];
			if (open) {
				var $open = $(FIELD).find('[data-card="' + open.id + '"]');
				if (open.range) {
					setStatus($open, 'Add Entry before starting another.');
					if ($open.length) {
						$('html, body').animate({ scrollTop: $open.offset().top - 80 }, 200);
					}
					return;
				}
				if ($open.length) {
					$('html, body').animate({ scrollTop: $open.offset().top - 80 }, 200);
				}
				return;
			}
			cards.push(createCardModel());
			paint();
		});

		$field.on('click', '.oa-tfp-remove', function () {
			var $card = $(this).closest('.oa-tfp-card');
			var model = findModel($card.data('card'));
			if (!model || cards.length < 2) return;
			deleteCard(model).always(function () {
				cards = cards.filter(function (c) { return c.id !== model.id; });
				writeEntryIds();
				paint();
			});
		});

		$(document).on('click', '#gform_submit_button_1', function (e) {
			if (syncing) return;
			var incomplete = cards.filter(function (c) { return !c.confirmed && c.range; });
			if (incomplete.length) {
				e.preventDefault();
				e.stopImmediatePropagation();
				var first = incomplete[0];
				var $first = $(FIELD).find('[data-card="' + first.id + '"]');
				setStatus($first, cardIncomplete(first) || 'Add Entry before sending your request.');
				if ($first.length) {
					$('html, body').animate({ scrollTop: $first.offset().top - 80 }, 200);
				}
				return;
			}
			var pending = cards.filter(function (c) { return c.confirmed && c.range && (!c.entryId || c.dirty); });
			writeEntryIds();
			if (!pending.length) return;
			e.preventDefault();
			e.stopImmediatePropagation();
			syncing = true;
			var $btn = $(this);
			var chain = $.Deferred().resolve();
			pending.forEach(function (model) {
				chain = chain.then(function () {
					return saveCard(model, $(FIELD).find('[data-card="' + model.id + '"]'));
				});
			});
			chain.always(function () {
				writeEntryIds();
				syncing = false;
				$btn.off('click.oaFanSync');
				if (typeof gform !== 'undefined' && gform.submission && typeof gform.submission.handleButtonClick === 'function') {
					gform.submission.handleButtonClick($btn.get(0));
				} else {
					$btn.get(0).click();
				}
			});
		});
	}

	function boot() {
		var $field = $(FIELD);
		if (!$field.length) return;
		ensureOneCard();
		bind();
		ensureFloatSubmit();
		paint();
		writeEntryIds();
		applyQuoteSeedOnce();
	}

	$(function () {
		// Show loader ASAP when arriving from a product page with quote data.
		if (quoteSeed && !quoteSeedApplied) {
			showSeedLoader('Preparing your quote…');
		}
		boot();
		// When Fans / looking-for checkboxes toggle nested field visibility, repaint.
		$(document).on('change', '#field_1_14 input, #field_1_11 input, #input_1_11 input', function () {
			setTimeout(boot, 50);
		});
	});

	$(document).on('gform_post_render', function (e, formId) {
		if (String(formId) === '1') {
			boot();
		}
	});
})(jQuery);
JS;
	}
}

OA_TFP_Quote_Fan_Cards::init();
