<?php
/**
 * Plugin Name:       MagicFields
 * Plugin URI:        https://github.com/Jacckisback/magicfields
 * Description:       Custom fields for the block editor era: build field groups visually, attach them to posts, terms, users and options pages, and display their values with blocks, block bindings, shortcodes or PHP.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            GustinAI
 * Author URI:        https://gustinai.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       magicfields
 * Domain Path:       /languages
 *
 * @package MagicFields
 */

defined( 'ABSPATH' ) || exit;

define( 'MAGICFIELDS_VERSION', '1.1.0' );
define( 'MAGICFIELDS_FILE', __FILE__ );
define( 'MAGICFIELDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAGICFIELDS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'MagicFields\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = MAGICFIELDS_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

require MAGICFIELDS_DIR . 'includes/api.php';

MagicFields\Plugin::boot();
