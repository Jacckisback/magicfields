<?php
namespace MagicFields\Admin;

use MagicFields\Locations;
use MagicFields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Options pages: admin screens that hold site-wide field values.
 */
final class OptionsPages {

	const OPTION = 'magicfields_options_pages';

	/** @var array<string,array> */
	private static $local = array();

	public static function hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_magicfields_save_options', array( __CLASS__, 'handle_save' ) );
	}

	public static function register_local( array $page ): void {
		$page = self::sanitize( $page );
		if ( $page ) {
			$page['local']                = true;
			self::$local[ $page['slug'] ] = $page;
		}
	}

	private static function sanitize( array $page ): ?array {
		$title = sanitize_text_field( (string) ( $page['title'] ?? ( $page['page_title'] ?? '' ) ) );
		$slug  = sanitize_key( (string) ( $page['slug'] ?? ( $page['menu_slug'] ?? '' ) ) );
		if ( '' === $slug ) {
			$slug = sanitize_key( sanitize_title( $title ) );
		}
		if ( '' === $slug || '' === $title ) {
			return null;
		}
		$icon = (string) ( $page['icon'] ?? ( $page['icon_url'] ?? '' ) );
		return array(
			'slug'       => $slug,
			'title'      => $title,
			'menu_title' => sanitize_text_field( (string) ( $page['menu_title'] ?? '' ) ) ?: $title,
			'parent'     => sanitize_text_field( (string) ( $page['parent'] ?? ( $page['parent_slug'] ?? '' ) ) ),
			'capability' => sanitize_key( (string) ( $page['capability'] ?? '' ) ) ?: 'manage_options',
			'icon'       => preg_match( '/^dashicons-[a-z0-9\-]+$/', $icon ) ? $icon : 'dashicons-admin-generic',
			'position'   => is_numeric( $page['position'] ?? '' ) ? (int) $page['position'] : 82,
		);
	}

	public static function stored(): array {
		$pages = get_option( self::OPTION, array() );
		return is_array( $pages ) ? array_values( $pages ) : array();
	}

	public static function save( array $pages ): array {
		$clean = array();
		foreach ( $pages as $page ) {
			$page = is_array( $page ) ? self::sanitize( $page ) : null;
			if ( $page ) {
				$clean[ $page['slug'] ] = $page;
			}
		}
		update_option( self::OPTION, array_values( $clean ), false );
		return array_values( $clean );
	}

	/**
	 * @return array<string,array>
	 */
	public static function all(): array {
		$all = self::$local;
		foreach ( self::stored() as $page ) {
			if ( ! isset( $all[ $page['slug'] ] ) ) {
				$all[ $page['slug'] ] = $page;
			}
		}
		return $all;
	}

	private static function menu_slug( string $slug ): string {
		return 'magicfields-options-' . $slug;
	}

	public static function menu(): void {
		$all = self::all();
		foreach ( $all as $page ) {
			$render = static function () use ( $page ) {
				self::render( $page );
			};
			$parent = $page['parent'];
			if ( isset( $all[ $parent ] ) ) {
				$parent = self::menu_slug( $parent );
			}
			if ( '' === $parent ) {
				$hook = add_menu_page( $page['title'], $page['menu_title'], $page['capability'], self::menu_slug( $page['slug'] ), $render, $page['icon'], $page['position'] );
			} else {
				$hook = add_submenu_page( $parent, $page['title'], $page['menu_title'], $page['capability'], self::menu_slug( $page['slug'] ), $render );
			}
			if ( $hook ) {
				add_action( 'load-' . $hook, array( Screens::class, 'enqueue_form_assets' ) );
			}
		}
	}

	private static function render( array $page ): void {
		$ctx    = array( 'kind' => 'option', 'id' => 0, 'page' => $page['slug'] );
		$groups = Locations::groups_for( Locations::info_for( $ctx ) );
		echo '<div class="wrap magicfields-wrap magicfields-options"><h1>' . esc_html( $page['title'] ) . '</h1>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Options saved.', 'magicfields' ) . '</p></div>';
		}
		if ( ! $groups ) {
			echo '<p>' . esc_html__( 'No field group is assigned to this options page yet. Create one and set its location to this page.', 'magicfields' ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="magicfields_save_options" /><input type="hidden" name="magicfields_page" value="' . esc_attr( $page['slug'] ) . '" />';
		Screens::render_form( $groups, $ctx, 'card' );
		submit_button( __( 'Save options', 'magicfields' ) );
		echo '</form></div>';
	}

	public static function handle_save(): void {
		$slug = sanitize_key( (string) ( $_POST['magicfields_page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$all  = self::all();
		if ( ! isset( $all[ $slug ] ) || ! current_user_can( $all[ $slug ]['capability'] ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'magicfields' ), 403 );
		}
		$data = Screens::posted_values();
		if ( null === $data ) {
			wp_die( esc_html__( 'The link has expired. Please reload the page and try again.', 'magicfields' ), 403 );
		}
		$ctx = array( 'kind' => 'option', 'id' => 0, 'page' => $slug );
		Values::save( $ctx, Locations::groups_for( Locations::info_for( $ctx ) ), $data );
		wp_safe_redirect( add_query_arg( array( 'page' => self::menu_slug( $slug ), 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
