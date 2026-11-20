# Impreza Compatibility Scanner

Read-only WordPress plugin that audits the shortcodes stored in a site's content and shows which of them are native Impreza / US Core elements, which depend on WPBakery Page Builder, and which need manual review — to support the decision whether WPBakery can be deactivated.

**Version 0.9.0-beta** · Requires WordPress 6.0+, PHP 7.4+ · License GPL-2.0-or-later · Text domain `impreza-compatibility-scanner`

> **Beta.** The plugin is read-only, but use it on a staging copy first and report results via [Issues](https://github.com/cleopas/impreza-compatibility-scanner/issues) — especially shortcodes you believe are misclassified.

> The plugin never claims that WPBakery is safe to remove. A clean scan means *no known dependency in the scanned content*, not a guarantee.

> **Not affiliated with UpSolution or WPBakery.** "Impreza", "UpSolution" and "WPBakery" are trademarks of their respective owners. This is an independent community tool. It contains no code from Impreza, US Core, WPBakery or Ultimate Addons; you need your own licensed copies of those products.

---

## 1. Installation

1. Download `impreza-compatibility-scanner.zip` from the [latest release](https://github.com/cleopas/impreza-compatibility-scanner/releases/latest) and upload it in **Plugins → Add New → Upload Plugin**. (The green "Code → Download ZIP" button also works, but the folder will be named `impreza-compatibility-scanner-main`.)
2. Activate it. Activation creates two plugin tables (`{prefix}ics_occurrences`, `{prefix}ics_tags`). Nothing else is changed.
3. Open **Tools → Impreza Compatibility Scanner**.

Uninstalling (delete from the Plugins screen) removes the two tables and the plugin's own options (`ics_*`) only.

## 2. Usage

1. Click **Start scan**. Keep the page open; a progress bar shows the progress. The scan:
   1. captures the **front-end shortcode registry** (see §3.1),
   2. scans posts in batches (≈8 seconds of work per request),
   3. scans widgets and WPBakery saved templates,
   4. stores the results and reloads the page.
2. If the page was closed or the connection dropped, the page offers **Resume interrupted scan** or **Restart from the beginning**. **Cancel scan** discards the partial results.
3. The results of the last *completed* scan stay visible (and exportable) while a new scan runs.
4. Review the result:
   * **Overall assessment** — *Confirmed incompatible elements found* / *Manual review required* / *No known incompatibilities found*.
   * **By content** — one row per content item, source location and shortcode; links to *Edit*, *Live Builder* and *View*. Filters: shortcode, content type, classification, status; search covers title, shortcode, location and notes.
   * **By shortcode** — one row per shortcode: classification, *Renders without WPBakery*, *Native Live Builder element*, number of content items, occurrences, explanation and the **evidence** list.
5. **Export all results (CSV)** or **Export shortcode summary (CSV)** — the complete result set, not only the current page.

Recommended workflow: run the scan with WPBakery **active** (to see which elements WPBakery currently renders), then on a **staging copy** deactivate WPBakery, scan again and compare.

### What is scanned

| Source | Details |
|---|---|
| Posts of all post types | `post_content` and `post_excerpt`; statuses publish, future (scheduled), draft, pending, private. Trash, auto-drafts and revisions are excluded. |
| Impreza templates | Reusable Blocks (`us_page_block`), Page Templates (`us_content_template`), Portfolio, Testimonials. |
| WPBakery data | Grid Builder templates (`vc_grid_item`), "My templates" (option `wpb_js_templates`). |
| Unregistered post types | Post types that exist in the database but are not registered now (e.g. `vc_grid_item` while WPBakery is off). |
| Post meta | Non-protected meta keys (no leading `_`) containing `[` — e.g. ACF text/WYSIWYG fields; serialized arrays are walked safely. |
| Widgets | All `widget_*` options (Text, Custom HTML, …), with sidebar / inactive status. |

Not scanned: `us_header`, `us_grid_layout` (JSON, not shortcodes), `us_cform_inbound` (form submissions), WooCommerce orders, attachments, menus, theme option values, PHP template files.

## 3. How classifications are determined

The full investigation of Impreza 9.6.1, US Core 9.6.1, WPBakery 9.2 and Ultimate Addons 3.21.6 is in [`docs/FINDINGS.md`](docs/FINDINGS.md). Summary:

### 3.1 Two runtime facts per shortcode tag

1. **Who handles it.** The plugin reads the global `$shortcode_tags` (never calls a handler) and uses PHP Reflection on the callback to find its source file → *US Core*, *WPBakery*, *WordPress core*, a plugin, mu-plugin, theme or child theme. `US_Shortcodes` instances and WPBakery's `vc_do_shortcode` / `WPBakeryShortCode` classes are recognised by class/function, not only by path.
2. **Is it a native Live Builder element.** The Live Builder's editable list (`elms_supported`) is built from `us_config('shortcodes.theme_elements')` minus elements whose `place_if` is false. The plugin reproduces that check. Every other shortcode is kept in place by the Live Builder but shows *"Editing of this element is not supported"*.

**Why the front-end registry matters:** WPBakery registers most of its shortcodes only on the front end (`template_redirect`). In wp-admin they do not exist. The scanner therefore makes an authenticated, nonce-protected request to the home page (`?ics_registry_probe=…`) which stops at `template_redirect` priority 9999 and returns only the list of tags and handlers. No page is rendered and no shortcode is executed. If this request fails (e.g. a security plugin blocks it), the scan continues with the wp-admin registry and the assessment becomes *Manual review required*.

### 3.2 Rules (first match wins)

| # | Rule ID | Condition | Result |
|---|---|---|---|
| 5 | `manual_override` | Tag set via the `ics_tag_classification_overrides` filter | as configured |
| 10 | `us_native_element` | Tag is an Impreza theme element, handled by `US_Shortcodes`, available in the Live Builder | **Compatible** (renders: yes, LB: yes) |
| | | Impreza element with `override_config_only` (Gravity Forms, Contact Form 7, WooCommerce cart/checkout/account) rendered by its own plugin | **Compatible** |
| | | Impreza element, but `place_if` false, or another handler overrides it | **Unknown** |
| 20 | `wpbakery_handler` | Handler is WPBakery, tag not an Impreza element (e.g. `vc_custom_heading`, `vc_raw_html`) | **Incompatible** |
| 30 | `wpbakery_dependent_addon` | Tag/handler belongs to an add-on with verified WPBakery dependency (Ultimate Addons: 58 tags) | **Incompatible** (Unknown if it is registered although WPBakery is off) |
| 40 | `known_wpbakery_unregistered` | No handler, tag in WPBakery's own element list, not an Impreza element (e.g. `vc_single_image` disabled by Impreza, any `vc_*` with WPBakery off, `vc_gitem*`) | **Incompatible** (Unregistered if US Core is inactive) |
| 50 | `wordpress_core` | Handler in `wp-includes` (`caption`, `audio`, `video`, `embed`, `playlist`…) | **Compatible** (renders: yes, LB editable: no) |
| 60 | `third_party` | Registered by any other plugin/theme | **Unknown** — evidence notes whether it was registered while WPBakery was inactive and whether it is mapped into WPBakery (`vc_map`) |
| 70 | `unregistered` | No handler anywhere, no known owner | **Unregistered** |
| – | `fallback` | Nothing matched | **Unknown** |

Important consequences:

* `vc_row`, `vc_column`, `vc_column_text`, `vc_tta_*`, `vc_video`, `vc_widget_sidebar` are **Compatible** — Impreza renders them itself.
* Not all `vc_*` tags are treated alike; the decision always comes from the runtime handler and the US Core config.
* Third-party shortcodes are never marked Compatible automatically, because registration alone does not prove independence from WPBakery (Ultimate Addons is a real counter-example).

### 3.3 Notes (do not change the classification)

* `legacy_wpb_design_css` — the shortcode has `css=".vc_custom_123{…}"` (old WPBakery Design Options). That CSS is output by WPBakery from `_wpb_shortcodes_custom_css`; Impreza uses a different (JSON) format. Check such elements visually.
* **Nested inside** — the direct parent shortcode(s).

### 3.4 Extending

| Hook | Purpose |
|---|---|
| `ics_classification_rules` | Add/remove/reorder rule objects implementing `ImprezaCompatScanner\Rules\Rule`. |
| `ics_tag_classification_overrides` | `tag => [ classification, reason, renders_without_wpb, lb_editable ]` for manually verified tags. |
| `ics_tag_verdict` | Adjust a final verdict. |
| `ics_wpbakery_dependent_addons` | Add more add-ons with a verified WPBakery dependency (see `data/wpbakery-dependent-addons.php`). |
| `ics_known_wpbakery_tags` | Extend the WPBakery element list (`data/wpbakery-elements.php`). |
| `ics_scan_post_types`, `ics_scan_post_statuses`, `ics_include_unregistered_post_types` | Scope of post scanning. |
| `ics_scan_protected_meta_keys`, `ics_excluded_meta_keys` | Meta scanning. |
| `ics_scan_options` | Additional options to scan (`option_name => label`). |
| `ics_batch_time_budget` (default 8 s), `ics_batch_size` (default 25) | Batch tuning. |
| `ics_required_capability` | Default `manage_options`. |

Example of a manual override (in a site-specific plugin):

```php
add_filter( 'ics_tag_classification_overrides', function ( $overrides ) {
	$overrides['my_events_list'] = array(
		'classification'      => 'compatible',
		'reason'              => 'Verified on staging with WPBakery deactivated (2026-11-20).',
		'renders_without_wpb' => 'yes',
		'lb_editable'         => 'no',
	);
	return $overrides;
} );
```

## 4. Security and read-only operation

* Only users with `manage_options` (filterable) can open the page, run scans, capture the registry or export.
* All AJAX actions use a nonce; CSV export uses `check_admin_referer()`; the front-end probe verifies its own nonce and capability.
* Shortcodes are **parsed**, never executed (`get_shortcode_regex()` + `preg_match_all`, no `do_shortcode()`).
* Serialized meta is unserialized with `allowed_classes => false` (no object instantiation).
* The plugin writes only to its two tables and its options `ics_scan_state`, `ics_last_scan`, `ics_batch_lock`, `ics_frontend_registry`, `ics_db_version`.
* CSV: UTF-8 with BOM, RFC 4180 quoting, cells starting with `= + - @` or tab are prefixed with `'` (formula-injection protection).

## 5. Known limitations

1. **Shortcode content only.** PHP templates, theme option values, header/grid layouts (JSON), hard-coded `do_shortcode()` calls in a child theme, and CSS/JS that WPBakery enqueues are not analysed.
2. **Attribute-level dependencies** are not fully detected: e.g. Ultimate Addons row backgrounds or parallax stored as extra `vc_row` attributes, WPBakery-specific attributes on Impreza elements. Only legacy WPBakery Design Options CSS is flagged.
3. **WordPress parser limits**: same-tag nesting (`[foo][foo]…[/foo][/foo]`) cannot be parsed reliably by WordPress either (that is why WPBakery uses `vc_row_inner`). Unclosed tags are counted as self-closing.
4. **Tag names**: only tags starting with a letter followed by letters, digits, `_` or `-` are recognised. Unknown tags without attributes, closing tag or `/]` (e.g. `[Note]`) are ignored, the same rule the Live Builder uses.
5. **Registry timing**: the front-end probe runs at `template_redirect`. Shortcodes registered later (e.g. inside a template) or only under special conditions (specific page, logged-out users, WPBakery Grid Builder scope) can appear as Unregistered. Security/caching plugins that block query-string requests can prevent the probe.
6. **Third-party plugins** are classified Unknown unless verified data exists. Rendering correctness and styling are never verified visually.
7. **Live Builder availability per post type / user role** (Theme Options → Live Builder settings) is not evaluated; "Native Live Builder element" refers to the element type.
8. **Multisite**: each site is scanned separately from its own Tools screen.
9. **Very large single values**: meta/option strings over 2 MB are skipped; content that exceeds PCRE limits is reported as a warning.
10. Compatibility data was verified against Impreza/US Core 9.6.1, WPBakery 9.2 and Ultimate Addons 3.21.6. Runtime detection adapts to other versions, but the static lists (`data/`) may need updating.

## 6. Testing

### 6.1 Test environment used during development

WordPress 6.7 (alpha build), PHP 8.3, MariaDB 10.11, Impreza 9.6.1 + US Core 9.6.1, WPBakery 9.2, Ultimate Addons 3.21.6. Tests were done with WPBakery active, WPBakery inactive and US Core inactive.

The build used had no wp-admin CSS/JS assets, so the admin page layout has not yet been checked with normal wp-admin styling. Reports from real sites are welcome.

### 6.2 Fixtures

On a **local or staging** site only:

1. Optionally copy `tests/mu-plugin-test-shortcodes.php` to `wp-content/mu-plugins/` (registers `[my_test_box]` everywhere and `[front_only_box]` on the front end only).
2. Run `wp eval-file wp-content/plugins/impreza-compatibility-scanner/tests/fixtures.php`.
3. Run a scan.

### 6.3 Representative test cases and expected results

| Fixture | Content | Expected |
|---|---|---|
| ICS-T01 | `vc_row` › `vc_column` › `vc_column_text` › `us_btn`, `us_iconbox` | all **Compatible**, LB editable: yes; parents shown |
| ICS-T02 | `vc_custom_heading`, `vc_raw_html`, `vc_single_image`, `vc_empty_space`, `vc_pie` | **Incompatible** (`wpbakery_handler` while WPBakery renders them; `known_wpbakery_unregistered` for the ones Impreza disables or when WPBakery is off). `vc_empty_space` without attributes is still counted. |
| ICS-T03 | `ultimate_heading`, `just_icon` | **Incompatible** (`wpbakery_dependent_addon`) with WPBakery on and off |
| ICS-T04 | `caption`, `audio`, `embed`, `gallery` | **Compatible**; `gallery` is an Impreza element (LB: yes), the others WordPress core (LB: no) |
| ICS-T05 (draft) | `[old_plugin_box title="x"]…`, `[Note]`, `[1]`, `[[vc_row]]`, `["a","b"]`, `[lonely_tag]` | only `old_plugin_box` → **Unregistered**; the rest ignored (escaped / not shortcode-like) |
| ICS-T06 (private) | nested tabs; `contact-form-7` (CF7 not installed), `my_test_box`, `front_only_box` | tabs **Compatible**; `contact-form-7` **Unregistered**; test shortcodes **Unknown**; `front_only_box` is found only thanks to the front-end registry |
| ICS-T07 (scheduled) | `vc_row css=".vc_custom_1234{…}"` | **Compatible** + note `legacy_wpb_design_css`; assessment at least *Manual review required* |
| ICS-T08 (Reusable Block) | `us_btn`, `vc_btn` | Compatible / Incompatible |
| ICS-T09 (Page Template) | `us_post_title`, `us_post_content` | **Compatible** |
| ICS-T10 (pending, malformed) | unclosed `vc_row`, broken `[ultimate_heading` | no crash; well-formed tags counted, broken tag ignored |
| ICS-T11 (meta) | ACF-like `intro_text`, serialized `blocks`, protected `_internal` | `meta:intro_text` and `meta:blocks` reported; `_internal` skipped |
| ICS-T12 (`vc_grid_item`) | `vc_gitem*` structure | **Incompatible**; still found when WPBakery is off (unregistered post type) |
| ICS-T13 (trash) | `vc_custom_heading` | not reported |
| ICS-T14 | `vc_btn` in excerpt | location `post_excerpt` |
| Widgets | Text widget with `vc_btn`, Custom HTML with `old_plugin_box` (inactive) | location `widget:text-990`, status inactive |
| Option | WPBakery template with `vc_pie` | location `option:wpb_js_templates:ics_test_tpl` |

### 6.4 Further checks performed

* **Read-only**: checksums of `wp_posts`, `wp_postmeta`, `wp_users`, `wp_usermeta`, `wp_terms`, `wp_term_relationships` and all non-plugin options were identical before and after a scan.
* **Performance**: 3,017 posts with ~6 KB of nested shortcodes each (600,000 occurrences) scanned in ~2 seconds of server time; with a forced 0.05 s budget the scan ran in 47 batches.
* **Resume**: rows written by a simulated interrupted batch were removed on resume; final counts were exact (no duplicates).
* **Cancel**: partial rows deleted, previous completed scan kept.
* **Permissions**: editor → page 403, AJAX 403, probe 403; logged-out → probe 403, export 400; bad nonce → 403.
* **CSV**: UTF-8 BOM, 19 columns, title `=HYPERLINK(…)` exported as `'=HYPERLINK(…)`.

### 6.5 Suggested acceptance test on a real site

1. Back up the site; use a staging copy.
2. Scan with WPBakery active. Export the CSV.
3. For each **Incompatible** shortcode, plan a replacement with an Impreza element.
4. For each **Unknown** shortcode, open two or three listed pages, deactivate WPBakery on staging and check the output visually.
5. Scan again with WPBakery inactive and compare.

## 7. Contributing

* **Misclassified shortcode?** Open an issue with the *Misclassified shortcode* template: tag, classification shown, the evidence from the "By shortcode" view, plugin versions, and what actually happens with WPBakery deactivated.
* **New verified add-on dependency?** Add it to `data/wpbakery-dependent-addons.php` with the version and the exact place in the add-on's code that proves the dependency. Lists are only extended with verified evidence.
* Pull requests: WordPress Coding Standards, PHP 7.4 compatible, no new runtime dependencies, keep the plugin strictly read-only.

## 8. File structure

```
impreza-compatibility-scanner.php   bootstrap, constants, autoloader
uninstall.php                       removes plugin tables/options
includes/
  class-plugin.php                  hooks, capability, AJAX endpoints
  class-environment.php             theme / US Core / WPBakery status, US config access (read-only)
  class-registry.php                handler inspection + front-end registry probe
  class-content-discovery.php       batched discovery of posts, meta, widgets, options
  class-shortcode-extractor.php     WordPress-grammar parsing, nesting, notes
  class-tag-context.php             facts about one tag
  class-verdict.php                 classification value object
  class-classifier.php              rule runner
  class-scan-manager.php            start / batch / cancel / resume / finalize / assessment
  class-results-store.php           plugin tables, queries
  rules/                            one class per rule (interface-rule.php)
  admin/                            admin page, list tables, CSV exporter
data/                               verified tag lists (WPBakery 9.2, Ultimate Addons 3.21.6)
assets/                             admin.js (no jQuery), admin.css
docs/FINDINGS.md                    investigation of Impreza / US Core / WPBakery internals
tests/                              fixtures for manual testing
```
