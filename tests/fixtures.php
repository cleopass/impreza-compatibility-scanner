<?php
/**
 * Test fixtures for Impreza Compatibility Scanner.
 *
 * ONLY FOR A LOCAL / STAGING SITE. Creates test posts, a widget, a WPBakery template option and
 * post meta. Run with WP-CLI:   wp eval-file wp-content/plugins/impreza-compatibility-scanner/tests/fixtures.php
 * Remove afterwards with:       wp post delete $(wp post list --post_type=any --post_status=any --s="ICS-T" --format=ids) --force
 *
 * The matching optional mu-plugin (tests/mu-plugin-test-shortcodes.php) registers [my_test_box]
 * everywhere and [front_only_box] only on the front end.
 *
 * @package ImprezaCompatibilityScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$mk = function( $title, $content, $type = 'page', $status = 'publish', $extra = array() ) {
	$args = array_merge( array( 'post_title' => $title, 'post_content' => $content, 'post_type' => $type, 'post_status' => $status ), $extra );
	$id = wp_insert_post( wp_slash( $args ), true );
	echo $title, ' => ', is_wp_error( $id ) ? $id->get_error_message() : $id, "\n";
	return $id;
};
$mk( 'ICS-T01 Native Impreza layout', '[vc_row][vc_column][vc_column_text]Hello [us_btn label="Go"][/vc_column_text][us_iconbox icon="fas|star" title="Star"][/vc_column][/vc_row]' );
$mk( 'ICS-T02 WPBakery elements', '[vc_row][vc_column][vc_custom_heading text="Hi"][vc_single_image image="1"][vc_raw_html]JTNDcCUzRQ==[/vc_raw_html][vc_empty_space][vc_pie value="50"][/vc_column][/vc_row]' );
$mk( 'ICS-T03 Ultimate Addons', '[vc_row][vc_column][ultimate_heading main_heading="Title"]Sub[/ultimate_heading][just_icon icon="x"][/vc_column][/vc_row]' );
$mk( 'ICS-T04 WordPress core shortcodes', '[caption id="a1" width="300"]<img src="x.jpg"> Caption[/caption] [gallery ids="1,2"] [embed]https://example.com/v[/embed] [audio src="a.mp3"]', 'post' );
$mk( 'ICS-T05 Unregistered and noise', '[old_plugin_box title="x"]content[/old_plugin_box] [Note] plain text, footnote [1], escaped [[vc_row]] and JSON ["a","b"] and [lonely_tag]', 'post', 'draft' );
$mk( 'ICS-T06 Nested tabs private', '[vc_row][vc_column][vc_tta_tabs][vc_tta_section title="A" tab_id="a"][vc_column_text][contact-form-7 id="1"] [my_test_box] [front_only_box x="1"][/vc_column_text][/vc_tta_section][/vc_tta_tabs][/vc_column][/vc_row]', 'page', 'private' );
$mk( 'ICS-T07 Scheduled legacy css', '[vc_row css=".vc_custom_1234{padding-top: 0px !important;}"][vc_column][vc_column_text]x[/vc_column_text][/vc_column][/vc_row]', 'post', 'future', array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 86400 * 30 ) ) );
$mk( 'ICS-T08 Reusable block', '[us_btn label="Block"][vc_btn title="Old button"]', 'us_page_block' );
$mk( 'ICS-T09 Page template', '[vc_row][vc_column][us_post_title][us_post_content][/vc_column][/vc_row]', 'us_content_template' );
$mk( 'ICS-T10 Malformed', '[vc_row][vc_column]unclosed [vc_column_text]text [ultimate_heading main_heading="x"', 'page', 'pending' );
$id = $mk( 'ICS-T11 Meta sources', 'No shortcodes in content.' );
add_post_meta( $id, 'intro_text', '[vc_custom_heading text="From ACF"]' );
add_post_meta( $id, 'blocks', array( 'a' => '[ult_buttons btn_title="x"]', 'b' => array( '[my_test_box]' ) ) );
add_post_meta( $id, '_internal', '[vc_icon icon="x"]' );
$mk( 'ICS-T12 Grid item', '[vc_gitem][vc_gitem_animated_block][vc_gitem_zone_a][vc_gitem_row][vc_gitem_col][vc_gitem_post_title][/vc_gitem_col][/vc_gitem_row][/vc_gitem_zone_a][/vc_gitem_animated_block][/vc_gitem]', 'vc_grid_item' );
$mk( 'ICS-T13 Trashed', '[vc_custom_heading text="trash"]', 'page', 'trash' );
$mk( 'ICS-T14 Excerpt', 'Body', 'post', 'publish', array( 'post_excerpt' => 'Teaser [vc_btn title="x"]' ) );
// Widgets — added as new instances (existing widgets are kept).
$text = get_option( 'widget_text', array( '_multiwidget' => 1 ) );
$text[990] = array( 'title' => 'ICS-T Promo', 'text' => '[vc_btn title="Widget"] and [my_test_box]' );
update_option( 'widget_text', $text );
$html = get_option( 'widget_custom_html', array( '_multiwidget' => 1 ) );
$html[991] = array( 'title' => 'ICS-T HTML', 'content' => '<div>[old_plugin_box a="1"]</div>' );
update_option( 'widget_custom_html', $html );
$sw = get_option( 'sidebars_widgets', array() );
$sw['wp_inactive_widgets'] = array_merge( isset( $sw['wp_inactive_widgets'] ) ? (array) $sw['wp_inactive_widgets'] : array(), array( 'text-990', 'custom_html-991' ) );
update_option( 'sidebars_widgets', $sw );
// WPBakery "My templates" — one template added (existing templates are kept).
$tpl = get_option( 'wpb_js_templates', array() );
$tpl = is_array( $tpl ) ? $tpl : array();
$tpl['ics_test_tpl'] = array( 'name' => 'ICS-T Old template', 'template' => '[vc_row][vc_column][vc_pie value="50"][/vc_column][/vc_row]' );
update_option( 'wpb_js_templates', $tpl );
echo "Done. Remove the widgets text-990 / custom_html-991 and the template ics_test_tpl manually after testing.\n";
