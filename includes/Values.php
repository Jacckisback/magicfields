<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes field values for posts, terms, users and options pages.
 *
 * Each top-level field is one meta entry (or one option named "options_{name}"),
 * under the field name: values stay readable with get_post_meta() and are
 * compatible with data created by ACF.
 */
final class Values {

	/**
	 * Turns "what the developer passed" into a context: kind + id.
	 *
	 * Accepts null (current post, or the queried term/author), a post ID,
	 * WP_Post / WP_Term / WP_User, "option", "term_12" or "user_3".
	 */
	public static function resolve( $object = null ): ?array {
		if ( null === $object || false === $object || '' === $object ) {
			$id = get_the_ID();
			if ( $id ) {
				return array( 'kind' => 'post', 'id' => (int) $id );
			}
			$object = get_queried_object();
			if ( ! $object ) {
				return null;
			}
		}
		if ( $object instanceof \WP_Post ) {
			return array( 'kind' => 'post', 'id' => (int) $object->ID );
		}
		if ( $object instanceof \WP_Term ) {
			return array( 'kind' => 'term', 'id' => (int) $object->term_id );
		}
		if ( $object instanceof \WP_User ) {
			return array( 'kind' => 'user', 'id' => (int) $object->ID );
		}
		if ( is_numeric( $object ) ) {
			return array( 'kind' => 'post', 'id' => (int) $object );
		}
		if ( is_string( $object ) ) {
			if ( 'option' === $object || 'options' === $object ) {
				return array( 'kind' => 'option', 'id' => 0 );
			}
			if ( preg_match( '/^(term|user)_(\d+)$/', $object, $m ) ) {
				return array( 'kind' => $m[1], 'id' => (int) $m[2] );
			}
			if ( preg_match( '/^[a-z0-9_\-]+_(\d+)$/', $object, $m ) ) {
				// ACF style "category_12".
				return array( 'kind' => 'term', 'id' => (int) $m[1] );
			}
		}
		return null;
	}

	private static function exists( array $ctx, string $name ): bool {
		if ( 'option' === $ctx['kind'] ) {
			return null !== get_option( 'options_' . $name, null );
		}
		return metadata_exists( $ctx['kind'], $ctx['id'], $name );
	}

	private static function read( array $ctx, string $name ) {
		if ( 'option' === $ctx['kind'] ) {
			return get_option( 'options_' . $name, '' );
		}
		return get_metadata( $ctx['kind'], $ctx['id'], $name, true );
	}

	private static function write( array $ctx, string $name, $value ): void {
		if ( 'option' === $ctx['kind'] ) {
			// Like metadata below, an emptied field keeps an empty entry.
			update_option( 'options_' . $name, FieldTypes::is_empty( $value ) ? '' : $value );
			return;
		}
		if ( FieldTypes::is_empty( $value ) ) {
			// Keep an empty entry so a default value does not come back.
			update_metadata( $ctx['kind'], $ctx['id'], $name, '' );
			return;
		}
		update_metadata( $ctx['kind'], $ctx['id'], $name, wp_slash( $value ) );
	}

	/**
	 * Raw stored value of a field, with its default when it was never saved.
	 * Also understands the flattened repeater/group layout written by ACF.
	 */
	public static function raw( array $ctx, array $field, string $name = '' ) {
		$name = '' !== $name ? $name : $field['name'];
		$type = $field['type'];
		$subs = (array) ( $field['sub_fields'] ?? array() );

		if ( ! self::exists( $ctx, $name ) ) {
			if ( 'group' === $type ) {
				$out   = array();
				$found = false;
				foreach ( $subs as $sub ) {
					if ( FieldTypes::has_value( $sub ) ) {
						$found               = $found || self::exists( $ctx, $name . '_' . $sub['name'] );
						$out[ $sub['name'] ] = self::raw( $ctx, $sub, $name . '_' . $sub['name'] );
					}
				}
				return $found ? $out : FieldTypes::default_value( $field );
			}
			return FieldTypes::default_value( $field );
		}

		$value = self::read( $ctx, $name );

		if ( 'repeater' === $type ) {
			if ( is_array( $value ) ) {
				return array_values( $value );
			}
			$rows = array();
			for ( $i = 0, $count = min( 500, absint( $value ) ); $i < $count; $i++ ) {
				$row = array();
				foreach ( $subs as $sub ) {
					if ( FieldTypes::has_value( $sub ) ) {
						$row[ $sub['name'] ] = self::raw( $ctx, $sub, "{$name}_{$i}_{$sub['name']}" );
					}
				}
				$rows[] = $row;
			}
			return $rows;
		}
		if ( 'flexible' === $type ) {
			if ( ! is_array( $value ) ) {
				return array();
			}
			$value = array_values( $value );
			if ( ! $value || is_array( $value[0] ) ) {
				return $value;
			}
			// ACF layout: a list of layout names, sub values in "{name}_{i}_{sub}".
			$rows = array();
			foreach ( $value as $i => $layout ) {
				$layout_fields = FieldTypes::row_fields( $field, $layout );
				if ( null === $layout_fields ) {
					continue;
				}
				$row = array( 'acf_fc_layout' => (string) $layout );
				foreach ( $layout_fields as $sub ) {
					if ( FieldTypes::has_value( $sub ) ) {
						$row[ $sub['name'] ] = self::raw( $ctx, $sub, "{$name}_{$i}_{$sub['name']}" );
					}
				}
				$rows[] = $row;
			}
			return $rows;
		}
		if ( 'group' === $type ) {
			if ( is_array( $value ) ) {
				return $value;
			}
			$out = array();
			foreach ( $subs as $sub ) {
				if ( FieldTypes::has_value( $sub ) ) {
					$out[ $sub['name'] ] = self::raw( $ctx, $sub, $name . '_' . $sub['name'] );
				}
			}
			return $out;
		}
		if ( 'date' === $type && is_string( $value ) && preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
			return "$m[1]-$m[2]-$m[3]";
		}
		if ( FieldTypes::is_multiple( $field ) ) {
			return FieldTypes::is_empty( $value ) ? array() : array_values( (array) $value );
		}
		return $value;
	}

	/**
	 * Raw values of every field of the given groups, keyed by field name.
	 */
	public static function all_raw( array $ctx, array $groups ): array {
		$out = array();
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( FieldTypes::has_value( $field ) ) {
					$out[ $field['name'] ] = self::raw( $ctx, $field );
				}
			}
		}
		return $out;
	}

	/**
	 * Saves the submitted values of the given groups. $data must be unslashed.
	 */
	public static function save( array $ctx, array $groups, array $data ): void {
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! FieldTypes::has_value( $field ) || ! array_key_exists( $field['name'], $data ) ) {
					continue;
				}
				self::write( $ctx, $field['name'], FieldTypes::sanitize( $field, $data[ $field['name'] ] ) );
			}
		}
		do_action( 'magicfields_saved', $ctx, $groups, $data );
	}

	/**
	 * Public getter behind magicfields_get().
	 */
	public static function get( string $name, $object = null, bool $format = true ) {
		$ctx = self::resolve( $object );
		if ( ! $ctx ) {
			return null;
		}
		$field = Groups::field_for( $name, $ctx );
		if ( ! $field ) {
			return self::exists( $ctx, $name ) ? self::read( $ctx, $name ) : null;
		}
		$raw   = self::raw( $ctx, $field );
		$value = $format ? FieldTypes::format( $field, $raw ) : $raw;
		return apply_filters( 'magicfields_value', $value, $field, $ctx, $raw );
	}

	public static function set( string $name, $value, $object = null ): bool {
		$ctx = self::resolve( $object );
		if ( ! $ctx ) {
			return false;
		}
		$field = Groups::field_for( $name, $ctx );
		self::write( $ctx, $name, $field ? FieldTypes::sanitize( $field, $value ) : $value );
		return true;
	}
}
