<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * Field type registry: definition, sanitization and front-end formatting.
 */
final class FieldTypes {

	/** @var array|null */
	private static $types = null;

	/**
	 * All field types. Each: label, category, has_value, settings (builder schema).
	 */
	public static function all(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}
		$default     = array( 'name' => 'default', 'label' => __( 'Default value', 'magicfields' ), 'control' => 'text' );
		$placeholder = array( 'name' => 'placeholder', 'label' => __( 'Placeholder', 'magicfields' ), 'control' => 'text' );
		$choices     = array( 'name' => 'choices', 'label' => __( 'Choices', 'magicfields' ), 'control' => 'choices', 'help' => __( 'One choice per line. Use "value : Label" to store a value different from the label.', 'magicfields' ) );
		$multiple    = array( 'name' => 'multiple', 'label' => __( 'Allow multiple values', 'magicfields' ), 'control' => 'toggle' );
		$min         = array( 'name' => 'min', 'label' => __( 'Minimum', 'magicfields' ), 'control' => 'number' );
		$max         = array( 'name' => 'max', 'label' => __( 'Maximum', 'magicfields' ), 'control' => 'number' );
		$step        = array( 'name' => 'step', 'label' => __( 'Step', 'magicfields' ), 'control' => 'number' );
		$format      = array( 'name' => 'display_format', 'label' => __( 'Display format', 'magicfields' ), 'control' => 'text', 'help' => __( 'PHP date format used on the front end. Leave empty to return the stored value.', 'magicfields' ) );
		$media_return = array(
			'name'    => 'return_format',
			'label'   => __( 'Return format', 'magicfields' ),
			'control' => 'select',
			'default' => 'array',
			'options' => array(
				'array' => __( 'Array (id, url, alt, sizes…)', 'magicfields' ),
				'url'   => __( 'URL', 'magicfields' ),
				'id'    => __( 'ID', 'magicfields' ),
			),
		);
		$object_return = array(
			'name'    => 'return_format',
			'label'   => __( 'Return format', 'magicfields' ),
			'control' => 'select',
			'default' => 'object',
			'options' => array(
				'object' => __( 'Object', 'magicfields' ),
				'id'     => __( 'ID', 'magicfields' ),
			),
		);

		$basic    = __( 'Basic', 'magicfields' );
		$content  = __( 'Content', 'magicfields' );
		$choice   = __( 'Choice', 'magicfields' );
		$relation = __( 'Relational', 'magicfields' );
		$layout   = __( 'Layout', 'magicfields' );

		$types = array(
			'text'         => array( __( 'Text', 'magicfields' ), $basic, array( $default, $placeholder, array( 'name' => 'maxlength', 'label' => __( 'Character limit', 'magicfields' ), 'control' => 'number' ) ) ),
			'textarea'     => array( __( 'Text area', 'magicfields' ), $basic, array( array( 'name' => 'default', 'label' => __( 'Default value', 'magicfields' ), 'control' => 'textarea' ), $placeholder, array( 'name' => 'rows', 'label' => __( 'Rows', 'magicfields' ), 'control' => 'number', 'default' => 4 ) ) ),
			'number'       => array( __( 'Number', 'magicfields' ), $basic, array( $default, $placeholder, $min, $max, $step ) ),
			'range'        => array( __( 'Range', 'magicfields' ), $basic, array( $default, $min, $max, $step ) ),
			'email'        => array( __( 'Email', 'magicfields' ), $basic, array( $default, $placeholder ) ),
			'url'          => array( __( 'URL', 'magicfields' ), $basic, array( $default, $placeholder ) ),
			'password'     => array( __( 'Password', 'magicfields' ), $basic, array( $placeholder ) ),
			'wysiwyg'      => array( __( 'Rich text', 'magicfields' ), $content, array( array( 'name' => 'default', 'label' => __( 'Default value', 'magicfields' ), 'control' => 'textarea' ), array( 'name' => 'media_upload', 'label' => __( 'Show the "Add media" button', 'magicfields' ), 'control' => 'toggle', 'default' => true ) ) ),
			'image'        => array( __( 'Image', 'magicfields' ), $content, array( $media_return ) ),
			'file'         => array( __( 'File', 'magicfields' ), $content, array( $media_return ) ),
			'gallery'      => array( __( 'Gallery', 'magicfields' ), $content, array( $media_return, array( 'name' => 'max', 'label' => __( 'Maximum number of images', 'magicfields' ), 'control' => 'number' ) ) ),
			'link'         => array( __( 'Link', 'magicfields' ), $content, array() ),
			'select'       => array( __( 'Select', 'magicfields' ), $choice, array( $choices, $default, $multiple, array( 'name' => 'allow_null', 'label' => __( 'Allow an empty choice', 'magicfields' ), 'control' => 'toggle', 'default' => true ) ) ),
			'checkbox'     => array( __( 'Checkboxes', 'magicfields' ), $choice, array( $choices, $default ) ),
			'radio'        => array( __( 'Radio buttons', 'magicfields' ), $choice, array( $choices, $default ) ),
			'button_group' => array( __( 'Button group', 'magicfields' ), $choice, array( $choices, $default ) ),
			'true_false'   => array( __( 'Toggle', 'magicfields' ), $choice, array( array( 'name' => 'default', 'label' => __( 'On by default', 'magicfields' ), 'control' => 'toggle' ), array( 'name' => 'message', 'label' => __( 'Text next to the toggle', 'magicfields' ), 'control' => 'text' ) ) ),
			'date'         => array( __( 'Date', 'magicfields' ), $choice, array( $format ) ),
			'datetime'     => array( __( 'Date and time', 'magicfields' ), $choice, array( $format ) ),
			'time'         => array( __( 'Time', 'magicfields' ), $choice, array( $format ) ),
			'color'        => array( __( 'Color', 'magicfields' ), $choice, array( $default, array( 'name' => 'alpha', 'label' => __( 'Allow transparency', 'magicfields' ), 'control' => 'toggle' ) ) ),
			'post_object'  => array( __( 'Post', 'magicfields' ), $relation, array( array( 'name' => 'post_types', 'label' => __( 'Post types', 'magicfields' ), 'control' => 'post_types', 'help' => __( 'Leave empty for all public post types.', 'magicfields' ) ), $multiple, $object_return ) ),
			'taxonomy'     => array( __( 'Term', 'magicfields' ), $relation, array( array( 'name' => 'taxonomy', 'label' => __( 'Taxonomy', 'magicfields' ), 'control' => 'taxonomy', 'default' => 'category' ), $multiple, $object_return ) ),
			'user'         => array( __( 'User', 'magicfields' ), $relation, array( array( 'name' => 'roles', 'label' => __( 'Roles', 'magicfields' ), 'control' => 'roles', 'help' => __( 'Leave empty for all roles.', 'magicfields' ) ), $multiple, $object_return ) ),
			'repeater'     => array( __( 'Repeater', 'magicfields' ), $layout, array( array( 'name' => 'min', 'label' => __( 'Minimum rows', 'magicfields' ), 'control' => 'number' ), array( 'name' => 'max', 'label' => __( 'Maximum rows', 'magicfields' ), 'control' => 'number' ), array( 'name' => 'button_label', 'label' => __( 'Button label', 'magicfields' ), 'control' => 'text' ) ) ),
			'flexible'     => array( __( 'Flexible content', 'magicfields' ), $layout, array( array( 'name' => 'min', 'label' => __( 'Minimum rows', 'magicfields' ), 'control' => 'number' ), array( 'name' => 'max', 'label' => __( 'Maximum rows', 'magicfields' ), 'control' => 'number' ), array( 'name' => 'button_label', 'label' => __( 'Button label', 'magicfields' ), 'control' => 'text' ) ) ),
			'group'        => array( __( 'Group', 'magicfields' ), $layout, array() ),
			'clone'        => array(
				__( 'Clone', 'magicfields' ),
				$layout,
				array(
					array( 'name' => 'clone', 'label' => __( 'Fields to reuse', 'magicfields' ), 'control' => 'clone', 'help' => __( 'Whole field groups or single fields. They are edited in one place and reused here.', 'magicfields' ) ),
					array(
						'name'    => 'display',
						'label'   => __( 'Display', 'magicfields' ),
						'control' => 'select',
						'default' => 'seamless',
						'options' => array(
							'seamless' => __( 'Seamless: the fields replace this one', 'magicfields' ),
							'group'    => __( 'Group: the fields are stored together under this name', 'magicfields' ),
						),
					),
				),
			),
			'tab'          => array( __( 'Tab', 'magicfields' ), $layout, array() ),
			'message'      => array( __( 'Message', 'magicfields' ), $layout, array( array( 'name' => 'message', 'label' => __( 'Message', 'magicfields' ), 'control' => 'textarea' ) ) ),
		);

		self::$types = array();
		foreach ( $types as $name => $t ) {
			self::$types[ $name ] = array(
				'label'     => $t[0],
				'category'  => $t[1],
				'has_value' => ! in_array( $name, array( 'tab', 'message', 'clone' ), true ),
				'nested'    => in_array( $name, array( 'repeater', 'group' ), true ),
				'layouts'   => 'flexible' === $name,
				'settings'  => $t[2],
			);
		}
		return self::$types;
	}

	public static function exists( string $type ): bool {
		return isset( self::all()[ $type ] );
	}

	public static function has_value( array $field ): bool {
		$types = self::all();
		return ! empty( $types[ $field['type'] ?? '' ]['has_value'] );
	}

	public static function is_multiple( array $field ): bool {
		$type = $field['type'] ?? '';
		return in_array( $type, array( 'checkbox', 'gallery' ), true )
			|| ( in_array( $type, array( 'select', 'post_object', 'taxonomy', 'user' ), true ) && ! empty( $field['multiple'] ) );
	}

	private static function choice_values( array $field ): array {
		$out = array();
		foreach ( (array) ( $field['choices'] ?? array() ) as $choice ) {
			$out[] = (string) ( $choice['value'] ?? '' );
		}
		return $out;
	}

	/**
	 * Sub fields that apply to one row: for flexible content they depend on the row's layout.
	 *
	 * @return array|null Null when the row has no valid layout.
	 */
	public static function row_fields( array $field, $row ): ?array {
		if ( 'flexible' !== ( $field['type'] ?? '' ) ) {
			return (array) ( $field['sub_fields'] ?? array() );
		}
		$name = is_array( $row ) ? (string) ( $row['acf_fc_layout'] ?? '' ) : (string) $row;
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			if ( $layout['name'] === $name ) {
				return (array) ( $layout['sub_fields'] ?? array() );
			}
		}
		return null;
	}

	public static function is_empty( $value ): bool {
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * Default raw value of a field that has never been saved.
	 */
	public static function default_value( array $field ) {
		$type    = $field['type'] ?? 'text';
		$default = $field['default'] ?? '';
		switch ( $type ) {
			case 'true_false':
				return empty( $default ) ? 0 : 1;
			case 'repeater':
			case 'flexible':
				return array();
			case 'group':
				$out = array();
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
					if ( self::has_value( $sub ) ) {
						$out[ $sub['name'] ] = self::default_value( $sub );
					}
				}
				return $out;
			case 'checkbox':
			case 'select':
				if ( self::is_multiple( $field ) ) {
					$parts = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $default ) ), 'strlen' );
					return array_values( array_intersect( $parts, self::choice_values( $field ) ) );
				}
				return in_array( (string) $default, self::choice_values( $field ), true ) ? (string) $default : '';
			case 'radio':
			case 'button_group':
				return in_array( (string) $default, self::choice_values( $field ), true ) ? (string) $default : '';
			case 'image':
			case 'file':
			case 'link':
			case 'password':
				return '';
			case 'gallery':
				return array();
			case 'post_object':
			case 'taxonomy':
			case 'user':
				return self::is_multiple( $field ) ? array() : '';
			default:
				return is_scalar( $default ) ? (string) $default : '';
		}
	}

	/**
	 * Sanitizes a raw value coming from the editor. Input must be unslashed.
	 */
	public static function sanitize( array $field, $value, int $depth = 0 ) {
		$type = $field['type'] ?? 'text';
		if ( null === $value || $depth > 8 ) {
			return '';
		}
		switch ( $type ) {
			case 'textarea':
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
			case 'number':
			case 'range':
				return is_numeric( $value ) ? $value + 0 : '';
			case 'email':
				return is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
			case 'url':
				return is_scalar( $value ) ? esc_url_raw( trim( (string) $value ) ) : '';
			case 'wysiwyg':
				return is_scalar( $value ) ? wp_kses_post( (string) $value ) : '';
			case 'true_false':
				return empty( $value ) || 'false' === $value ? 0 : 1;
			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				$allowed = self::choice_values( $field );
				if ( self::is_multiple( $field ) ) {
					$value = is_array( $value ) ? array_map( 'strval', array_filter( $value, 'is_scalar' ) ) : array();
					return array_values( array_unique( array_intersect( $value, $allowed ) ) );
				}
				return is_scalar( $value ) && in_array( (string) $value, $allowed, true ) ? (string) $value : '';
			case 'date':
				$value = is_scalar( $value ) ? (string) $value : '';
				if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
					$value = "$m[1]-$m[2]-$m[3]";
				}
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
			case 'datetime':
				$value = is_scalar( $value ) ? str_replace( 'T', ' ', (string) $value ) : '';
				if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
					$value .= ':00';
				}
				return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
			case 'time':
				$value = is_scalar( $value ) ? (string) $value : '';
				if ( preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
					$value .= ':00';
				}
				return preg_match( '/^\d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
			case 'color':
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				return preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,%\s]+\)|hsla?\([0-9.,%\s]+\))$/', $value ) ? $value : '';
			case 'image':
			case 'file':
				return absint( is_array( $value ) ? ( $value['id'] ?? 0 ) : $value ) ?: '';
			case 'gallery':
				$ids = array_values( array_filter( array_map( 'absint', is_array( $value ) ? $value : array() ) ) );
				$max = absint( $field['max'] ?? 0 );
				return $max ? array_slice( $ids, 0, $max ) : $ids;
			case 'link':
				if ( ! is_array( $value ) || empty( $value['url'] ) ) {
					return '';
				}
				return array(
					'url'    => esc_url_raw( trim( (string) $value['url'] ) ),
					'title'  => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
					'target' => '_blank' === ( $value['target'] ?? '' ) ? '_blank' : '',
				);
			case 'post_object':
			case 'taxonomy':
			case 'user':
				if ( self::is_multiple( $field ) ) {
					return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : array( $value ) ) ) ) );
				}
				if ( is_array( $value ) ) {
					$value = reset( $value );
				}
				return absint( $value ) ?: '';
			case 'repeater':
				$rows = array();
				$max  = absint( $field['max'] ?? 0 );
				foreach ( is_array( $value ) ? array_values( $value ) : array() as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$rows[] = self::sanitize_children( (array) ( $field['sub_fields'] ?? array() ), $row, $depth );
					if ( $max && count( $rows ) >= $max ) {
						break;
					}
				}
				return $rows;
			case 'flexible':
				$rows = array();
				$max  = absint( $field['max'] ?? 0 );
				foreach ( is_array( $value ) ? array_values( $value ) : array() as $row ) {
					$subs = is_array( $row ) ? self::row_fields( $field, $row ) : null;
					if ( null === $subs ) {
						continue;
					}
					$rows[] = array( 'acf_fc_layout' => (string) $row['acf_fc_layout'] ) + self::sanitize_children( $subs, $row, $depth );
					if ( $max && count( $rows ) >= $max ) {
						break;
					}
				}
				return $rows;
			case 'group':
				return is_array( $value ) ? self::sanitize_children( (array) ( $field['sub_fields'] ?? array() ), $value, $depth ) : '';
			case 'tab':
			case 'message':
			case 'clone':
				return '';
			default:
				$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				$limit = absint( $field['maxlength'] ?? 0 );
				return $limit ? mb_substr( $value, 0, $limit ) : $value;
		}
	}

	private static function sanitize_children( array $subs, array $row, int $depth ): array {
		$out = array();
		foreach ( $subs as $sub ) {
			if ( self::has_value( $sub ) ) {
				$out[ $sub['name'] ] = self::sanitize( $sub, $row[ $sub['name'] ] ?? '', $depth + 1 );
			}
		}
		return $out;
	}

	/**
	 * Formats a raw value for use in templates.
	 */
	public static function format( array $field, $value ) {
		$type = $field['type'] ?? 'text';
		switch ( $type ) {
			case 'number':
			case 'range':
				return is_numeric( $value ) ? $value + 0 : $value;
			case 'true_false':
				return ! empty( $value );
			case 'wysiwyg':
				return '' === $value || ! is_string( $value ) ? '' : do_shortcode( wpautop( wptexturize( $value ) ) );
			case 'date':
			case 'datetime':
			case 'time':
				if ( ! is_string( $value ) || '' === $value || empty( $field['display_format'] ) ) {
					return $value;
				}
				$stamp = strtotime( 'time' === $type ? '1970-01-01 ' . $value : $value );
				return false === $stamp ? $value : date_i18n( $field['display_format'], $stamp );
			case 'image':
			case 'file':
				return self::format_media( $field, $value );
			case 'gallery':
				$out = array();
				foreach ( (array) $value as $id ) {
					$item = self::format_media( $field, $id );
					if ( $item ) {
						$out[] = $item;
					}
				}
				return $out;
			case 'link':
				return is_array( $value ) && ! empty( $value['url'] ) ? $value : null;
			case 'post_object':
			case 'taxonomy':
			case 'user':
				$return = $field['return_format'] ?? 'object';
				$map    = static function ( $id ) use ( $type, $return, $field ) {
					$id = absint( $id );
					if ( ! $id ) {
						return null;
					}
					if ( 'id' === $return ) {
						return $id;
					}
					if ( 'post_object' === $type ) {
						return get_post( $id );
					}
					if ( 'user' === $type ) {
						return get_userdata( $id ) ?: null;
					}
					$term = get_term( $id, (string) ( $field['taxonomy'] ?? '' ) );
					return $term instanceof \WP_Term ? $term : null;
				};
				if ( self::is_multiple( $field ) ) {
					return array_values( array_filter( array_map( $map, (array) $value ) ) );
				}
				return $map( is_array( $value ) ? reset( $value ) : $value );
			case 'select':
			case 'checkbox':
				if ( self::is_multiple( $field ) ) {
					return self::is_empty( $value ) ? array() : array_values( (array) $value );
				}
				return is_array( $value ) ? (string) reset( $value ) : $value;
			case 'repeater':
				$rows = array();
				foreach ( is_array( $value ) ? $value : array() as $row ) {
					$rows[] = self::format_children( (array) ( $field['sub_fields'] ?? array() ), (array) $row );
				}
				return $rows;
			case 'flexible':
				$rows = array();
				foreach ( is_array( $value ) ? $value : array() as $row ) {
					$subs = self::row_fields( $field, $row );
					if ( null !== $subs ) {
						$rows[] = array( 'acf_fc_layout' => (string) $row['acf_fc_layout'] ) + self::format_children( $subs, (array) $row );
					}
				}
				return $rows;
			case 'group':
				return self::format_children( (array) ( $field['sub_fields'] ?? array() ), is_array( $value ) ? $value : array() );
			default:
				return $value;
		}
	}

	private static function format_children( array $subs, array $row ): array {
		$out = array();
		foreach ( $subs as $sub ) {
			if ( self::has_value( $sub ) ) {
				$raw                 = array_key_exists( $sub['name'], $row ) ? $row[ $sub['name'] ] : self::default_value( $sub );
				$out[ $sub['name'] ] = self::format( $sub, $raw );
			}
		}
		return $out;
	}

	private static function format_media( array $field, $id ) {
		$id = absint( is_array( $id ) ? ( $id['id'] ?? 0 ) : $id );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}
		$return = $field['return_format'] ?? 'array';
		if ( 'id' === $return ) {
			return $id;
		}
		$url = wp_get_attachment_url( $id );
		if ( 'url' === $return ) {
			return $url ?: null;
		}
		$meta = wp_get_attachment_metadata( $id );
		$out  = array(
			'id'        => $id,
			'ID'        => $id,
			'url'       => $url,
			'title'     => get_the_title( $id ),
			'alt'       => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'   => wp_get_attachment_caption( $id ) ?: '',
			'mime_type' => get_post_mime_type( $id ),
			'filename'  => wp_basename( (string) get_attached_file( $id ) ),
			'width'     => (int) ( $meta['width'] ?? 0 ),
			'height'    => (int) ( $meta['height'] ?? 0 ),
			'sizes'     => array(),
		);
		if ( wp_attachment_is_image( $id ) ) {
			foreach ( get_intermediate_image_sizes() as $size ) {
				$src = wp_get_attachment_image_src( $id, $size );
				if ( $src ) {
					$out['sizes'][ $size ]             = $src[0];
					$out['sizes'][ $size . '-width' ]  = $src[1];
					$out['sizes'][ $size . '-height' ] = $src[2];
				}
			}
		}
		return $out;
	}
}
