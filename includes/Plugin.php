<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 */
final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'init' ), 5 );
		add_action( 'plugins_loaded', array( __CLASS__, 'compat_functions' ), 20 );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		Frontend::hooks();
		Blocks::hooks();
		if ( is_admin() ) {
			Admin\Builder::hooks();
			Admin\Screens::hooks();
			Admin\OptionsPages::hooks();
		}
	}

	public static function init(): void {
		load_plugin_textdomain( 'magicfields', false, dirname( plugin_basename( MAGICFIELDS_FILE ) ) . '/languages' );
		self::register_assets();
		register_post_type(
			Groups::POST_TYPE,
			array(
				'label'        => 'MagicFields groups',
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
				'supports'     => array( 'title' ),
				'can_export'   => true,
			)
		);
	}

	/**
	 * Defines ACF-style helpers so templates written for ACF keep working,
	 * unless ACF (or anything else) already defines them.
	 */
	public static function compat_functions(): void {
		if ( ! apply_filters( 'magicfields_acf_compat', true ) ) {
			return;
		}
		require MAGICFIELDS_DIR . 'includes/compat.php';
	}

	public static function capability(): string {
		return (string) apply_filters( 'magicfields_capability', 'manage_options' );
	}

	/**
	 * JS translations are served from the same PHP translation file as the
	 * PHP strings, so the plugin needs no build step.
	 */
	public static function js_locale_data(): array {
		$file = MAGICFIELDS_DIR . 'languages/magicfields-' . determine_locale() . '.l10n.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = include $file;
		$out  = array(
			'' => array(
				'domain'       => 'magicfields',
				'lang'         => (string) ( $data['language'] ?? '' ),
				'plural-forms' => (string) ( $data['plural-forms'] ?? 'nplurals=2; plural=(n != 1);' ),
			),
		);
		foreach ( (array) ( $data['messages'] ?? array() ) as $source => $translation ) {
			$out[ $source ] = explode( "\0", (string) $translation );
		}
		return $out;
	}

	public static function register_assets(): void {
		$deps = array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'wp-core-data', 'wp-url' );
		wp_register_style( 'magicfields-admin', MAGICFIELDS_URL . 'assets/css/admin.css', array( 'wp-components' ), MAGICFIELDS_VERSION );
		wp_register_script( 'magicfields-fields', MAGICFIELDS_URL . 'assets/js/fields.js', $deps, MAGICFIELDS_VERSION, true );
		$locale = self::js_locale_data();
		if ( $locale ) {
			wp_add_inline_script( 'magicfields-fields', 'wp.i18n.setLocaleData(' . wp_json_encode( $locale ) . ", 'magicfields');", 'before' );
		}
		wp_register_script( 'magicfields-form', MAGICFIELDS_URL . 'assets/js/form.js', array( 'magicfields-fields' ), MAGICFIELDS_VERSION, true );
		wp_register_script( 'magicfields-editor', MAGICFIELDS_URL . 'assets/js/editor.js', array( 'magicfields-fields', 'wp-blocks', 'wp-block-editor', 'wp-server-side-render', 'wp-dom-ready', 'wp-hooks', 'wp-compose' ), MAGICFIELDS_VERSION, true );
		wp_register_script( 'magicfields-builder', MAGICFIELDS_URL . 'assets/js/builder.js', array( 'magicfields-fields' ), MAGICFIELDS_VERSION, true );
	}
}
