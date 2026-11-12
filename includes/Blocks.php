<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * Fields and blocks:
 *
 * - "Fields as blocks": a field group can become a block of its own. Its
 *   values live in the block (attribute "data") and are rendered by an HTML
 *   template with {{placeholders}}, a theme file or a filter.
 * - "Fields on blocks": a group located on a block type adds its fields to
 *   that block's settings. Values live in the block attribute "magicfields".
 */
final class Blocks {

	public static function hooks(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 25 );
		add_filter( 'register_block_type_args', array( __CLASS__, 'add_attribute' ), 10, 2 );
		add_filter( 'render_block', array( __CLASS__, 'filter_output' ), 10, 2 );
	}

	/**
	 * @return array[] Active groups that are blocks, keyed by block name.
	 */
	public static function custom_blocks(): array {
		$out = array();
		foreach ( Groups::all() as $group ) {
			if ( ! empty( $group['block']['enabled'] ) && '' !== $group['block']['slug'] ) {
				$out[ 'magicfields/' . $group['block']['slug'] ] = $group;
			}
		}
		return $out;
	}

	/**
	 * @return array[] Active groups located on block types.
	 */
	public static function block_groups(): array {
		return array_values(
			array_filter(
				Groups::all(),
				static function ( $group ) {
					return Locations::group_targets( $group, 'block' );
				}
			)
		);
	}

	public static function register(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::custom_blocks() as $name => $group ) {
			if ( $registry->is_registered( $name ) ) {
				continue;
			}
			register_block_type(
				$name,
				array(
					'api_version'           => 3,
					'title'                 => $group['title'],
					'description'           => $group['block']['description'],
					'category'              => $group['block']['category'],
					'icon'                  => $group['block']['icon'],
					'attributes'            => array(
						'data' => array( 'type' => 'object', 'default' => array() ),
					),
					'supports'              => array(
						'html'       => false,
						'align'      => array( 'wide', 'full' ),
						'anchor'     => true,
						'color'      => array( 'text' => true, 'background' => true, 'link' => true ),
						'typography' => array( 'fontSize' => true, 'lineHeight' => true ),
						'spacing'    => array( 'margin' => true, 'padding' => true ),
					),
					'editor_script_handles' => array( 'magicfields-editor' ),
					'render_callback'       => static function ( $attributes, $content, $block ) use ( $group ) {
						return self::render( $group, (array) ( $attributes['data'] ?? array() ), $block );
					},
				)
			);
		}
	}

	/**
	 * Sanitized raw values of a group stored in a block.
	 */
	private static function values( array $group, array $data ): array {
		$out = array();
		foreach ( $group['fields'] as $field ) {
			if ( FieldTypes::has_value( $field ) ) {
				$out[ $field['name'] ] = array_key_exists( $field['name'], $data )
					? FieldTypes::sanitize( $field, $data[ $field['name'] ] )
					: FieldTypes::default_value( $field );
			}
		}
		return $out;
	}

	private static function formatted( array $fields, array $raw ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( FieldTypes::has_value( $field ) ) {
				$out[ $field['name'] ] = FieldTypes::format( $field, $raw[ $field['name'] ] ?? '' );
			}
		}
		return $out;
	}

	private static function render( array $group, array $data, $block ): string {
		$raw    = self::values( $group, $data );
		$fields = self::formatted( $group['fields'], $raw );
		$slug   = $group['block']['slug'];

		$html = apply_filters( 'magicfields_render_block', null, $fields, $group, $block );
		if ( null === $html ) {
			$file = locate_template( array( "magicfields-blocks/{$slug}.php" ) );
			if ( $file ) {
				ob_start();
				( static function () use ( $file, $fields, $block, $group ) {
					include $file;
				} )();
				$html = ob_get_clean();
			} elseif ( '' !== trim( $group['block']['template'] ) ) {
				$html = self::template( $group['block']['template'], $group['fields'], $raw );
			} else {
				$html = '';
				foreach ( $group['fields'] as $field ) {
					if ( FieldTypes::has_value( $field ) ) {
						$part = Frontend::render_value( $field, $raw[ $field['name'] ] );
						if ( '' !== $part ) {
							$html .= '<div class="magicfields-block__field magicfields-block__field--' . esc_attr( $field['name'] ) . '">' . $part . '</div>';
						}
					}
				}
			}
		}
		$html = (string) $html;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $html; // Editor preview: the editor adds the wrapper itself.
		}
		return '' === trim( $html ) ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Small template language for block markup:
	 *
	 *   {{name}}                 the field as safe HTML
	 *   {{name|raw}}             the stored value, escaped, for attributes (images and files give their URL)
	 *   {{#name}} … {{/name}}    repeats for each row of a repeater / flexible content,
	 *                            or shows the content once when the field is not empty
	 *   {{^name}} … {{/name}}    shows the content when the field is empty
	 */
	public static function template( string $template, array $fields, array $raw, int $depth = 0 ): string {
		$by_name = array();
		foreach ( $fields as $field ) {
			if ( FieldTypes::has_value( $field ) ) {
				$by_name[ $field['name'] ] = $field;
			}
		}
		$filled = static function ( $field, $value ) {
			return 'true_false' === $field['type'] ? ! empty( $value ) : ! FieldTypes::is_empty( $value );
		};

		if ( $depth < 6 ) {
			$template = preg_replace_callback(
				'/\{\{([#^])\s*([a-z0-9_\-]+)\s*\}\}(.*?)\{\{\/\s*\2\s*\}\}/s',
				static function ( $m ) use ( $by_name, $raw, $filled, $depth ) {
					$field = $by_name[ $m[2] ] ?? null;
					if ( ! $field ) {
						return '';
					}
					$value = $raw[ $m[2] ] ?? '';
					if ( '^' === $m[1] ) {
						return $filled( $field, $value ) ? '' : self::template( $m[3], array_values( $by_name ), $raw, $depth + 1 );
					}
					if ( ! $filled( $field, $value ) ) {
						return '';
					}
					if ( in_array( $field['type'], array( 'repeater', 'flexible' ), true ) ) {
						$out = '';
						foreach ( (array) $value as $row ) {
							$subs = FieldTypes::row_fields( $field, $row );
							if ( null !== $subs && is_array( $row ) ) {
								// {{acf_fc_layout}} gives the layout name inside flexible rows.
								$subs[] = array( 'name' => 'acf_fc_layout', 'type' => 'text', 'label' => '' );
								$out   .= self::template( $m[3], $subs, $row, $depth + 1 );
							}
						}
						return $out;
					}
					if ( 'group' === $field['type'] ) {
						return self::template( $m[3], (array) $field['sub_fields'], (array) $value, $depth + 1 );
					}
					return self::template( $m[3], array_values( $by_name ), $raw, $depth + 1 );
				},
				$template
			);
		}

		return (string) preg_replace_callback(
			'/\{\{\s*([a-z0-9_\-]+)\s*(\|\s*raw\s*)?\}\}/',
			static function ( $m ) use ( $by_name, $raw ) {
				$field = $by_name[ $m[1] ] ?? null;
				if ( ! $field ) {
					return '';
				}
				$value = $raw[ $m[1] ] ?? '';
				if ( empty( $m[2] ) ) {
					return Frontend::render_value( $field, $value );
				}
				if ( in_array( $field['type'], array( 'image', 'file' ), true ) ) {
					return esc_url( (string) wp_get_attachment_url( absint( $value ) ) );
				}
				if ( 'link' === $field['type'] ) {
					return is_array( $value ) ? esc_url( (string) ( $value['url'] ?? '' ) ) : '';
				}
				if ( 'url' === $field['type'] ) {
					return esc_url( (string) $value );
				}
				if ( 'password' === $field['type'] ) {
					return '';
				}
				return is_scalar( $value ) ? esc_attr( (string) $value ) : esc_attr( implode( ',', array_filter( (array) $value, 'is_scalar' ) ) );
			},
			$template
		);
	}

	/* ---------- Fields on blocks ---------- */

	private static function groups_for_block( string $name ): array {
		$out = array();
		foreach ( self::block_groups() as $group ) {
			if ( Locations::matches( $group, array( 'kind' => 'block', 'block' => $name ) ) ) {
				$out[] = $group;
			}
		}
		return $out;
	}

	/**
	 * Declares the "magicfields" attribute on blocks that receive fields, so the
	 * server accepts it (dynamic blocks, editor previews).
	 */
	public static function add_attribute( $args, $name ) {
		if ( ! is_string( $name ) || 0 === strpos( $name, 'magicfields/' ) || ! did_action( 'init' ) && ! doing_action( 'init' ) ) {
			return $args;
		}
		static $busy = false;
		if ( $busy ) {
			return $args;
		}
		$busy   = true;
		$groups = self::groups_for_block( $name );
		$busy   = false;
		if ( $groups ) {
			$args['attributes']            = (array) ( $args['attributes'] ?? array() );
			$args['attributes']['magicfields'] = array( 'type' => 'object' );
		}
		return $args;
	}

	/**
	 * Values of the fields attached to a block, formatted. $block is the
	 * parsed block array (as given to the "render_block" filter) or a WP_Block.
	 */
	public static function block_fields( $block ): array {
		$parsed = $block instanceof \WP_Block ? $block->parsed_block : (array) $block;
		$name   = (string) ( $parsed['blockName'] ?? '' );
		$data   = (array) ( $parsed['attrs']['magicfields'] ?? array() );
		$out    = array();
		foreach ( '' !== $name ? self::groups_for_block( $name ) : array() as $group ) {
			$out += self::formatted( $group['fields'], self::values( $group, $data ) );
		}
		return $out;
	}

	/**
	 * Lets themes change a block's markup from its field values.
	 */
	public static function filter_output( $content, $block ) {
		if ( empty( $block['attrs']['magicfields'] ) || ! has_filter( 'magicfields_block_output' ) ) {
			return $content;
		}
		return apply_filters( 'magicfields_block_output', $content, self::block_fields( $block ), $block );
	}
}
