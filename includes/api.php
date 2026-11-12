<?php
/**
 * Public functions for themes and plugins.
 *
 * @package MagicFields
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the value of a field.
 *
 * @param string $name   Field name.
 * @param mixed  $object Null for the current post, a post ID, a WP_Post / WP_Term / WP_User,
 *                       "option" for options pages, or "term_12" / "user_3".
 * @param bool   $format Format the value as configured (image arrays, objects…) or return it as stored.
 */
function magicfields_get( string $name, $object = null, bool $format = true ) {
	return MagicFields\Values::get( $name, $object, $format );
}

/**
 * Prints a field as safe HTML (text is escaped, images become <img>, links become <a>…).
 */
function magicfields_the( string $name, $object = null ): void {
	echo MagicFields\Frontend::html( $name, $object ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field type.
}

/**
 * Returns a field as safe HTML.
 */
function magicfields_html( string $name, $object = null ): string {
	return MagicFields\Frontend::html( $name, $object );
}

/**
 * Saves a field value.
 */
function magicfields_update( string $name, $value, $object = null ): bool {
	return MagicFields\Values::set( $name, $value, $object );
}

/**
 * Values of the fields attached to a block ("Fields on blocks").
 *
 * @param array|WP_Block $block The block, e.g. the second argument of the "render_block" filter.
 * @return array Field name => formatted value.
 */
function magicfields_get_block_fields( $block ): array {
	return MagicFields\Blocks::block_fields( $block );
}

/**
 * Registers a field group from code. Call it on or before the "init" action.
 */
function magicfields_register_group( array $group ): void {
	MagicFields\Groups::register_local( $group );
}

/**
 * Registers an options page from code. Call it before the "admin_menu" action.
 */
function magicfields_add_options_page( array $page ): void {
	MagicFields\Admin\OptionsPages::register_local( $page );
}
