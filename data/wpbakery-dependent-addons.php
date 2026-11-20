<?php
/**
 * Add-ons whose shortcodes are verified to depend on WPBakery at runtime.
 *
 * Each entry:
 *  - name:             Human readable add-on name.
 *  - plugin_dir:       Plugin folder name (used to recognise the handler's file location).
 *  - verified_version: Version in which the dependency was verified.
 *  - evidence:         Where in the add-on's code the dependency is visible.
 *  - tags:             Shortcode tags registered by that version.
 *
 * Extend with the 'ics_wpbakery_dependent_addons' filter.
 *
 * @package ImprezaCompatibilityScanner
 */

defined( 'ABSPATH' ) || exit;

return array(
	'ultimate_vc_addons' => array(
		'name'             => 'Ultimate Addons for WPBakery Page Builder',
		'plugin_dir'       => 'Ultimate_VC_Addons',
		'verified_version' => '3.21.6',
		'evidence'         => 'Ultimate_VC_Addons.php: the constructor returns before loading any module when WPB_VC_VERSION is not defined, and aio_init() returns early as well, so none of its shortcodes are registered without WPBakery.',
		'tags'             => array(
			'bsf-info-box',
			'icon_counter',
			'icon_timeline',
			'icon_timeline_feat',
			'icon_timeline_item',
			'icon_timeline_sep',
			'info_circle',
			'info_circle_item',
			'info_list',
			'info_list_item',
			'interactive_banner',
			'interactive_banner_2',
			'just_icon',
			'single_icon',
			'single_tab',
			'stat_counter',
			'swatch_container',
			'swatch_item',
			'ult_animation_block',
			'ult_buttons',
			'ult_content_box',
			'ult_countdown',
			'ult_createlink',
			'ult_dualbutton',
			'ult_hotspot',
			'ult_hotspot_items',
			'ult_ihover',
			'ult_ihover_item',
			'ult_range_slider',
			'ult_sticky_section',
			'ult_tab_element',
			'ult_team',
			'ultimate_carousel',
			'ultimate_ctation',
			'ultimate_dual_color',
			'ultimate_exp_section',
			'ultimate_fancytext',
			'ultimate_google_map',
			'ultimate_google_trends',
			'ultimate_heading',
			'ultimate_icon_list',
			'ultimate_icon_list_item',
			'ultimate_icons',
			'ultimate_img_separator',
			'ultimate_info_banner',
			'ultimate_info_table',
			'ultimate_modal',
			'ultimate_pricing',
			'ultimate_ribbon',
			'ultimate_spacer',
			'ultimate_video',
			'ultimate_video_banner',
			'woocomposer_carousel',
			'woocomposer_carousel_cat',
			'woocomposer_grid',
			'woocomposer_grid_cat',
			'woocomposer_list',
			'woocomposer_product',
		),
	),
);
