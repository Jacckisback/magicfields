<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * Converts imported field groups, including ACF JSON exports, to our format.
 */
final class Import {

	const ACF_TYPES = array(
		'date_picker'      => 'date',
		'date_time_picker' => 'datetime',
		'time_picker'      => 'time',
		'color_picker'     => 'color',
		'relationship'     => 'post_object',
		'page_link'        => 'post_object',
		'oembed'           => 'url',
		'accordion'        => 'tab',
		'flexible_content' => 'flexible',
	);

	public static function normalize( array $group, array &$warnings ): array {
		$is_acf = 0 === strpos( (string) ( $group['key'] ?? '' ), 'group_' ) && ! isset( $group['order'] ) && isset( $group['menu_order'] );
		$title  = (string) ( $group['title'] ?? '' );

		$group['fields'] = self::fields( (array) ( $group['fields'] ?? array() ), $title, $warnings );

		$location = array();
		foreach ( (array) ( $group['location'] ?? array() ) as $set ) {
			$rules = array();
			foreach ( (array) $set as $rule ) {
				$param = (string) ( $rule['param'] ?? '' );
				if ( 'user_form' === $param ) {
					$rule = array( 'param' => 'user_role', 'operator' => '==', 'value' => 'all' );
				} elseif ( 'page' === $param ) {
					$rule['param'] = 'post';
				} elseif ( 'post_template' === $param ) {
					$rule['param'] = 'page_template';
				}
				if ( isset( Locations::params()[ $rule['param'] ?? '' ] ) ) {
					$rules[] = $rule;
				} else {
					/* translators: 1: group title, 2: rule name */
					$warnings[] = sprintf( __( '%1$s: the location rule "%2$s" is not supported and was skipped.', 'magicfields' ), $title, $param );
				}
			}
			if ( $rules ) {
				$location[] = $rules;
			}
		}
		$group['location'] = $location;
		if ( $is_acf ) {
			$group['position'] = 'side' === ( $group['position'] ?? '' ) ? 'side' : 'normal';
		}
		// A group that already exists under this key is updated; otherwise it is created with it.
		return $group;
	}

	private static function fields( array $fields, string $title, array &$warnings ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = (string) ( $field['type'] ?? 'text' );
			if ( isset( self::ACF_TYPES[ $type ] ) ) {
				if ( 'relationship' === $type ) {
					$field['multiple'] = true;
				}
				$type = self::ACF_TYPES[ $type ];
			}
			if ( ! FieldTypes::exists( $type ) ) {
				/* translators: 1: group title, 2: field label, 3: field type */
				$warnings[] = sprintf( __( '%1$s: the field "%2$s" (%3$s) is not supported and was skipped.', 'magicfields' ), $title, (string) ( $field['label'] ?? $field['name'] ?? '?' ), $type );
				continue;
			}
			$field['type'] = $type;

			// ACF vocabulary.
			if ( isset( $field['default_value'] ) && ! isset( $field['default'] ) ) {
				$default          = $field['default_value'];
				$field['default'] = is_array( $default ) ? implode( "\n", array_filter( $default, 'is_scalar' ) ) : $default;
			}
			if ( isset( $field['wrapper']['width'] ) && ! isset( $field['width'] ) ) {
				$field['width'] = (int) $field['wrapper']['width'];
			}
			if ( isset( $field['post_type'] ) && ! isset( $field['post_types'] ) ) {
				$field['post_types'] = (array) $field['post_type'];
			}
			if ( isset( $field['role'] ) && ! isset( $field['roles'] ) ) {
				$field['roles'] = (array) $field['role'];
			}
			if ( 'taxonomy' === $type && isset( $field['field_type'] ) ) {
				$field['multiple'] = in_array( $field['field_type'], array( 'checkbox', 'multi_select' ), true );
			}
			if ( isset( $field['maxlength'] ) && '' === $field['maxlength'] ) {
				unset( $field['maxlength'] );
			}
			if ( in_array( $type, array( 'image', 'file', 'gallery' ), true ) && ! isset( $field['return_format'] ) ) {
				$field['return_format'] = 'array';
			}
			if ( 'true_false' === $type && isset( $field['default_value'] ) ) {
				$field['default'] = ! empty( $field['default_value'] );
			}
			if ( isset( $field['conditional_logic'] ) && is_array( $field['conditional_logic'] ) ) {
				$field['_acf_conditions'] = $field['conditional_logic'];
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::fields( (array) $field['sub_fields'], $title, $warnings );
			}
			if ( 'flexible' === $type ) {
				$layouts = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					if ( is_array( $layout ) ) {
						$layout['sub_fields'] = self::fields( (array) ( $layout['sub_fields'] ?? array() ), $title, $warnings );
						$layouts[]            = $layout;
					}
				}
				$field['layouts'] = $layouts;
			}
			if ( 'clone' === $type && ! empty( $field['prefix_name'] ) && 'group' !== ( $field['display'] ?? '' ) ) {
				/* translators: 1: group title, 2: field label */
				$warnings[] = sprintf( __( '%1$s: the clone field "%2$s" prefixed its field names in ACF; here the cloned fields keep their own names.', 'magicfields' ), $title, (string) ( $field['label'] ?? $field['name'] ?? '?' ) );
			}
			$out[] = $field;
		}

		// ACF conditions point at field keys; ours point at sibling names.
		$names = array();
		foreach ( $out as $field ) {
			$names[ (string) ( $field['key'] ?? '' ) ] = (string) ( $field['name'] ?? '' );
		}
		foreach ( $out as &$field ) {
			if ( empty( $field['_acf_conditions'] ) ) {
				continue;
			}
			$conditions = array();
			foreach ( (array) reset( $field['_acf_conditions'] ) as $rule ) {
				$target = $names[ (string) ( $rule['field'] ?? '' ) ] ?? '';
				if ( '' === $target ) {
					continue;
				}
				$op           = (string) ( $rule['operator'] ?? '==' );
				$op           = '==empty' === $op ? 'empty' : ( '!=empty' === $op ? 'not_empty' : ( '==contains' === $op ? 'contains' : $op ) );
				$conditions[] = array( 'field' => $target, 'operator' => $op, 'value' => (string) ( $rule['value'] ?? '' ) );
			}
			$field['conditions'] = $conditions;
			unset( $field['_acf_conditions'] );
		}
		return $out;
	}
}
