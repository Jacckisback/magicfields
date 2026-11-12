<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * Field group storage: database groups (one post each) plus groups registered in PHP.
 */
final class Groups {

	const POST_TYPE = 'magicfields_group';
	const META      = '_magicfields_config';

	/** @var array<string,array> */
	private static $local = array();

	/** @var array<string,array>|null */
	private static $db = null;

	/** @var array<string,array> Results of all(), by arguments. */
	private static $cache = array();

	/**
	 * Registers a group from code. It is read-only in the admin.
	 */
	public static function register_local( array $group ): void {
		$group = self::sanitize( $group, true );
		if ( '' === $group['key'] ) {
			return;
		}
		$group['local']               = true;
		self::$local[ $group['key'] ] = $group;
		self::$cache                  = array();
	}

	/**
	 * @return array<string,array> All groups, keyed by group key, ordered.
	 */
	public static function all( bool $only_active = true, bool $expand = true ): array {
		$cache_key = (int) $only_active . '-' . (int) $expand;
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}
		if ( null === self::$db ) {
			self::$db = array();
			$posts    = get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => array( 'publish', 'draft' ),
					'numberposts'      => -1,
					'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
					'suppress_filters' => true,
				)
			);
			foreach ( $posts as $post ) {
				$config = json_decode( (string) get_post_meta( $post->ID, self::META, true ), true );
				if ( ! is_array( $config ) ) {
					$config = array();
				}
				$config['id']     = $post->ID;
				$config['key']    = $post->post_name;
				$config['title']  = $post->post_title;
				$config['active'] = 'publish' === $post->post_status;
				$config['order']  = (int) $post->menu_order;
				$config          += array( 'fields' => array(), 'location' => array(), 'position' => 'normal', 'description' => '', 'show_in_rest' => false, 'block' => self::sanitize_block( array(), true ) );
				self::$db[ $post->post_name ] = $config;
			}
		}
		$all = self::$local + self::$db;
		if ( $only_active ) {
			$all = array_filter(
				$all,
				static function ( $g ) {
					return ! empty( $g['active'] );
				}
			);
		}
		uasort(
			$all,
			static function ( $a, $b ) {
				return array( (int) $a['order'], $a['title'] ) <=> array( (int) $b['order'], $b['title'] );
			}
		);
		if ( $expand ) {
			$source = $all;
			foreach ( $all as $key => $group ) {
				$all[ $key ]['fields'] = self::expand( $group['fields'], $source, array( $key => true ), 0 );
			}
		}
		self::$cache[ $cache_key ] = $all;
		return $all;
	}

	/**
	 * Resolves clone fields: they are replaced by the fields they point at
	 * (seamless) or become a group holding them.
	 */
	private static function expand( array $fields, array $all, array $seen, int $depth ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( 'clone' !== $field['type'] ) {
				if ( ! empty( $field['sub_fields'] ) ) {
					$field['sub_fields'] = self::expand( $field['sub_fields'], $all, $seen, $depth );
				}
				foreach ( (array) ( $field['layouts'] ?? array() ) as $i => $layout ) {
					$field['layouts'][ $i ]['sub_fields'] = self::expand( (array) $layout['sub_fields'], $all, $seen, $depth );
				}
				$out[] = $field;
				continue;
			}
			$resolved = array();
			foreach ( $depth < 5 ? (array) ( $field['clone'] ?? array() ) : array() as $target ) {
				if ( isset( $seen[ $target ] ) ) {
					continue; // A group or field cannot contain itself.
				}
				$next = $seen + array( $target => true );
				if ( isset( $all[ $target ] ) ) {
					$resolved = array_merge( $resolved, self::expand( $all[ $target ]['fields'], $all, $next, $depth + 1 ) );
					continue;
				}
				foreach ( $all as $group ) {
					$found = self::field_by_key( $group['fields'], $target );
					if ( $found ) {
						$resolved = array_merge( $resolved, self::expand( array( $found ), $all, $next, $depth + 1 ) );
						break;
					}
				}
			}
			if ( 'group' === ( $field['display'] ?? 'seamless' ) && '' !== $field['name'] ) {
				$field['type']       = 'group';
				$field['sub_fields'] = $resolved;
				unset( $field['clone'], $field['display'] );
				$out[] = $field;
			} else {
				$out = array_merge( $out, $resolved );
			}
		}
		return $out;
	}

	private static function field_by_key( array $fields, string $key ): ?array {
		foreach ( $fields as $field ) {
			if ( $field['key'] === $key ) {
				return $field;
			}
			$children = (array) ( $field['sub_fields'] ?? array() );
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				$children = array_merge( $children, (array) $layout['sub_fields'] );
			}
			$found = $children ? self::field_by_key( $children, $key ) : null;
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * A group as stored (clone fields not resolved).
	 */
	public static function get( string $key ): ?array {
		$all = self::all( false, false );
		return $all[ $key ] ?? null;
	}

	public static function flush(): void {
		self::$db    = null;
		self::$cache = array();
	}

	/**
	 * Creates or updates a database group. Returns the saved group or WP_Error.
	 */
	public static function save( array $data ) {
		$group = self::sanitize( $data, current_user_can( 'unfiltered_html' ) );
		if ( '' === $group['title'] ) {
			return new \WP_Error( 'magicfields_title', __( 'The field group needs a title.', 'magicfields' ), array( 'status' => 400 ) );
		}
		$dupe = self::duplicate_name( $group['fields'] );
		if ( $dupe ) {
			/* translators: %s: field name */
			return new \WP_Error( 'magicfields_duplicate', sprintf( __( 'Two fields at the same level share the name "%s".', 'magicfields' ), $dupe ), array( 'status' => 400 ) );
		}
		$existing = '' !== $group['key'] ? self::get( $group['key'] ) : null;
		if ( $existing && ! empty( $existing['local'] ) ) {
			return new \WP_Error( 'magicfields_local', __( 'This group is registered in code and cannot be edited here.', 'magicfields' ), array( 'status' => 400 ) );
		}
		if ( '' === $group['key'] ) {
			$group['key'] = 'group_' . strtolower( wp_generate_password( 12, false ) );
		}
		$postarr = array(
			'ID'          => $existing ? (int) $existing['id'] : 0,
			'post_type'   => self::POST_TYPE,
			'post_title'  => $group['title'],
			'post_name'   => $group['key'],
			'post_status' => $group['active'] ? 'publish' : 'draft',
			'menu_order'  => $group['order'],
		);
		$id      = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// The slug may have been made unique by WordPress: keep it as the key.
		$group['key'] = get_post_field( 'post_name', $id );
		$config       = array(
			'fields'      => $group['fields'],
			'location'    => $group['location'],
			'position'    => $group['position'],
			'description'  => $group['description'],
			'show_in_rest' => $group['show_in_rest'],
			'block'        => $group['block'],
		);
		update_post_meta( $id, self::META, wp_slash( wp_json_encode( $config ) ) );
		self::flush();
		return self::get( $group['key'] );
	}

	public static function delete( string $key ): bool {
		$group = self::get( $key );
		if ( ! $group || ! empty( $group['local'] ) ) {
			return false;
		}
		$done = (bool) wp_delete_post( (int) $group['id'], true );
		self::flush();
		return $done;
	}

	private static function duplicate_name( array $fields ): string {
		$seen = array();
		foreach ( $fields as $field ) {
			if ( FieldTypes::has_value( $field ) ) {
				if ( isset( $seen[ $field['name'] ] ) ) {
					return $field['name'];
				}
				$seen[ $field['name'] ] = true;
			}
			$lists = array( (array) ( $field['sub_fields'] ?? array() ) );
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				$lists[] = (array) $layout['sub_fields'];
			}
			foreach ( $lists as $list ) {
				$dupe = $list ? self::duplicate_name( $list ) : '';
				if ( $dupe ) {
					return $dupe;
				}
			}
		}
		return '';
	}

	public static function sanitize_name( $name ): string {
		$name = strtolower( remove_accents( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9_\-]+/', '_', $name );
		return trim( (string) $name, '_' );
	}

	/**
	 * Normalizes a group array (from the builder, an import or PHP).
	 */
	public static function sanitize( array $data, bool $trusted = false ): array {
		$location = array();
		foreach ( (array) ( $data['location'] ?? array() ) as $or ) {
			$rules = array();
			foreach ( (array) $or as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['param'] ) || ! isset( Locations::params()[ $rule['param'] ] ) ) {
					continue;
				}
				$rules[] = array(
					'param'    => $rule['param'],
					'operator' => '!=' === ( $rule['operator'] ?? '==' ) ? '!=' : '==',
					'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				);
			}
			if ( $rules ) {
				$location[] = $rules;
			}
		}
		return array(
			'key'         => sanitize_key( (string) ( $data['key'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'active'      => ! isset( $data['active'] ) || ! empty( $data['active'] ),
			'order'       => (int) ( $data['order'] ?? ( $data['menu_order'] ?? 0 ) ),
			'position'    => 'side' === ( $data['position'] ?? '' ) ? 'side' : 'normal',
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'fields'       => self::sanitize_fields( (array) ( $data['fields'] ?? array() ) ),
			'location'     => $location,
			'show_in_rest' => ! empty( $data['show_in_rest'] ),
			'block'        => self::sanitize_block( (array) ( $data['block'] ?? array() ), $trusted ),
		);
	}

	/**
	 * Settings that turn a group into a block of its own.
	 */
	private static function sanitize_block( array $block, bool $trusted ): array {
		$icon     = preg_replace( '/^dashicons-/', '', (string) ( $block['icon'] ?? '' ) );
		$template = (string) ( $block['template'] ?? '' );
		return array(
			'enabled'     => ! empty( $block['enabled'] ),
			'slug'        => trim( (string) preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( (string) ( $block['slug'] ?? '' ) ) ), '-' ),
			'icon'        => preg_match( '/^[a-z0-9\-]+$/', (string) $icon ) ? $icon : 'block-default',
			'category'    => sanitize_key( (string) ( $block['category'] ?? '' ) ) ?: 'widgets',
			'description' => sanitize_text_field( (string) ( $block['description'] ?? '' ) ),
			// The template is HTML written by an administrator, like a Custom HTML block.
			'template'    => $trusted ? $template : wp_kses_post( $template ),
		);
	}

	private static function sanitize_fields( array $fields, int $depth = 0 ): array {
		$out   = array();
		$types = FieldTypes::all();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = (string) ( $field['type'] ?? 'text' );
			if ( ! isset( $types[ $type ] ) ) {
				$type = 'text';
			}
			$label = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
			$name  = self::sanitize_name( $field['name'] ?? '' );
			if ( '' === $name ) {
				$name = str_replace( '-', '_', self::sanitize_name( $label ) );
			}
			if ( '' === $name && $types[ $type ]['has_value'] ) {
				continue;
			}
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key ) {
				$key = 'field_' . strtolower( wp_generate_password( 12, false ) );
			}
			$clean = array(
				'key'          => $key,
				'label'        => '' !== $label ? $label : $name,
				'name'         => $name,
				'type'         => $type,
				'instructions' => sanitize_textarea_field( (string) ( $field['instructions'] ?? '' ) ),
				'required'     => ! empty( $field['required'] ),
				'width'        => min( 100, max( 0, (int) ( $field['width'] ?? 0 ) ) ),
				'conditions'   => array(),
			);
			foreach ( (array) ( $field['conditions'] ?? array() ) as $cond ) {
				if ( ! is_array( $cond ) || empty( $cond['field'] ) ) {
					continue;
				}
				$op                    = (string) ( $cond['operator'] ?? '==' );
				$clean['conditions'][] = array(
					'field'    => self::sanitize_name( $cond['field'] ),
					'operator' => in_array( $op, array( '==', '!=', 'empty', 'not_empty', 'contains' ), true ) ? $op : '==',
					'value'    => sanitize_text_field( (string) ( $cond['value'] ?? '' ) ),
				);
			}
			foreach ( $types[ $type ]['settings'] as $setting ) {
				$sname = $setting['name'];
				$raw   = array_key_exists( $sname, $field ) ? $field[ $sname ] : ( $setting['default'] ?? null );
				$clean[ $sname ] = self::sanitize_setting( $setting, $raw, $type );
			}
			if ( $types[ $type ]['nested'] ) {
				$clean['sub_fields'] = $depth < 6 ? self::sanitize_fields( (array) ( $field['sub_fields'] ?? array() ), $depth + 1 ) : array();
			}
			if ( $types[ $type ]['layouts'] ) {
				$clean['layouts'] = array();
				$used             = array();
				foreach ( $depth < 6 ? (array) ( $field['layouts'] ?? array() ) : array() as $layout ) {
					if ( ! is_array( $layout ) ) {
						continue;
					}
					$llabel = sanitize_text_field( (string) ( $layout['label'] ?? '' ) );
					$lname  = self::sanitize_name( $layout['name'] ?? '' );
					if ( '' === $lname ) {
						$lname = str_replace( '-', '_', self::sanitize_name( $llabel ) );
					}
					if ( '' === $lname || isset( $used[ $lname ] ) ) {
						continue;
					}
					$used[ $lname ]     = true;
					$clean['layouts'][] = array(
						'key'        => sanitize_key( (string) ( $layout['key'] ?? '' ) ) ?: 'layout_' . strtolower( wp_generate_password( 12, false ) ),
						'name'       => $lname,
						'label'      => '' !== $llabel ? $llabel : $lname,
						'sub_fields' => self::sanitize_fields( (array) ( $layout['sub_fields'] ?? array() ), $depth + 1 ),
					);
				}
			}
			$out[] = $clean;
		}
		return $out;
	}

	private static function sanitize_setting( array $setting, $raw, string $type ) {
		switch ( $setting['control'] ) {
			case 'toggle':
				return ! empty( $raw );
			case 'number':
				return is_numeric( $raw ) ? $raw + 0 : '';
			case 'textarea':
				return 'message' === $type || 'wysiwyg' === $type ? wp_kses_post( (string) $raw ) : sanitize_textarea_field( (string) $raw );
			case 'select':
				return isset( $setting['options'][ (string) $raw ] ) ? (string) $raw : (string) ( $setting['default'] ?? '' );
			case 'choices':
				$choices = array();
				if ( is_string( $raw ) ) {
					$lines = preg_split( '/\r\n|\r|\n/', $raw );
					$raw   = array();
					foreach ( $lines as $line ) {
						$parts = array_map( 'trim', explode( ' : ', $line, 2 ) );
						$raw[] = array( 'value' => $parts[0], 'label' => $parts[1] ?? $parts[0] );
					}
				}
				foreach ( (array) $raw as $value => $choice ) {
					if ( ! is_array( $choice ) ) {
						// ACF style: value => label.
						$choice = array( 'value' => (string) $value, 'label' => (string) $choice );
					}
					$v = sanitize_text_field( (string) ( $choice['value'] ?? '' ) );
					if ( '' === $v ) {
						continue;
					}
					$l         = sanitize_text_field( (string) ( $choice['label'] ?? '' ) );
					$choices[] = array( 'value' => $v, 'label' => '' !== $l ? $l : $v );
				}
				return $choices;
			case 'clone':
			case 'post_types':
			case 'roles':
				return array_values( array_filter( array_map( 'sanitize_key', (array) $raw ) ) );
			case 'taxonomy':
				return sanitize_key( (string) $raw );
			default:
				return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
		}
	}

	/**
	 * Finds the field of that name for an object: first in the groups shown
	 * on that object, then anywhere (two groups may reuse a field name).
	 */
	public static function field_for( string $name, array $ctx ): ?array {
		foreach ( Locations::groups_for( Locations::info_for( $ctx ) ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( $field['name'] === $name && FieldTypes::has_value( $field ) ) {
					return $field;
				}
			}
		}
		return self::find_field( $name, $ctx['kind'] );
	}

	/**
	 * Finds a top-level field by name, preferring groups attached to the given kind of object.
	 */
	public static function find_field( string $name, string $kind = '' ): ?array {
		$fallback = null;
		foreach ( self::all() as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( $field['name'] !== $name || ! FieldTypes::has_value( $field ) ) {
					continue;
				}
				if ( '' === $kind || Locations::group_targets( $group, $kind ) ) {
					return $field;
				}
				$fallback = $fallback ?? $field;
			}
		}
		return $fallback;
	}
}
