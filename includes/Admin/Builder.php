<?php
namespace MagicFields\Admin;

use MagicFields\FieldTypes;
use MagicFields\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The "Fields" admin screen: field group builder, options pages and tools.
 */
final class Builder {

	const SLUG = 'magicfields';

	public static function hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MAGICFIELDS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Field groups', 'magicfields' ),
			__( 'Fields', 'magicfields' ),
			Plugin::capability(),
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-feedback',
			81
		);
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Field groups', 'magicfields' ) . '</a>' );
		return $links;
	}

	public static function render(): void {
		echo '<div class="wrap magicfields-wrap"><div id="magicfields-builder"></div></div>';
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_editor();
		wp_enqueue_style( 'magicfields-admin' );
		wp_enqueue_script( 'magicfields-builder' );
		wp_add_inline_script( 'magicfields-builder', 'window.magicfieldsBuilder = ' . wp_json_encode( self::data() ) . ';', 'before' );
	}

	private static function data(): array {
		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ), true ) ) {
				continue;
			}
			$post_types[] = array( 'value' => $type->name, 'label' => $type->labels->singular_name );
		}
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$taxonomies[] = array( 'value' => $tax->name, 'label' => $tax->labels->singular_name );
		}
		$roles = array();
		foreach ( wp_roles()->roles as $name => $role ) {
			$roles[] = array( 'value' => $name, 'label' => translate_user_role( $role['name'] ) );
		}
		$templates = array( array( 'value' => 'default', 'label' => __( 'Default template', 'magicfields' ) ) );
		$seen      = array();
		foreach ( $post_types as $type ) {
			foreach ( wp_get_theme()->get_page_templates( null, $type['value'] ) as $file => $name ) {
				if ( ! isset( $seen[ $file ] ) ) {
					$seen[ $file ] = true;
					$templates[]   = array( 'value' => $file, 'label' => $name );
				}
			}
		}
		$block_types = array();
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $block ) {
			if ( 0 !== strpos( $block->name, 'magicfields/' ) ) {
				$block_types[] = array( 'value' => $block->name, 'label' => ( $block->title ?: $block->name ) . ' (' . $block->name . ')' );
			}
		}
		usort(
			$block_types,
			static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);
		$categories = array();
		foreach ( get_default_block_categories() as $category ) {
			$categories[] = array( 'value' => $category['slug'], 'label' => $category['title'] );
		}
		return array(
			'blockTypes'   => $block_types,
			'categories'   => $categories,
			'types'        => FieldTypes::all(),
			'postTypes'    => $post_types,
			'taxonomies'   => $taxonomies,
			'roles'        => $roles,
			'templates'    => $templates,
			'optionsPages' => array_values( OptionsPages::all() ),
			'menuParents'  => array(
				array( 'value' => '', 'label' => __( 'Top level menu', 'magicfields' ) ),
				array( 'value' => 'options-general.php', 'label' => __( 'Under Settings', 'magicfields' ) ),
				array( 'value' => 'themes.php', 'label' => __( 'Under Appearance', 'magicfields' ) ),
				array( 'value' => 'tools.php', 'label' => __( 'Under Tools', 'magicfields' ) ),
			),
			'adminUrl'     => admin_url(),
		);
	}
}
