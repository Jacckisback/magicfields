<?php
/**
 * ACF-style function names, so existing templates keep working once ACF is
 * removed. Loaded late and only for names nobody else defined.
 *
 * @package MagicFields
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'get_field' ) ) {
	function get_field( $selector, $post_id = false, $format_value = true ) {
		return magicfields_get( (string) $selector, $post_id, (bool) $format_value );
	}
}

if ( ! function_exists( 'the_field' ) ) {
	function the_field( $selector, $post_id = false ) {
		$value = magicfields_get( (string) $selector, $post_id );
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
		}
		echo is_scalar( $value ) ? wp_kses_post( (string) $value ) : '';
	}
}

if ( ! function_exists( 'update_field' ) ) {
	function update_field( $selector, $value, $post_id = false ) {
		return magicfields_update( (string) $selector, $value, $post_id );
	}
}

if ( ! function_exists( 'get_sub_field' ) && ! function_exists( 'have_rows' ) ) {
	/**
	 * Minimal have_rows() / the_row() / get_sub_field() loop over repeaters.
	 */
	function magicfields_rows_stack( $set = null ) {
		static $stack = array();
		if ( null !== $set ) {
			$stack = $set;
		}
		return $stack;
	}

	function have_rows( $selector, $post_id = false ) {
		$stack = magicfields_rows_stack();
		$top   = end( $stack );
		$id    = $selector . '|' . ( is_scalar( $post_id ) ? (string) $post_id : spl_object_id( $post_id ) );
		if ( ! $top || $top['id'] !== $id ) {
			$parent = $top && $top['i'] >= 0 ? ( $top['rows'][ $top['i'] ][ $selector ] ?? null ) : null;
			$rows   = is_array( $parent ) ? $parent : magicfields_get( (string) $selector, $post_id );
			if ( ! is_array( $rows ) || ! $rows ) {
				return false;
			}
			$stack[] = array( 'id' => $id, 'rows' => array_values( $rows ), 'i' => -1 );
			magicfields_rows_stack( $stack );
			return true;
		}
		if ( $top['i'] + 1 < count( $top['rows'] ) ) {
			return true;
		}
		array_pop( $stack );
		magicfields_rows_stack( $stack );
		return false;
	}

	function the_row() {
		$stack = magicfields_rows_stack();
		if ( ! $stack ) {
			return false;
		}
		$stack[ count( $stack ) - 1 ]['i']++;
		magicfields_rows_stack( $stack );
		$top = end( $stack );
		return $top['rows'][ $top['i'] ] ?? false;
	}

	function get_sub_field( $selector ) {
		$stack = magicfields_rows_stack();
		$top   = end( $stack );
		return $top && $top['i'] >= 0 ? ( $top['rows'][ $top['i'] ][ $selector ] ?? null ) : null;
	}

	function get_row_layout() {
		return get_sub_field( 'acf_fc_layout' );
	}

	function the_sub_field( $selector ) {
		$value = get_sub_field( $selector );
		echo is_scalar( $value ) ? wp_kses_post( (string) $value ) : '';
	}
}
