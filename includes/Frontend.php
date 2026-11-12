<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * Displays field values: [magicfield] shortcode, "Field value" block and the
 * block bindings source.
 */
final class Frontend {

	const SOURCE = 'magicfields/field';

	public static function hooks(): void {
		add_shortcode( 'magicfield', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	public static function register(): void {
		register_block_type(
			'magicfields/field',
			array(
				'api_version'           => 3,
				'title'                 => __( 'Field value', 'magicfields' ),
				'description'           => __( 'Displays the value of a custom field.', 'magicfields' ),
				'category'              => 'theme',
				'icon'                  => 'feedback',
				'attributes'            => array(
					'name'   => array( 'type' => 'string', 'default' => '' ),
					'source' => array( 'type' => 'string', 'default' => 'post' ),
					'tag'    => array( 'type' => 'string', 'default' => 'div' ),
					'postId' => array( 'type' => 'number', 'default' => 0 ),
				),
				'uses_context'          => array( 'postId' ),
				'supports'              => array(
					'html'       => false,
					'align'      => true,
					'color'      => array( 'text' => true, 'background' => true, 'link' => true ),
					'typography' => array( 'fontSize' => true, 'lineHeight' => true, 'textAlign' => true ),
					'spacing'    => array( 'margin' => true, 'padding' => true ),
				),
				'editor_script_handles' => array( 'magicfields-editor' ),
				'render_callback'       => array( __CLASS__, 'render_block' ),
			)
		);

		if ( function_exists( 'register_block_bindings_source' ) ) {
			register_block_bindings_source(
				self::SOURCE,
				array(
					'label'              => __( 'Custom field (MagicFields)', 'magicfields' ),
					'uses_context'       => array( 'postId' ),
					'get_value_callback' => array( __CLASS__, 'binding_value' ),
				)
			);
		}
	}

	private static function target( string $source, int $post_id ) {
		return 'option' === $source ? 'option' : ( $post_id ?: null );
	}

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'name' => '', 'post_id' => 0, 'source' => 'post' ), (array) $atts, 'magicfield' );
		return self::html( (string) $atts['name'], self::target( (string) $atts['source'], absint( $atts['post_id'] ) ) );
	}

	public static function render_block( $attributes, $content, $block ): string {
		$name = (string) ( $attributes['name'] ?? '' );
		if ( '' === $name ) {
			return '';
		}
		$post_id = absint( $attributes['postId'] ?? 0 ) ?: absint( $block->context['postId'] ?? 0 );
		$html    = self::html( $name, self::target( (string) ( $attributes['source'] ?? 'post' ), $post_id ) );
		if ( '' === $html ) {
			return '';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $html; // Editor preview: the editor adds the wrapper itself.
		}
		$tag = in_array( $attributes['tag'] ?? 'div', array( 'div', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $attributes['tag'] : 'div';
		return sprintf( '<%1$s %2$s>%3$s</%1$s>', $tag, get_block_wrapper_attributes(), $html );
	}

	/**
	 * Value for a bound block attribute, e.g. the "url" of an Image block or
	 * the "content" of a Paragraph.
	 */
	public static function binding_value( array $args, $block, string $attribute ) {
		$name = (string) ( $args['key'] ?? '' );
		if ( '' === $name ) {
			return null;
		}
		$object = 'option' === ( $args['source'] ?? '' ) ? 'option' : ( absint( $block->context['postId'] ?? 0 ) ?: null );
		$ctx    = Values::resolve( $object );
		if ( ! $ctx || ( 'post' === $ctx['kind'] && ! self::can_show_post( $ctx['id'] ) ) ) {
			return null;
		}
		$field = Groups::field_for( $name, $ctx );
		if ( ! $field || in_array( $field['type'], array( 'password', 'repeater', 'flexible', 'group' ), true ) ) {
			return null;
		}
		$raw = Values::raw( $ctx, $field );
		if ( in_array( $field['type'], array( 'image', 'file' ), true ) ) {
			$id = absint( $raw );
			if ( ! $id ) {
				return null;
			}
			switch ( $attribute ) {
				case 'id':
					return $id;
				case 'alt':
					return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
				case 'title':
				case 'text':
					return get_the_title( $id );
				case 'content':
					return esc_url( (string) wp_get_attachment_url( $id ) );
				default:
					return (string) wp_get_attachment_url( $id );
			}
		}
		if ( 'link' === $field['type'] ) {
			if ( ! is_array( $raw ) || empty( $raw['url'] ) ) {
				return null;
			}
			if ( in_array( $attribute, array( 'text', 'content', 'title' ), true ) ) {
				return esc_html( '' !== $raw['title'] ? $raw['title'] : $raw['url'] );
			}
			if ( 'linkTarget' === $attribute ) {
				return $raw['target'];
			}
			return $raw['url'];
		}
		if ( in_array( $attribute, array( 'url', 'href' ), true ) ) {
			return is_scalar( $raw ) ? esc_url_raw( (string) $raw ) : null;
		}
		$html = self::render( $field, $raw );
		return in_array( $attribute, array( 'content', 'text' ), true ) ? $html : wp_strip_all_tags( $html );
	}

	private static function can_show_post( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		if ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $post_id ) ) {
			return false;
		}
		return ! post_password_required( $post );
	}

	/**
	 * Safe HTML for a field value.
	 */
	public static function html( string $name, $object = null ): string {
		$ctx = Values::resolve( $object );
		if ( ! $ctx || '' === $name || ( 'post' === $ctx['kind'] && ! self::can_show_post( $ctx['id'] ) ) ) {
			return '';
		}
		$field = Groups::field_for( $name, $ctx );
		if ( ! $field ) {
			return '';
		}
		return (string) apply_filters( 'magicfields_html', self::render( $field, Values::raw( $ctx, $field ) ), $field, $ctx );
	}

	private static function choice_label( array $field, $value ): string {
		foreach ( (array) ( $field['choices'] ?? array() ) as $choice ) {
			if ( (string) $choice['value'] === (string) $value ) {
				return $choice['label'];
			}
		}
		return (string) $value;
	}

	/**
	 * Safe HTML for a raw value of the given field.
	 */
	public static function render_value( array $field, $raw ): string {
		return self::render( $field, $raw );
	}

	private static function render( array $field, $raw ): string {
		if ( FieldTypes::is_empty( $raw ) && 'true_false' !== $field['type'] ) {
			return '';
		}
		switch ( $field['type'] ) {
			case 'password':
			case 'repeater':
			case 'flexible':
			case 'group':
				return '';
			case 'textarea':
				return nl2br( esc_html( (string) $raw ) );
			case 'wysiwyg':
				return (string) FieldTypes::format( $field, $raw );
			case 'email':
				$email = antispambot( (string) $raw );
				return '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			case 'url':
				return '<a href="' . esc_url( (string) $raw ) . '">' . esc_html( (string) $raw ) . '</a>';
			case 'true_false':
				return esc_html( $raw ? __( 'Yes', 'magicfields' ) : __( 'No', 'magicfields' ) );
			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				$labels = array();
				foreach ( (array) $raw as $value ) {
					$labels[] = self::choice_label( $field, $value );
				}
				return esc_html( implode( ', ', $labels ) );
			case 'date':
			case 'datetime':
			case 'time':
				if ( empty( $field['display_format'] ) ) {
					$field['display_format'] = 'time' === $field['type'] ? get_option( 'time_format' ) : get_option( 'date_format' ) . ( 'datetime' === $field['type'] ? ' ' . get_option( 'time_format' ) : '' );
				}
				return esc_html( (string) FieldTypes::format( $field, $raw ) );
			case 'image':
				return wp_get_attachment_image( absint( $raw ), 'large' );
			case 'gallery':
				$out = '';
				foreach ( (array) $raw as $id ) {
					$out .= '<figure class="magicfields-gallery__item">' . wp_get_attachment_image( absint( $id ), 'medium_large' ) . '</figure>';
				}
				return '' === $out ? '' : '<div class="magicfields-gallery">' . $out . '</div>';
			case 'file':
				$url = wp_get_attachment_url( absint( $raw ) );
				return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( absint( $raw ) ) ) . '</a>' : '';
			case 'link':
				if ( ! is_array( $raw ) || empty( $raw['url'] ) ) {
					return '';
				}
				return '<a href="' . esc_url( $raw['url'] ) . '"' . ( '_blank' === $raw['target'] ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( '' !== $raw['title'] ? $raw['title'] : $raw['url'] ) . '</a>';
			case 'post_object':
				$links = array();
				foreach ( (array) $raw as $id ) {
					$post = get_post( absint( $id ) );
					if ( $post && is_post_publicly_viewable( $post ) ) {
						$links[] = '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
					}
				}
				return implode( ', ', $links );
			case 'taxonomy':
				$links = array();
				foreach ( (array) $raw as $id ) {
					$term = get_term( absint( $id ), (string) ( $field['taxonomy'] ?? '' ) );
					if ( $term instanceof \WP_Term ) {
						$url     = get_term_link( $term );
						$links[] = is_wp_error( $url ) ? esc_html( $term->name ) : '<a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a>';
					}
				}
				return implode( ', ', $links );
			case 'user':
				$names = array();
				foreach ( (array) $raw as $id ) {
					$user = get_userdata( absint( $id ) );
					if ( $user ) {
						$names[] = esc_html( $user->display_name );
					}
				}
				return implode( ', ', $names );
			default:
				return is_scalar( $raw ) ? esc_html( (string) $raw ) : '';
		}
	}
}
