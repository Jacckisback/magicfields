=== MagicFields ===
Contributors: gustinai
Tags: custom fields, meta, block editor, gutenberg, acf
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom fields for the block editor: build field groups visually and display their values with blocks, block bindings, a shortcode or PHP.

== Description ==

MagicFields adds custom fields to posts, pages, custom post types, taxonomy terms, user profiles and options pages.

* 30 field types: text, text area, number, range, email, URL, password, rich text, image, file, gallery, link, select, checkboxes, radio buttons, button group, toggle, date, date and time, time, color, post, term, user, repeater, flexible content, group, clone, tab and message.
* Fields as blocks: turn a field group into a block of its own, with an HTML template and no PHP.
* Fields on blocks: add your fields to the settings of any existing block.
* Optional public values in the REST API, for headless sites.
* A visual builder: drag fields to reorder them, nest sub fields, set widths to place fields side by side, add conditional logic.
* Native to the block editor: values are saved with the post (one save, undo and "unsaved changes" just work), below the content or in the sidebar.
* Display without code: the "Field value" block, block bindings for Paragraph, Heading, Button and Image blocks, and the [magicfield] shortcode.
* Display with code: magicfields_get(), magicfields_the().
* Coming from Advanced Custom Fields: import ACF JSON exports. Values keep the same meta keys, so existing content is read as is, and get_field() / the_field() / have_rows() keep working in your templates once ACF is deactivated.
* No build step, no external service, no tracking.

== Installation ==

1. Upload the plugin and activate it.
2. Open "Fields" in the admin menu and add a field group.
3. Choose where the group appears, save, and edit a post.

== Frequently Asked Questions ==

= Where are values stored? =

Each field is one post, term or user meta entry named after the field. Options page values are stored as options named "options_{field name}".

= What happens to my data if I remove the plugin? =

Field groups are deleted; the values saved in your content are kept.

= Which ACF features are not converted on import? =

ACF blocks (their PHP templates), and a few field types such as Google Map. The import report lists everything that was skipped.

== Changelog ==

= 1.1.0 =
* New: flexible content and clone fields, also converted from ACF exports.
* New: field groups as blocks, with an HTML template.
* New: fields on existing blocks.
* New: optional public values in the REST API (posts, terms, users).

= 1.0.0 =
* First release.
