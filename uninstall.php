<?php
/**
 * Uninstall: removes the field groups and the plugin settings.
 *
 * The values saved in your content (post, term and user meta, "options_*"
 * options) are kept on purpose: they are your data, and stay readable with
 * get_post_meta() or another custom fields plugin.
 *
 * @package MagicFields
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$magicfields_groups = get_posts(
	array(
		'post_type'   => 'magicfields_group',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $magicfields_groups as $magicfields_id ) {
	wp_delete_post( $magicfields_id, true );
}
delete_option( 'magicfields_options_pages' );
