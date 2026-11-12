<?php
namespace MagicFields;

defined( 'ABSPATH' ) || exit;

/**
 * Location rules: decide where a field group appears.
 *
 * A group's location is a list of rule sets. The group shows when every rule
 * of at least one set matches ("or" between sets, "and" inside a set).
 */
final class Locations {

	/**
	 * @return array<string,string> Rule parameter => kind of object it applies to.
	 */
	public static function params(): array {
		return array(
			'post_type'     => 'post',
			'page_template' => 'post',
			'post'          => 'post',
			'taxonomy'      => 'term',
			'user_role'     => 'user',
			'options_page'  => 'option',
			'block'         => 'block',
		);
	}

	/**
	 * Whether a group has at least one rule set made for this kind of object.
	 */
	public static function group_targets( array $group, string $kind ): bool {
		$params = self::params();
		foreach ( (array) ( $group['location'] ?? array() ) as $set ) {
			foreach ( $set as $rule ) {
				if ( ( $params[ $rule['param'] ] ?? '' ) === $kind ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array $info  Describes the edited object: kind plus post_type, post_id, page_template, taxonomy, roles or options_page.
	 * @param bool  $loose Ignore rules the block editor re-evaluates live (page template).
	 */
	public static function matches( array $group, array $info, bool $loose = false ): bool {
		foreach ( (array) ( $group['location'] ?? array() ) as $set ) {
			if ( ! $set ) {
				continue;
			}
			$ok = true;
			foreach ( $set as $rule ) {
				if ( ! self::rule_matches( $rule, $info, $loose ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	private static function rule_matches( array $rule, array $info, bool $loose ): bool {
		$params = self::params();
		$param  = $rule['param'] ?? '';
		if ( ( $params[ $param ] ?? '' ) !== ( $info['kind'] ?? '' ) ) {
			return false;
		}
		$value = (string) ( $rule['value'] ?? '' );
		switch ( $param ) {
			case 'post_type':
				$hit = 'all' === $value || ( $info['post_type'] ?? '' ) === $value;
				break;
			case 'page_template':
				if ( $loose ) {
					return true;
				}
				$hit = ( $info['page_template'] ?? 'default' ) === $value;
				break;
			case 'post':
				$hit = (int) ( $info['post_id'] ?? 0 ) === (int) $value && (int) $value > 0;
				break;
			case 'taxonomy':
				$hit = 'all' === $value || ( $info['taxonomy'] ?? '' ) === $value;
				break;
			case 'user_role':
				$hit = 'all' === $value || in_array( $value, (array) ( $info['roles'] ?? array() ), true );
				break;
			case 'options_page':
				$hit = ( $info['options_page'] ?? '' ) === $value;
				break;
			case 'block':
				$hit = 'all' === $value || ( $info['block'] ?? '' ) === $value;
				break;
			default:
				return false;
		}
		return '!=' === ( $rule['operator'] ?? '==' ) ? ! $hit : $hit;
	}

	/**
	 * @return array[] Active groups shown for the described object.
	 */
	public static function groups_for( array $info, bool $loose = false ): array {
		$out = array();
		foreach ( Groups::all() as $group ) {
			if ( self::matches( $group, $info, $loose ) ) {
				$out[] = $group;
			}
		}
		return $out;
	}

	public static function post_info( $post ): array {
		$post = get_post( $post );
		if ( ! $post ) {
			return array( 'kind' => 'post' );
		}
		return array(
			'kind'          => 'post',
			'post_type'     => $post->post_type,
			'post_id'       => $post->ID,
			'page_template' => get_page_template_slug( $post ) ?: 'default',
		);
	}

	/**
	 * Describes the object behind a value context (see Values::resolve()).
	 */
	public static function info_for( array $ctx ): array {
		switch ( $ctx['kind'] ) {
			case 'post':
				return self::post_info( $ctx['id'] );
			case 'term':
				$term = get_term( $ctx['id'] );
				return array( 'kind' => 'term', 'taxonomy' => $term instanceof \WP_Term ? $term->taxonomy : '' );
			case 'user':
				$user = get_userdata( $ctx['id'] );
				return array( 'kind' => 'user', 'roles' => $user ? (array) $user->roles : array() );
			default:
				return array( 'kind' => 'option', 'options_page' => (string) ( $ctx['page'] ?? '' ) );
		}
	}
}
