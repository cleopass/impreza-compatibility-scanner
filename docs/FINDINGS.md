# Investigation findings — how Impreza / US Core / WPBakery handle shortcodes

These findings were made by reading the source code of the versions supplied for this project.
They are the basis for every compatibility rule in the plugin. Nothing in the rules is based on
assumptions beyond what is documented here.

| Component | Version inspected |
|---|---|
| Impreza (theme) | 9.6.1 |
| UpSolution Core (US Core, plugin `us-core`) | 9.6.1 |
| WPBakery Page Builder (`js_composer`) | 9.2 |
| Ultimate Addons for WPBakery (`Ultimate_VC_Addons`) | 3.21.6 |

File references are relative to each plugin/theme folder.

---

## 1. US Core registers its own handlers for theme elements — including `vc_row`, `vc_column` etc.

**`us-core/config/shortcodes.php`** returns four lists (filterable via `us_config_shortcodes`):

* `theme_elements` — the elements of the theme. Names without a `vc_` prefix get a `us_` prefix
  when registered (`btn` → `[us_btn]`). This list contains the WPBakery-named layout shortcodes
  `vc_row`, `vc_row_inner`, `vc_column`, `vc_column_inner`, `vc_column_text`, `vc_tta_accordion`,
  `vc_tta_tabs`, `vc_tta_tour`, `vc_tta_section`, `vc_widget_sidebar`, `vc_video`.
* `alias` — shortcodes that reuse another element's template (e.g. `vc_column_inner` → `vc_column`,
  `gallery` → `us_gallery`, `us_post_carousel` → `us_post_list`).
* `disabled` — WPBakery elements that US Core **removes** (`remove_shortcode()`) when the theme
  option `disable_extra_vc` ("Disable extra features of WPBakery Page Builder", default ON) is enabled,
  e.g. `vc_btn`, `vc_single_image`, `vc_separator`, `vc_empty_space`, `vc_icon`, `vc_message`,
  `vc_gallery`, `vc_tabs`, `vc_tour`, `vc_accordion`, `vc_tta_pageable`, `vc_tta_toggle`,
  `vc_section`, `vc_pie`, `vc_progress_bar`, … and several WooCommerce shortcodes
  (`product`, `products`, `product_category`, …). Note: `remove_shortcode()` runs on `init`, but on
  the front end WPBakery registers its lean-mapped tags later (`template_redirect`, see §4), and
  `vc_remove_element()` is only called in wp-admin (`us_vc_init_shortcodes()` in
  `plugins-support/js_composer/js_composer.php`). Whether such a tag renders on the front end can
  therefore only be decided from the real front-end registry, which is what the scanner uses.
  In every case these are WPBakery elements, not Impreza elements.
* `added_design_options` — WPBakery elements to which US Core only *adds* its Design options
  inside the WPBakery editor: `vc_custom_heading`, `vc_line_chart`, `vc_raw_html`, `vc_round_chart`.
  They are still rendered by WPBakery.

**`us-core/functions/shortcodes.php` — class `US_Shortcodes`**

* `init()` runs on `init` priority 20 ("after WPBakery") and calls
  `add_shortcode( $shortcode, array( $this, $shortcode ) )` for every theme element and alias.
  Because `add_shortcode()` overwrites, **US Core's handler replaces WPBakery's** for `vc_row`,
  `vc_column`, `vc_column_text`, `vc_tta_*` etc.
* Rendering goes through the magic `__call()`, which loads `templates/elements/<element>.php`.
* The file is loaded unconditionally from `functions/init.php`, i.e. **these shortcodes are
  registered whether WPBakery is active or not**.
* Exception: `vc_front_end_compatibility()` removes the `vc_tta_*` handlers, but it is only called
  when the *WPBakery front-end editor* is open (`vc_is_page_editable()`), so it does not affect
  normal rendering or the Impreza Live Builder.

→ **Runtime evidence used by the scanner:** a tag whose handler is
`array( <instance of US_Shortcodes>, <tag> )` is rendered by US Core.

## 2. "Override config only" elements

`config/elements/gravityform.php`, `contact-form-7.php`, `woocommerce_cart.php`,
`woocommerce_checkout.php`, `woocommerce_my_account.php` set `'override_config_only' => TRUE`
("This is not our element and we only store the configuration for support in the builders").
They appear in `theme_elements`, are **rendered by their own plugin**, but US Core provides the
Live Builder settings panel for them. `us_get_shortcode_full_name()` keeps their names unprefixed.

## 3. What the Impreza Live Builder can edit (`us-core/builder/`)

* `USBuilder::get_config()` (`builder/include/USBuilder.class.php`) builds `elms_supported` and
  `elm_titles` **only** from `us_config( 'shortcodes.theme_elements' )`, skipping elements whose
  config has `place_if => false` (e.g. WooCommerce elements without WooCommerce, `gravityform`
  without Gravity Forms).
* `builder/assets/js/builder/builder.panel.js`: when an element is not in `elms_supported`, the
  panel shows **"Editing of this element is not supported."** with a link "Edit page in Backend".
* `USBuilder_Shortcode::normalize_content()` (`builder/include/USBuilder/Shortcode.class.php`)
  keeps non-theme shortcodes **unchanged in place** ("Shortcodes that aren't registered on the site
  stay as they are, so they work when their plugin is back"). It only treats something as an
  unregistered shortcode when it has attributes, is self-closing, or has a closing tag
  (so plain text such as `[Note]` is not a shortcode).

→ A shortcode that is not a theme element **can still render** in the Live Builder preview, but it
is **not editable as a native element**. The scanner therefore reports two separate facts per tag:
"Renders without WPBakery" and "Native Live Builder element".

## 4. When WPBakery registers its shortcodes (`js_composer`)

* `include/classes/core/class-vc-base.php → initPage()`: on the **front end**, WPBakery calls
  `WPBMap::addAllMappedShortcodes()` on `template_redirect`. Lean-mapped elements
  (`config/lean-map.php`) are registered as `add_shortcode( $tag, 'vc_do_shortcode' )`
  — only if the tag does not exist yet (`include/classes/core/class-wpb-map.php`).
* `WPBakeryShortCodeFishBones` registers `array( $this, 'render' )` when an element class is
  instantiated.
* Consequence: **in wp-admin / admin-ajax, most WPBakery shortcodes are not registered at all.**
  A scanner that only looks at the admin runtime would wrongly report them as unregistered.
  The plugin therefore captures the **front-end shortcode registry** with an authenticated,
  nonce-protected request that stops at `template_redirect` (priority 9999, after WPBakery) and
  returns only tag → handler information. No page is rendered and no shortcode is executed.
* `WPBMap::exists( $tag )` is a read-only lookup that tells whether a tag is mapped into the
  WPBakery editor (including third-party `vc_map()` registrations).
* The list of WPBakery-owned element tags in `data/wpbakery-elements.php` was extracted from
  `config/**/shortcode-*.php`, `config/lean-map.php` and the `'base'` keys in WPBakery 9.2.
  WooCommerce / Gravity Forms / Contact Form 7 tags that WPBakery merely maps are **not** in this
  list, because they are rendered by their own plugins.

## 5. Ultimate Addons for WPBakery depends on WPBakery at runtime

`Ultimate_VC_Addons.php`, constructor:

```php
if ( ! defined( 'WPB_VC_VERSION' ) ) {
    add_action( 'admin_init', array( $this, 'init_addons' ) );
    return;
}
```

and `aio_init()` also returns early without `WPB_VC_VERSION`. Its modules (which call
`add_shortcode()`) are therefore **never loaded when WPBakery is inactive**. This is verified
evidence of a WPBakery dependency. The 58 tags registered by version 3.21.6 are listed in
`data/wpbakery-dependent-addons.php`.

US Core contains `plugins-support/Ultimate_VC_Addons.php`, but it only sanitizes BSF updater hooks
and merges Reusable Block content for asset detection — it does not render Ultimate elements.

## 6. Post types that contain shortcode content

From `us-core/functions/post-types.php`:

| Post type | Content | Scanned |
|---|---|---|
| `us_page_block` (Reusable Blocks) | shortcodes | yes |
| `us_content_template` (Page Templates) | shortcodes | yes |
| `us_portfolio`, `us_testimonial` | shortcodes | yes |
| `us_header` | JSON | no |
| `us_grid_layout` | JSON | no |
| `us_cform_inbound` | form submissions | no (personal data) |

From WPBakery: `vc_grid_item` (Grid Builder templates, `vc_gitem*` shortcodes) — scanned.
WPBakery "My templates" are stored in the option `wpb_js_templates` — scanned as an option source.

## 7. Design options

US Core stores Design options in the `css` attribute as URL-encoded JSON ("jsoncss",
`us_get_unique_css_class_name()`, `us_append_elm_design_settings()`). Legacy WPBakery design
options look like `css=".vc_custom_123{…}"`; the matching CSS is stored in the post meta
`_wpb_shortcodes_custom_css` and printed **by WPBakery**. US Core migrations (e.g.
`migrations/Impreza/us_migration_7_0.php`) converted old content, but newer content edited with
the WPBakery backend editor may still contain the legacy format. The scanner flags such occurrences
with the note `legacy_wpb_design_css`. This is reported as a note, not as a classification.

## 8. Things deliberately *not* concluded

* Being registered does not prove Live Builder compatibility (see §3).
* Being absent from the Live Builder "Add element" list does not mean a shortcode cannot render (§3).
* A third-party shortcode registered while WPBakery is active may still depend on WPBakery
  (as Ultimate Addons does). Without verified evidence it is classified **Unknown**.
