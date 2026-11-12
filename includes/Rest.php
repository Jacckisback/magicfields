<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * REST API: group management for the builder, pickers, and the "magicfields"
 * field the block editor uses to load and save values with the post.
 */
final class Rest {

	const NS = 'magicfields/v1';

	public static function register(): void {
		$admin = static function () {
			return current_user_can( Plugin::capability() );
		};
		register_rest_route(
			self::NS,
			'/groups',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_groups' ), 'permission_callback' => $admin ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_group' ), 'permission_callback' => $admin ),
			)
		);
		register_rest_route(
			self::NS,
			'/groups/(?P<key>[a-z0-9_\-]+)',
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_group' ), 'permission_callback' => $admin )
		);
		register_rest_route(
			self::NS,
			'/import',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import' ), 'permission_callback' => $admin )
		);
		register_rest_route(
			self::NS,
			'/options-pages',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_pages' ), 'permission_callback' => $admin ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'save_pages' ), 'permission_callback' => $admin ),
			)
		);
		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $post_type ) {
			register_rest_field(
				$post_type,
				'magicfields',
				array(
					'get_callback'    => static function ( $object, $name, $request ) {
						$id  = (int) ( $object['id'] ?? 0 );
						$ctx = array( 'kind' => 'post', 'id' => $id );
						if ( 'edit' === $request['context'] ) {
							return (object) Values::all_raw( $ctx, Locations::groups_for( Locations::post_info( $id ) ) );
						}
						return post_password_required( $id ) ? new \stdClass() : self::public_values( $ctx );
					},
					'update_callback' => static function ( $value, $post ) {
						if ( ! is_array( $value ) || ! current_user_can( 'edit_post', $post->ID ) ) {
							return true;
						}
						Values::save( array( 'kind' => 'post', 'id' => (int) $post->ID ), Locations::groups_for( Locations::post_info( $post ) ), $value );
						return true;
					},
					'schema'          => array(
						'description'          => 'MagicFields raw values.',
						'type'                 => 'object',
						'context'              => array( 'view', 'edit' ),
						'additionalProperties' => true,
					),
				)
			);
		}

		// Terms and users: read-only, groups that opted in.
		$public = array(
			'description'          => 'MagicFields values.',
			'type'                 => 'object',
			'context'              => array( 'view', 'edit' ),
			'readonly'             => true,
			'additionalProperties' => true,
		);
		foreach ( get_taxonomies( array( 'show_in_rest' => true ) ) as $taxonomy ) {
			register_rest_field(
				$taxonomy,
				'magicfields',
				array(
					'get_callback' => static function ( $object ) {
						return self::public_values( array( 'kind' => 'term', 'id' => (int) ( $object['id'] ?? 0 ) ) );
					},
					'schema'       => $public,
				)
			);
		}
		register_rest_field(
			'user',
			'magicfields',
			array(
				'get_callback' => static function ( $object ) {
					return self::public_values( array( 'kind' => 'user', 'id' => (int) ( $object['id'] ?? 0 ) ) );
				},
				'schema'       => $public,
			)
		);
	}

	/**
	 * Formatted values of the groups that chose to appear in the REST API.
	 * Passwords are never included; related posts, terms and users are IDs.
	 */
	public static function public_values( array $ctx ): \stdClass {
		$out = array();
		foreach ( Locations::groups_for( Locations::info_for( $ctx ) ) as $group ) {
			if ( empty( $group['show_in_rest'] ) ) {
				continue;
			}
			foreach ( self::rest_fields( $group['fields'] ) as $field ) {
				$out[ $field['name'] ] = FieldTypes::format( $field, Values::raw( $ctx, $field ) );
			}
		}
		return (object) $out;
	}

	private static function rest_fields( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! FieldTypes::has_value( $field ) || 'password' === $field['type'] ) {
				continue;
			}
			if ( in_array( $field['type'], array( 'post_object', 'taxonomy', 'user' ), true ) ) {
				$field['return_format'] = 'id';
			}
			if ( isset( $field['sub_fields'] ) ) {
				$field['sub_fields'] = self::rest_fields( (array) $field['sub_fields'] );
			}
			foreach ( (array) ( $field['layouts'] ?? array() ) as $i => $layout ) {
				$field['layouts'][ $i ]['sub_fields'] = self::rest_fields( (array) $layout['sub_fields'] );
			}
			$out[] = $field;
		}
		return $out;
	}

	public static function list_groups() {
		return rest_ensure_response( array_values( Groups::all( false, false ) ) );
	}

	public static function save_group( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		return rest_ensure_response( Groups::save( is_array( $data ) ? $data : array() ) );
	}

	public static function delete_group( \WP_REST_Request $request ) {
		return rest_ensure_response( array( 'deleted' => Groups::delete( (string) $request['key'] ) ) );
	}

	public static function import( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$list = is_array( $data ) ? ( $data['groups'] ?? null ) : null;
		if ( ! is_array( $list ) ) {
			return new \WP_Error( 'magicfields_import', __( 'This file does not contain field groups.', 'magicfields' ), array( 'status' => 400 ) );
		}
		if ( isset( $list['title'] ) || isset( $list['fields'] ) ) {
			$list = array( $list );
		}
		$done     = array();
		$warnings = array();
		foreach ( $list as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$group = Import::normalize( $group, $warnings );
			$saved = Groups::save( $group );
			if ( is_wp_error( $saved ) ) {
				$warnings[] = ( $group['title'] ?? '?' ) . ': ' . $saved->get_error_message();
			} else {
				$done[] = $saved['title'];
			}
		}
		return rest_ensure_response( array( 'imported' => $done, 'warnings' => array_values( array_unique( $warnings ) ) ) );
	}

	public static function get_pages() {
		return rest_ensure_response( Admin\OptionsPages::stored() );
	}

	public static function save_pages( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		return rest_ensure_response( Admin\OptionsPages::save( is_array( $data ) ? $data : array() ) );
	}

	/**
	 * Picker search for the relational fields.
	 */
	public static function search( \WP_REST_Request $request ) {
		$kind    = (string) $request['kind'];
		$search  = sanitize_text_field( (string) $request['s'] );
		$include = array_filter( array_map( 'absint', explode( ',', (string) $request['include'] ) ) );
		$out     = array();

		if ( 'term' === $kind ) {
			$taxonomy = sanitize_key( (string) $request['taxonomy'] );
			$tax      = get_taxonomy( $taxonomy );
			if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) ) {
				return rest_ensure_response( array() );
			}
			$args = array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 30 );
			if ( $include ) {
				$args['include'] = $include;
				$args['number']  = 0;
			} elseif ( '' !== $search ) {
				$args['search'] = $search;
			}
			$terms = get_terms( $args );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$out[] = array( 'id' => (int) $term->term_id, 'label' => html_entity_decode( $term->name, ENT_QUOTES ), 'sub' => $tax->labels->singular_name );
			}
		} elseif ( 'user' === $kind ) {
			if ( ! current_user_can( 'list_users' ) ) {
				return rest_ensure_response( array() );
			}
			$args  = array( 'number' => 30, 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_login' ) );
			$roles = array_filter( array_map( 'sanitize_key', explode( ',', (string) $request['roles'] ) ) );
			if ( $include ) {
				$args['include'] = $include;
				$args['number']  = -1;
			} else {
				if ( $roles ) {
					$args['role__in'] = $roles;
				}
				if ( '' !== $search ) {
					$args['search']         = '*' . $search . '*';
					$args['search_columns'] = array( 'user_login', 'display_name', 'user_email' );
				}
			}
			foreach ( get_users( $args ) as $user ) {
				$out[] = array( 'id' => (int) $user->ID, 'label' => $user->display_name, 'sub' => $user->user_login );
			}
		} else {
			$types = array_filter( array_map( 'sanitize_key', explode( ',', (string) $request['post_types'] ) ) );
			if ( ! $types ) {
				$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
			}
			$args = array(
				'post_type'           => $include ? 'any' : $types,
				'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'      => $include ? count( $include ) : 30,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'orderby'             => '' !== $search ? 'relevance' : 'date',
			);
			if ( $include ) {
				$args['post__in'] = $include;
				$args['orderby']  = 'post__in';
			} elseif ( '' !== $search ) {
				$args['s'] = $search;
			}
			foreach ( get_posts( $args ) as $post ) {
				if ( ! current_user_can( 'read_post', $post->ID ) ) {
					continue;
				}
				$type  = get_post_type_object( $post->post_type );
				$out[] = array(
					'id'    => (int) $post->ID,
					'label' => '' !== $post->post_title ? html_entity_decode( get_the_title( $post ), ENT_QUOTES ) : '#' . $post->ID,
					'sub'   => $type ? $type->labels->singular_name : $post->post_type,
				);
			}
		}
		return rest_ensure_response( $out );
	}
}
