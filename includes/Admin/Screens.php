<?php
namespace MagicFields\Admin;

use MagicFields\Blocks;
use MagicFields\Groups;
use MagicFields\Locations;
use MagicFields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Shows field groups on edit screens (posts, terms, users) and saves them.
 *
 * In the block editor values travel with the post through the REST API; on
 * every other screen they are posted as one JSON field.
 */
final class Screens {

	const NONCE = 'magicfields_nonce';

	/** @var bool */
	private static $form_printed = false;

	public static function hooks(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );

		add_action( 'admin_init', array( __CLASS__, 'taxonomy_hooks' ), 99 );
		add_action( 'created_term', array( __CLASS__, 'save_term' ) );
		add_action( 'edited_term', array( __CLASS__, 'save_term' ) );

		add_action( 'show_user_profile', array( __CLASS__, 'user_form' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_form' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user' ) );
	}

	public static function styles( string $hook ): void {
		if ( in_array( $hook, array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php', 'profile.php', 'user-edit.php' ), true ) ) {
			wp_enqueue_style( 'magicfields-admin' );
		}
	}

	public static function enqueue_form_assets(): void {
		add_action(
			'admin_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'magicfields-admin' );
			}
		);
	}

	/**
	 * Prints the mount points of a non-block-editor form and hands the data to JS.
	 *
	 * @param string $style "card" wraps each group in a titled box, "plain" prints a heading, "none" prints nothing around it.
	 */
	public static function render_form( array $groups, array $ctx, string $style = 'plain' ): void {
		if ( ! $groups ) {
			return;
		}
		self::print_form_fields( $groups, $ctx );
		foreach ( $groups as $group ) {
			if ( 'card' === $style ) {
				echo '<div class="postbox magicfields-card"><div class="postbox-header"><h2>' . esc_html( $group['title'] ) . '</h2></div><div class="inside">';
			} elseif ( 'plain' === $style ) {
				echo '<h2>' . esc_html( $group['title'] ) . '</h2>';
			}
			self::mount( $group );
			if ( 'card' === $style ) {
				echo '</div></div>';
			}
		}
	}

	private static function mount( array $group ): void {
		echo '<div class="magicfields-mount" id="magicfields-mount-' . esc_attr( $group['key'] ) . '"></div>';
	}

	/**
	 * Hidden JSON field + nonce, and the script data. Printed once per screen.
	 */
	private static function print_form_fields( array $groups, array $ctx ): void {
		if ( self::$form_printed ) {
			return;
		}
		self::$form_printed = true;
		wp_nonce_field( 'magicfields_save', self::NONCE, false );
		echo '<input type="hidden" name="magicfields_values" id="magicfields-values" value="" />';
		wp_enqueue_media();
		wp_enqueue_editor();
		wp_enqueue_style( 'magicfields-admin' );
		wp_enqueue_script( 'magicfields-form' );
		$data = array(
			'groups' => array_values( $groups ),
			'values' => (object) Values::all_raw( $ctx, $groups ),
		);
		wp_add_inline_script( 'magicfields-form', 'window.magicfieldsForm = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Values posted by a non-block-editor form, or null when the request is not ours.
	 */
	public static function posted_values(): ?array {
		if ( ! isset( $_POST[ self::NONCE ], $_POST['magicfields_values'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), 'magicfields_save' ) ) {
			return null;
		}
		// Sanitized field by field in Values::save().
		$data = json_decode( (string) wp_unslash( $_POST['magicfields_values'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return is_array( $data ) ? $data : null;
	}

	/* ---------- Posts ---------- */

	public static function meta_boxes( $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$screen = get_current_screen();
		$block  = $screen && $screen->is_block_editor();
		$groups = Locations::groups_for( Locations::post_info( $post ), $block );
		if ( ! $groups ) {
			return;
		}
		$ctx = array( 'kind' => 'post', 'id' => (int) $post->ID );
		foreach ( $groups as $group ) {
			if ( $block && 'side' === $group['position'] ) {
				continue; // Rendered as a sidebar panel by editor.js.
			}
			add_meta_box(
				'magicfields-' . $group['key'],
				$group['title'],
				static function () use ( $group, $groups, $ctx, $block ) {
					if ( ! $block ) {
						self::print_form_fields( $groups, $ctx );
					}
					self::mount( $group );
				},
				$post_type,
				$group['position'],
				'high',
				array( '__block_editor_compatible_meta_box' => true )
			);
		}
	}

	public static function block_editor(): void {
		$screen = get_current_screen();
		$post   = get_post();
		$groups = array();
		if ( $screen && 'post' === $screen->base && $post ) {
			$groups = Locations::groups_for( Locations::post_info( $post ), true );
			wp_enqueue_editor();
		}
		$fields = array();
		foreach ( Groups::all() as $group ) {
			if ( ! Locations::group_targets( $group, 'post' ) && ! Locations::group_targets( $group, 'option' ) ) {
				continue;
			}
			foreach ( $group['fields'] as $field ) {
				if ( ! in_array( $field['type'], array( 'tab', 'message', 'repeater', 'flexible', 'group', 'password' ), true ) ) {
					$fields[ $field['name'] ] = array( 'name' => $field['name'], 'label' => $field['label'], 'type' => $field['type'], 'group' => $group['title'] );
				}
			}
		}
		$blocks = array();
		foreach ( Blocks::custom_blocks() as $name => $group ) {
			$blocks[] = array( 'name' => $name, 'group' => $group );
		}
		$block_groups = Blocks::block_groups();
		if ( $blocks || $block_groups ) {
			wp_enqueue_editor();
			wp_enqueue_media();
		}
		wp_enqueue_style( 'magicfields-admin' );
		wp_enqueue_script( 'magicfields-editor' );
		wp_add_inline_script(
			'magicfields-editor',
			'window.magicfieldsEditor = ' . wp_json_encode(
				array(
					'groups'      => array_values( $groups ),
					'fields'      => array_values( $fields ),
					'blocks'      => $blocks,
					'blockGroups' => $block_groups,
				)
			) . ';',
			'before'
		);
	}

	public static function save_post( $post_id, $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( (int) ( $_POST['post_ID'] ?? 0 ) !== (int) $post_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$data = self::posted_values();
		if ( null === $data || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		Values::save( array( 'kind' => 'post', 'id' => (int) $post_id ), Locations::groups_for( Locations::post_info( $post ) ), $data );
	}

	/* ---------- Terms ---------- */

	public static function taxonomy_hooks(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form", array( __CLASS__, 'term_edit_form' ), 10, 2 );
			add_action( "{$taxonomy}_add_form_fields", array( __CLASS__, 'term_add_form' ) );
		}
	}

	public static function term_edit_form( $term, $taxonomy ): void {
		self::render_form(
			Locations::groups_for( array( 'kind' => 'term', 'taxonomy' => $taxonomy ) ),
			array( 'kind' => 'term', 'id' => (int) $term->term_id )
		);
	}

	public static function term_add_form( $taxonomy ): void {
		echo '<div class="magicfields-term-add">';
		self::render_form(
			Locations::groups_for( array( 'kind' => 'term', 'taxonomy' => $taxonomy ) ),
			array( 'kind' => 'term', 'id' => 0 )
		);
		echo '</div>';
	}

	public static function save_term( $term_id ): void {
		$term = get_term( $term_id );
		$data = self::posted_values();
		if ( null === $data || ! $term instanceof \WP_Term || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		Values::save( array( 'kind' => 'term', 'id' => (int) $term_id ), Locations::groups_for( array( 'kind' => 'term', 'taxonomy' => $term->taxonomy ) ), $data );
	}

	/* ---------- Users ---------- */

	public static function user_form( $user ): void {
		$ctx = array( 'kind' => 'user', 'id' => (int) $user->ID );
		self::render_form( Locations::groups_for( Locations::info_for( $ctx ) ), $ctx );
	}

	public static function save_user( $user_id ): void {
		$data = self::posted_values();
		if ( null === $data || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$ctx = array( 'kind' => 'user', 'id' => (int) $user_id );
		Values::save( $ctx, Locations::groups_for( Locations::info_for( $ctx ) ), $data );
	}
}
