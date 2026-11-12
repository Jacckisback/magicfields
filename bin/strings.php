<?php
/**
 * Lists the translatable strings of the plugin (PHP and JS), and reports
 * the ones missing from a translation file.
 *
 *   php bin/strings.php            # print every string
 *   php bin/strings.php fr_FR      # print strings missing from languages/magicfields-fr_FR.l10n.php
 */
$root    = dirname( __DIR__ );
$strings = array();
$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	$path = $file->getPathname();
	if ( ! preg_match( '/\.(php|js)$/', $path ) || preg_match( '#/(tests|bin|languages|dist)/#', $path ) ) {
		continue;
	}
	$code = file_get_contents( $path );
	// __( '…', 'magicfields' ), esc_html__( … ), __( '…', TD ), and both forms of _n( … ).
	preg_match_all( "/\\b(?:__|esc_html__|esc_attr__|_n)\\(\\s*(?:\\/\\*.*?\\*\\/\\s*)?'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*(?:'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*[^,]+,\\s*)?(?:'magicfields'|TD)\\s*\\)/s", $code, $m, PREG_SET_ORDER );
	foreach ( $m as $hit ) {
		$strings[ stripslashes( $hit[1] ) ] = true;
	}
}
$strings = array_keys( $strings );
sort( $strings );
if ( isset( $argv[1] ) ) {
	$file = "$root/languages/magicfields-{$argv[1]}.l10n.php";
	$data = is_readable( $file ) ? include $file : array( 'messages' => array() );
	$missing = array_diff( $strings, array_keys( $data['messages'] ) );
	$unused  = array_diff( array_keys( $data['messages'] ), $strings );
	echo count( $strings ) . ' strings, ' . count( $missing ) . ' missing, ' . count( $unused ) . " unused\n";
	foreach ( $missing as $s ) {
		echo "MISSING  $s\n";
	}
	foreach ( $unused as $s ) {
		echo "UNUSED   $s\n";
	}
	exit( $missing ? 1 : 0 );
}
echo implode( "\n", $strings ) . "\n";
