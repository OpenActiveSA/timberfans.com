<?php
/**
 * One-time seed for "Wooden Ceiling Fans for Covered Patios".
 * Runs on init until the post exists, then no-ops.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'gp_child_seed_patio_blog_post', 30 );

function gp_child_seed_patio_blog_post() {
	if ( is_admin() || wp_doing_ajax() || wp_installing() ) {
		return;
	}

	if ( get_option( 'gp_child_seeded_patio_blog' ) ) {
		return;
	}

	$existing = get_page_by_path( 'wooden-ceiling-fans-covered-patios', OBJECT, 'post' );
	if ( $existing ) {
		update_option( 'gp_child_seeded_patio_blog', (int) $existing->ID, false );
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$author_id = $admins ? (int) $admins[0] : 1;

	$cat = get_term_by( 'name', 'Fans', 'category' );
	if ( ! $cat ) {
		$inserted = wp_insert_term( 'Fans', 'category' );
		$cat_id   = ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) ? (int) $inserted['term_id'] : 0;
	} else {
		$cat_id = (int) $cat->term_id;
	}

	$post_id = wp_insert_post(
		array(
			'post_title'     => 'Wooden Ceiling Fans for Covered Patios: What You Need to Know',
			'post_name'      => 'wooden-ceiling-fans-covered-patios',
			'post_status'    => 'draft',
			'post_type'      => 'post',
			'post_author'    => $author_id,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'post_excerpt'   => 'Discover how to choose and install wooden ceiling fans on covered patios. Learn about coastal conditions, damp location finishes and caring for your Timber Fan.',
			'post_content'   => '<!-- seeding -->',
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return;
	}

	$images = array(
		'01' => array(
			'file'  => '01-covered-coastal-patio.jpg',
			'title' => 'Wooden ceiling fans on a covered coastal patio',
			'alt'   => 'Two wooden ceiling fans under a timber-clad covered patio overlooking the ocean at sunset',
		),
		'02' => array(
			'file'  => '02-slatted-patio-fan.jpg',
			'title' => 'Timber ceiling fan on a slatted patio',
			'alt'   => 'Black motor timber ceiling fan installed on a slatted covered patio',
		),
		'03' => array(
			'file'  => '03-pool-patio-fans.jpg',
			'title' => 'Covered pool patio with timber ceiling fans',
			'alt'   => 'Covered poolside patio with timber ceiling fans and indoor-outdoor living',
		),
		'04' => array(
			'file'  => '04-covered-lounge-fan.jpg',
			'title' => 'Timber ceiling fan over a covered outdoor lounge',
			'alt'   => 'Three-blade timber ceiling fan over a covered outdoor lounge',
		),
		'05' => array(
			'file'  => '05-modern-pool-patio.jpg',
			'title' => 'Modern covered patio and pool with timber ceiling fans',
			'alt'   => 'Modern covered patio and swimming pool with multiple timber ceiling fans',
		),
		'06' => array(
			'file'  => '06-laminated-timber-blades.jpg',
			'title' => 'Laminated timber fan blades',
			'alt'   => 'Close-up of laminated timber ceiling fan blades and stainless hub',
		),
		'07' => array(
			'file'  => '07-finishing-timber-blade.jpg',
			'title' => 'Hand-finishing a timber fan blade',
			'alt'   => 'Craftsman hand-finishing a laminated timber ceiling fan blade',
		),
		'08' => array(
			'file'  => '08-timber-fan-downrod.jpg',
			'title' => 'Timber ceiling fan on a downrod',
			'alt'   => 'Timber ceiling fan hanging from a downrod on a vaulted covered patio',
		),
		'09' => array(
			'file'  => '09-slatted-outdoor-lounge.jpg',
			'title' => 'Timber ceiling fan in a slatted outdoor lounge',
			'alt'   => 'Timber ceiling fan in a slatted covered outdoor lounge looking into the bush',
		),
		'10' => array(
			'file'  => '10-indoor-outdoor-dining.jpg',
			'title' => 'Indoor-outdoor dining with timber ceiling fans',
			'alt'   => 'Covered indoor-outdoor dining space with timber ceiling fans',
		),
	);

	$ids = array();
	foreach ( $images as $key => $image ) {
		$ids[ $key ] = gp_child_seed_attach_local_image(
			$image['file'],
			$post_id,
			$image['title'],
			$image['alt']
		);
	}

	if ( ! empty( $ids['01'] ) ) {
		set_post_thumbnail( $post_id, $ids['01'] );
	}

	$content = gp_child_seed_patio_blog_content( $ids );

	$update = array(
		'ID'           => $post_id,
		'post_content' => $content,
		'post_status'  => 'publish',
	);
	if ( $cat_id ) {
		$update['post_category'] = array( $cat_id );
	}
	wp_update_post( $update );

	update_post_meta( $post_id, '_yoast_wpseo_title', 'Wooden Ceiling Fans for Covered Patios | Timber Fans' );
	update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Discover how to choose and install wooden ceiling fans on covered patios. Learn about coastal conditions, damp location finishes and caring for your Timber Fan.' );
	update_post_meta( $post_id, '_yoast_wpseo_focuskw', 'wooden ceiling fans' );

	update_option( 'gp_child_seeded_patio_blog', (int) $post_id, false );
}

/**
 * Sideload a theme image into the media library.
 *
 * @param string $filename File in assets/blog/wooden-ceiling-fans-covered-patios/.
 * @param int    $parent_id Parent post ID.
 * @param string $title Attachment title.
 * @param string $alt Alt text.
 * @return int Attachment ID.
 */
function gp_child_seed_attach_local_image( $filename, $parent_id, $title, $alt ) {
	$path = get_stylesheet_directory() . '/assets/blog/wooden-ceiling-fans-covered-patios/' . $filename;
	if ( ! file_exists( $path ) ) {
		return 0;
	}

	$filetype = wp_check_filetype( basename( $path ), null );
	$upload   = wp_upload_bits( basename( $path ), null, file_get_contents( $path ) );
	if ( ! empty( $upload['error'] ) ) {
		return 0;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'post_parent'    => $parent_id,
		),
		$upload['file'],
		$parent_id
	);

	if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
		return 0;
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

	return (int) $attachment_id;
}

/**
 * Gutenberg image block.
 *
 * @param int    $id  Attachment ID.
 * @param string $alt Alt text.
 * @return string
 */
function gp_child_seed_image_block( $id, $alt ) {
	if ( ! $id ) {
		return '';
	}
	$src = wp_get_attachment_image_url( $id, 'large' );
	if ( ! $src ) {
		$src = wp_get_attachment_url( $id );
	}
	return sprintf(
		"<!-- wp:image {\"id\":%1\$d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%2\$s\" alt=\"%3\$s\" class=\"wp-image-%1\$d\"/></figure>\n<!-- /wp:image -->\n\n",
		(int) $id,
		esc_url( $src ),
		esc_attr( $alt )
	);
}

/**
 * Three equal image columns.
 *
 * @param array $items List of array( id, alt ).
 * @return string
 */
function gp_child_seed_image_columns( $items ) {
	$columns = '';
	foreach ( $items as $item ) {
		$columns .= "<!-- wp:column -->\n<div class=\"wp-block-column\">\n" . gp_child_seed_image_block( $item['id'], $item['alt'] ) . "</div>\n<!-- /wp:column -->\n\n";
	}

	return "<!-- wp:columns -->\n<div class=\"wp-block-columns\">\n" . $columns . "</div>\n<!-- /wp:columns -->\n\n";
}

/**
 * Image left, text right — matches the Word doc wrap for Installation.
 *
 * @param int    $image_id  Attachment ID.
 * @param string $image_alt Alt text.
 * @param string $text      Gutenberg blocks for the right column.
 * @return string
 */
function gp_child_seed_image_text_columns( $image_id, $image_alt, $text ) {
	$image = gp_child_seed_image_block( $image_id, $image_alt );

	return "<!-- wp:columns {\"verticalAlignment\":\"top\",\"className\":\"patio-install-columns\"} -->\n<div class=\"wp-block-columns patio-install-columns are-vertically-aligned-top\">\n<!-- wp:column {\"verticalAlignment\":\"top\",\"width\":\"46%\"} -->\n<div class=\"wp-block-column is-vertically-aligned-top\" style=\"flex-basis:46%\">\n" . $image . "</div>\n<!-- /wp:column -->\n\n<!-- wp:column {\"verticalAlignment\":\"top\",\"width\":\"54%\"} -->\n<div class=\"wp-block-column is-vertically-aligned-top\" style=\"flex-basis:54%\">\n" . $text . "</div>\n<!-- /wp:column -->\n</div>\n<!-- /wp:columns -->\n\n";
}

/**
 * Build Gutenberg post content.
 *
 * @param int[] $ids Map of image keys to attachment IDs.
 * @return string
 */
function gp_child_seed_patio_blog_content( $ids ) {
	$shop  = home_url( '/shop/' );
	$quote = home_url( '/quote/' );

	$html  = '';
	$html .= "<!-- wp:paragraph -->\n<p>A covered patio is one of the most enjoyable spaces in a South African home. Whether you're relaxing with family or entertaining friends, a ceiling fan can make the space more comfortable while adding natural warmth and style.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>If you're considering a wooden ceiling fan for a covered patio, particularly in a coastal or tropical environment, there are a few things to consider.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= gp_child_seed_image_block( $ids['01'], 'Two wooden ceiling fans under a timber-clad covered patio overlooking the ocean at sunset' );

	$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Are Wooden Ceiling Fans Suitable for Covered Patios?</h2>\n<!-- /wp:heading -->\n\n";
	$html .= gp_child_seed_image_columns(
		array(
			array( 'id' => $ids['02'], 'alt' => 'Black motor timber ceiling fan installed on a slatted covered patio' ),
			array( 'id' => $ids['03'], 'alt' => 'Covered poolside patio with timber ceiling fans and indoor-outdoor living' ),
			array( 'id' => $ids['04'], 'alt' => 'Three-blade timber ceiling fan over a covered outdoor lounge' ),
		)
	);
	$html .= "<!-- wp:paragraph -->\n<p>Yes. Wooden ceiling fans can be a great choice for covered outdoor areas, provided they are installed in a suitably sheltered location. Many Timber Fans customers have installed our fans in covered patios, entertainment areas and coastal homes.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>Our fans are a damp-location version: the metal components receive an anti-rust treatment to provide additional protection against corrosion.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>It's important to remember, however, that <strong>damp location does not mean waterproof</strong>. Our fans should not be exposed to direct rain, persistent leaks or regular wind-driven water.</p>\n<!-- /wp:paragraph -->\n\n";

	$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">How Does a Ceiling Fan Work on an Open-Sided Patio?</h2>\n<!-- /wp:heading -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>A ceiling fan cools you by moving air across your skin, helping your body release heat.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>On an open-sided patio, some of that airflow naturally disperses beyond the space rather than being contained by walls. You can still enjoy a refreshing breeze, particularly when sitting directly beneath the fan, but the cooling effect may be less concentrated than it would be indoors.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>The size and position of the fan matters. You may need multiple fans across a large patio area. Choose a fan suited to the size of the space and position it where you spend the most time.</p>\n<!-- /wp:paragraph -->\n\n";

	$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Damp Location &amp; Coastal Environments</h2>\n<!-- /wp:heading -->\n\n";
	$html .= gp_child_seed_image_block( $ids['05'], 'Modern covered patio and swimming pool with multiple timber ceiling fans' );
	$html .= "<!-- wp:paragraph -->\n<p>Our damp location fans are designed for environments where humidity and moisture in the air may be higher than indoors.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>The metal components receive an anti-rust treatment, with stainless steel blade screws and nickel-plated mild steel used for the remaining metal components.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>Our fans have been installed successfully in coastal and tropical locations, including Mauritius, Seychelles and Mozambique. However, conditions vary considerably. A property directly on the seafront will experience greater exposure to salt-laden air than a more sheltered coastal home.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>With suitable placement and care, our experience is that the damp location finish performs well in most coastal and tropical environments.</p>\n<!-- /wp:paragraph -->\n\n";

	$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Wooden Blades: Strength &amp; Stability</h2>\n<!-- /wp:heading -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>Natural timber is one of the defining features of Timber Fans.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>Our Monaco and Sirocco ranges feature laminated Paulownia hardwood blades. Paulownia is lightweight and has a high strength-to-weight ratio, while lamination helps improve stability and reduce the risk of warping, shrinkage and splitting.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>Our Fern, Classic and Piccolo blade sets are manufactured from laminated veneer and sealed with a protective finish.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>While these construction methods provide excellent stability, <strong>wooden blades are not waterproof</strong>. Prolonged exposure to water or extreme environmental conditions could affect the timber.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= gp_child_seed_image_columns(
		array(
			array( 'id' => $ids['06'], 'alt' => 'Close-up of laminated timber ceiling fan blades and stainless hub' ),
			array( 'id' => $ids['07'], 'alt' => 'Craftsman hand-finishing a laminated timber ceiling fan blade' ),
			array( 'id' => $ids['08'], 'alt' => 'Timber ceiling fan hanging from a downrod on a vaulted covered patio' ),
		)
	);

	$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Important Installation Considerations</h2>\n<!-- /wp:heading -->\n\n";
	$install_text  = "<!-- wp:paragraph -->\n<p>A few simple checks can help ensure your fan performs well for years to come:</p>\n<!-- /wp:paragraph -->\n\n";
	$install_text .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n<!-- wp:list-item -->\n<li><strong>Keep it sheltered:</strong> Make sure the fan is protected from direct rain and wind-driven water.</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li><strong>Check for leaks:</strong> Resolve any roof or ceiling leaks before installation. Water entering through the mounting area can cause corrosion and damage internal components.</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li><strong>Consider exposure:</strong> Take prevailing winds, proximity to the sea and the overall exposure of the patio into account.</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li><strong>Install correctly:</strong> Follow the manufacturer's installation instructions and ensure electrical work is carried out by a qualified electrician.</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li><strong>Maintain your fan:</strong> Periodically check for corrosion, water marks or deterioration and keep the fan clean and dry.</li>\n<!-- /wp:list-item -->\n</ul>\n<!-- /wp:list -->\n\n";
	$html .= gp_child_seed_image_text_columns(
		$ids['09'],
		'Timber ceiling fan in a slatted covered outdoor lounge looking into the bush',
		$install_text
	);
	$html .= "<!-- wp:paragraph -->\n<p>If your patio is particularly exposed to strong or persistent winds, we recommend discussing the installation with Timber Fans before proceeding.</p>\n<!-- /wp:paragraph -->\n\n";

	$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">What If Rust Develops?</h2>\n<!-- /wp:heading -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>Even with a damp location finish, some surface rust may eventually occur in particularly harsh coastal environments.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>However, <strong>rust would not normally be expected soon after installation in a properly sheltered environment</strong>. If rust appears early, check for water leaks or direct exposure to rain before considering replacement.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>Replacement metal components and spares are available from Timber Fans if required.</p>\n<!-- /wp:paragraph -->\n\n";

	$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Choosing the Right Fan</h2>\n<!-- /wp:heading -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>When choosing a wooden ceiling fan for a covered patio, consider the <strong>level of shelter, climate, patio size and exposure to moisture and wind</strong>.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= "<!-- wp:paragraph -->\n<p>If you're unsure whether a particular Timber Fans model is suitable for your outdoor space, our team is happy to advise. <a href=\"" . esc_url( $quote ) . "\">Request a quote</a> or browse the <a href=\"" . esc_url( $shop ) . "\">full range</a>.</p>\n<!-- /wp:paragraph -->\n\n";
	$html .= gp_child_seed_image_block( $ids['10'], 'Covered indoor-outdoor dining space with timber ceiling fans' );

	return $html;
}

add_action( 'init', 'gp_child_patch_patio_install_columns', 40 );

/**
 * One-time: wrap the installation photo and checklist in two columns.
 */
function gp_child_patch_patio_install_columns() {
	if ( is_admin() || wp_doing_ajax() || wp_installing() ) {
		return;
	}

	if ( get_option( 'gp_child_patched_patio_install_cols' ) ) {
		return;
	}

	$post = get_page_by_path( 'wooden-ceiling-fans-covered-patios', OBJECT, 'post' );
	if ( ! $post ) {
		return;
	}

	if ( false !== strpos( $post->post_content, 'patio-install-columns' ) ) {
		update_option( 'gp_child_patched_patio_install_cols', (int) $post->ID, false );
		return;
	}

	$pattern      = '/(<!-- wp:heading -->\s*<h2 class="wp-block-heading">Important Installation Considerations<\/h2>\s*<!-- \/wp:heading -->\s*)(<!-- wp:image[\s\S]*?<!-- \/wp:image -->\s*)(<!-- wp:paragraph -->\s*<p>A few simple checks[\s\S]*?<!-- \/wp:paragraph -->\s*)(<!-- wp:list -->[\s\S]*?<!-- \/wp:list -->\s*)/';
	$replacement  = '$1<!-- wp:columns {"verticalAlignment":"top","className":"patio-install-columns"} -->' . "\n";
	$replacement .= '<div class="wp-block-columns patio-install-columns are-vertically-aligned-top">' . "\n";
	$replacement .= '<!-- wp:column {"verticalAlignment":"top","width":"46%"} -->' . "\n";
	$replacement .= '<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:46%">' . "\n";
	$replacement .= '$2</div>' . "\n";
	$replacement .= '<!-- /wp:column -->' . "\n\n";
	$replacement .= '<!-- wp:column {"verticalAlignment":"top","width":"54%"} -->' . "\n";
	$replacement .= '<div class="wp-block-column is-vertically-aligned-top" style="flex-basis:54%">' . "\n";
	$replacement .= '$3$4</div>' . "\n";
	$replacement .= '<!-- /wp:column -->' . "\n";
	$replacement .= '</div>' . "\n";
	$replacement .= '<!-- /wp:columns -->' . "\n\n";

	$updated = preg_replace( $pattern, $replacement, $post->post_content, 1, $count );
	if ( empty( $count ) || ! is_string( $updated ) ) {
		return;
	}

	wp_update_post(
		array(
			'ID'           => $post->ID,
			'post_content' => $updated,
		)
	);
	update_option( 'gp_child_patched_patio_install_cols', (int) $post->ID, false );
}
