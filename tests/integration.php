<?php
/**
 * Integration tests. Run against a WordPress install with the plugin active:
 *
 *   php tests/integration.php /path/to/wordpress
 *
 * They create and delete their own content (posts, a term, field groups). Run them
 * on a site with no field group of its own on posts: a group that reuses the test
 * field names ("related", "sections"…) on the same posts would change the results.
 */

if ( empty( $argv[1] ) || ! is_readable( $argv[1] . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/integration.php /path/to/wordpress\n" );
	exit( 2 );
}
require $argv[1] . '/wp-load.php';

use MagicFields\Groups;
use MagicFields\Locations;
use MagicFields\Values;
use MagicFields\Frontend;

$pass = 0;
$fail = 0;
function check( string $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		$pass++;
		return;
	}
	$fail++;
	echo "FAIL  $label\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n";
}

wp_set_current_user( 1 );

// ---- Group storage ---------------------------------------------------------
$test_groups = array( 'group_gftest', 'group_gftest_tax', 'group_gftest_opt', 'group_acftest', 'group_gftest_flex', 'group_gftest_shared', 'group_gftest_block', 'group_gftest_onblock', 'group_gftest_same' );
foreach ( $test_groups as $k ) {
	Groups::delete( $k );
}
$group = Groups::save(
	array(
		'key'      => 'group_gftest',
		'title'    => 'Test <b>group</b>',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ), array( array( 'param' => 'bogus', 'value' => 'x' ) ) ),
		'fields'   => array(
			array( 'label' => 'Sous-titre é', 'type' => 'text', 'default' => 'def', 'maxlength' => 10 ),
			array( 'label' => 'Price', 'name' => 'price', 'type' => 'number' ),
			array( 'label' => 'Featured', 'name' => 'featured', 'type' => 'true_false', 'default' => true ),
			array( 'label' => 'Color', 'name' => 'tone', 'type' => 'select', 'choices' => "red : Red\nblue : Blue", 'default' => 'blue' ),
			array( 'label' => 'Tags', 'name' => 'tags', 'type' => 'checkbox', 'choices' => array( array( 'value' => 'a', 'label' => 'A' ), array( 'value' => 'b', 'label' => 'B' ) ) ),
			array( 'label' => 'Body', 'name' => 'body', 'type' => 'wysiwyg' ),
			array( 'label' => 'When', 'name' => 'when', 'type' => 'date', 'display_format' => 'd/m/Y' ),
			array( 'label' => 'Cover', 'name' => 'cover', 'type' => 'image', 'return_format' => 'id' ),
			array( 'label' => 'Site', 'name' => 'site', 'type' => 'link' ),
			array( 'label' => 'Related', 'name' => 'related', 'type' => 'post_object', 'multiple' => true, 'return_format' => 'id' ),
			array( 'label' => 'Secret', 'name' => 'secret', 'type' => 'password' ),
			array( 'label' => 'Tab', 'type' => 'tab' ),
			array(
				'label'      => 'Steps',
				'name'       => 'steps',
				'type'       => 'repeater',
				'max'        => 3,
				'sub_fields' => array(
					array( 'label' => 'Title', 'name' => 'title', 'type' => 'text' ),
					array( 'label' => 'Minutes', 'name' => 'minutes', 'type' => 'number' ),
					array( 'label' => 'Meta', 'name' => 'meta', 'type' => 'group', 'sub_fields' => array( array( 'label' => 'Note', 'name' => 'note', 'type' => 'text' ) ) ),
				),
			),
			array( 'label' => 'Bad type', 'name' => 'weird', 'type' => 'does_not_exist' ),
		),
	)
);
check( 'group saved', is_array( $group ), true );
check( 'group key kept', $group['key'], 'group_gftest' );
check( 'title sanitized', $group['title'], 'Test group' );
check( 'invalid location rule dropped', count( $group['location'] ), 1 );
check( 'name generated from label', $group['fields'][0]['name'], 'sous_titre_e' );
check( 'choices parsed from text', $group['fields'][3]['choices'], array( array( 'value' => 'red', 'label' => 'Red' ), array( 'value' => 'blue', 'label' => 'Blue' ) ) );
check( 'unknown type falls back to text', end( $group['fields'] )['type'], 'text' );
check( 'nested sub fields kept', $group['fields'][12]['sub_fields'][2]['sub_fields'][0]['name'], 'note' );
check( 'field keys generated', 0 === strpos( $group['fields'][0]['key'], 'field_' ), true );

$dupe = Groups::save( array( 'title' => 'Dupe', 'fields' => array( array( 'name' => 'x', 'type' => 'text' ), array( 'name' => 'x', 'type' => 'text' ) ) ) );
check( 'duplicate names rejected', is_wp_error( $dupe ), true );
check( 'empty title rejected', is_wp_error( Groups::save( array( 'title' => '' ) ) ), true );

// Re-saving updates instead of duplicating.
$group['title'] = 'Test group 2';
$again          = Groups::save( $group );
check( 'update keeps id', $again['id'], $group['id'] );
check( 'update keeps field keys', $again['fields'][1]['key'], $group['fields'][1]['key'] );

// ---- Locations -------------------------------------------------------------
$post_id = wp_insert_post( array( 'post_title' => 'GF test', 'post_status' => 'publish', 'post_type' => 'post' ) );
$page_id = wp_insert_post( array( 'post_title' => 'GF page', 'post_status' => 'publish', 'post_type' => 'page' ) );
check( 'group matches a post', in_array( 'group_gftest', wp_list_pluck( Locations::groups_for( Locations::post_info( $post_id ) ), 'key' ), true ), true );
check( 'group does not match a page', in_array( 'group_gftest', wp_list_pluck( Locations::groups_for( Locations::post_info( $page_id ) ), 'key' ), true ), false );
$g = array( 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ), array( 'param' => 'page_template', 'operator' => '==', 'value' => 'tpl.php' ) ) ) );
check( 'template rule strict', Locations::matches( $g, Locations::post_info( $page_id ) ), false );
check( 'template rule loose', Locations::matches( $g, Locations::post_info( $page_id ), true ), true );
$g = array( 'location' => array( array( array( 'param' => 'post_type', 'operator' => '!=', 'value' => 'page' ) ) ) );
check( 'not-equal rule', array( Locations::matches( $g, Locations::post_info( $post_id ) ), Locations::matches( $g, Locations::post_info( $page_id ) ) ), array( true, false ) );
check( 'post rule never matches a term', Locations::matches( $g, array( 'kind' => 'term', 'taxonomy' => 'category' ) ), false );
check( 'empty location matches nothing', Locations::matches( array( 'location' => array() ), Locations::post_info( $post_id ) ), false );

// ---- Values ----------------------------------------------------------------
check( 'default before first save', magicfields_get( 'sous_titre_e', $post_id ), 'def' );
check( 'toggle default', magicfields_get( 'featured', $post_id ), true );
check( 'select default', magicfields_get( 'tone', $post_id ), 'blue' );
check( 'unknown field is null', magicfields_get( 'nope_nope', $post_id ), null );

$ctx    = array( 'kind' => 'post', 'id' => $post_id );
$groups = array( Groups::get( 'group_gftest' ) ); // Only ours, whatever else the site has.
Values::save(
	$ctx,
	$groups,
	array(
		'sous_titre_e' => "Hello <script>alert(1)</script> world that is long",
		'price'        => '12.50',
		'featured'     => 0,
		'tone'         => 'green',
		'tags'         => array( 'b', 'zzz', 'a' ),
		'body'         => '<p onclick="x()">Hi <strong>there</strong></p><script>bad()</script>',
		'when'         => '20260315',
		'cover'        => '0',
		'site'         => array( 'url' => 'javascript:alert(1)', 'title' => 'x' ),
		'related'      => array( $page_id, 'abc', $page_id ),
		'secret'       => 'it\'s "quoted" \\ backslash',
		'steps'        => array(
			array( 'title' => 'One', 'minutes' => '5', 'meta' => array( 'note' => 'n1', 'evil' => 'x' ), 'extra' => 'dropped' ),
			array( 'title' => 'Two', 'minutes' => 'abc' ),
			'not a row',
			array( 'title' => 'Three' ),
			array( 'title' => 'Four (over max)' ),
		),
		'not_a_field'  => 'ignored',
	)
);
check( 'text sanitized and truncated', get_post_meta( $post_id, 'sous_titre_e', true ), 'Hello worl' );
check( 'number stored', magicfields_get( 'price', $post_id ), 12.5 );
check( 'toggle off beats default', magicfields_get( 'featured', $post_id ), false );
check( 'invalid choice rejected, no default resurrected', magicfields_get( 'tone', $post_id ), '' );
check( 'checkbox filtered', magicfields_get( 'tags', $post_id ), array( 'b', 'a' ) );
check( 'wysiwyg kses', get_post_meta( $post_id, 'body', true ), '<p>Hi <strong>there</strong></p>bad()' );
check( 'date normalized', get_post_meta( $post_id, 'when', true ), '2026-03-15' );
check( 'date formatted', magicfields_get( 'when', $post_id ), '15/03/2026' );
check( 'empty image', magicfields_get( 'cover', $post_id ), null );
check( 'javascript: link rejected', get_post_meta( $post_id, 'site', true ), array( 'url' => '', 'title' => 'x', 'target' => '' ) );
check( 'relation ids', magicfields_get( 'related', $post_id ), array( $page_id ) );
check( 'slashes and quotes survive', get_post_meta( $post_id, 'secret', true ), 'it\'s "quoted" \\ backslash' );
$steps = magicfields_get( 'steps', $post_id );
check( 'repeater max rows', count( $steps ), 3 );
check( 'repeater row values', $steps[0], array( 'title' => 'One', 'minutes' => 5, 'meta' => array( 'note' => 'n1' ) ) );
check( 'repeater bad number emptied', $steps[1]['minutes'], '' );
check( 'unknown key not saved', metadata_exists( 'post', $post_id, 'not_a_field' ), false );
check( 'groups of other objects untouched', metadata_exists( 'post', $page_id, 'price' ), false );

check( 'update api', magicfields_update( 'price', '7', $post_id ) && 7 === magicfields_get( 'price', $post_id ), true );
check( 'raw api', magicfields_get( 'featured', $post_id, false ), '0' );

// ---- ACF data layout -------------------------------------------------------
$acf_post = wp_insert_post( array( 'post_title' => 'ACF data', 'post_status' => 'publish' ) );
update_post_meta( $acf_post, 'steps', '2' );
update_post_meta( $acf_post, 'steps_0_title', 'A' );
update_post_meta( $acf_post, 'steps_0_minutes', '3' );
update_post_meta( $acf_post, 'steps_0_meta_note', 'deep' );
update_post_meta( $acf_post, 'steps_1_title', 'B' );
update_post_meta( $acf_post, 'when', '20251224' );
$steps = magicfields_get( 'steps', $acf_post );
check( 'ACF flattened repeater read', array( $steps[0]['title'], $steps[0]['minutes'], $steps[0]['meta']['note'], $steps[1]['title'] ), array( 'A', 3, 'deep', 'B' ) );
check( 'ACF Ymd date read', magicfields_get( 'when', $acf_post ), '24/12/2025' );

// ---- ACF-style helpers -----------------------------------------------------
check( 'get_field alias', function_exists( 'get_field' ) ? get_field( 'price', $post_id ) : 'missing', 7 );
$seen = array();
while ( have_rows( 'steps', $post_id ) ) {
	the_row();
	$seen[] = get_sub_field( 'title' );
}
check( 'have_rows loop', $seen, array( 'One', 'Two', 'Three' ) );

// ---- Front-end rendering ---------------------------------------------------
magicfields_update( 'sous_titre_e', '<i>x</i>&y', $post_id );
check( 'html escapes text', magicfields_html( 'sous_titre_e', $post_id ), 'x&amp;y' );
check( 'shortcode', do_shortcode( '[magicfield name="tags" post_id="' . $post_id . '"]' ), 'B, A' );
check( 'password never rendered', magicfields_html( 'secret', $post_id ), '' );
$private = wp_insert_post( array( 'post_title' => 'Private', 'post_status' => 'private' ) );
magicfields_update( 'price', 99, $private );
wp_set_current_user( 0 );
check( 'private post hidden from visitors', do_shortcode( '[magicfield name="price" post_id="' . $private . '"]' ), '' );
check( 'binding hidden from visitors', Frontend::binding_value( array( 'key' => 'price' ), (object) array( 'context' => array( 'postId' => $private ) ), 'content' ), null );
check( 'binding on public post', Frontend::binding_value( array( 'key' => 'price' ), (object) array( 'context' => array( 'postId' => $post_id ) ), 'content' ), '7' );
wp_set_current_user( 1 );
$block = render_block( array( 'blockName' => 'magicfields/field', 'attrs' => array( 'name' => 'price', 'postId' => $post_id, 'tag' => 'h2' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
check( 'block renders', (bool) preg_match( '#^<h2 class="[^"]*wp-block-magicfields-field[^"]*">7</h2>$#', $block ), true );

// ---- REST ------------------------------------------------------------------
$server  = rest_get_server();
$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
$request->set_param( 'context', 'edit' );
$data = $server->dispatch( $request )->get_data();
check( 'REST edit context exposes values', $data['magicfields']->price ?? null, '7' );
$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
$data    = $server->dispatch( $request )->get_data();
check( 'REST view context is empty unless the group opts in', (array) $data['magicfields'], array() );
$again['show_in_rest'] = true;
$again                 = Groups::save( $again );
$data                  = (array) $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data()['magicfields'];
check( 'REST public values are formatted', array( $data['price'], $data['featured'], $data['related'], $data['steps'][0]['title'] ), array( 7, false, array( $page_id ), 'One' ) );
check( 'REST public values never include passwords', array_key_exists( 'secret', $data ), false );
wp_set_current_user( 0 );
$data = (array) $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data()['magicfields'];
check( 'REST public values readable by visitors', $data['price'], 7 );
check( 'REST private post closed to visitors', $server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $private ) )->get_status(), 401 );
$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
$request->set_header( 'content-type', 'application/json' );
$request->set_body( wp_json_encode( array( 'magicfields' => array( 'price' => 1 ) ) ) );
check( 'REST write closed to visitors', $server->dispatch( $request )->get_status(), 401 );
wp_set_current_user( 1 );
check( 'REST write by visitor changed nothing', magicfields_get( 'price', $post_id ), 7 );
$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
$request->set_header( 'content-type', 'application/json' );
$request->set_body( wp_json_encode( array( 'magicfields' => array( 'price' => 42, 'tone' => 'red', 'secret' => 'a "b" \\c' ) ) ) );
$response = $server->dispatch( $request );
check( 'REST update status', $response->get_status(), 200 );
check( 'REST update saved', array( magicfields_get( 'price', $post_id ), magicfields_get( 'tone', $post_id ), get_post_meta( $post_id, 'secret', true ) ), array( 42, 'red', 'a "b" \\c' ) );
check( 'REST update left others alone', magicfields_get( 'tags', $post_id ), array( 'b', 'a' ) );

wp_set_current_user( 0 );
$request = new WP_REST_Request( 'GET', '/magicfields/v1/groups' );
check( 'groups endpoint closed to visitors', $server->dispatch( $request )->get_status(), 401 );
$request = new WP_REST_Request( 'GET', '/magicfields/v1/search' );
check( 'search endpoint closed to visitors', $server->dispatch( $request )->get_status(), 401 );
$subscriber = wp_insert_user( array( 'user_login' => 'gfsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $subscriber );
$request = new WP_REST_Request( 'POST', '/magicfields/v1/groups' );
check( 'groups endpoint closed to subscribers', $server->dispatch( $request )->get_status(), 403 );
wp_set_current_user( 1 );
$request = new WP_REST_Request( 'GET', '/magicfields/v1/search' );
$request->set_query_params( array( 'kind' => 'post', 's' => 'GF page' ) );
$found = wp_list_pluck( $server->dispatch( $request )->get_data(), 'id' );
check( 'search finds the page', in_array( $page_id, $found, true ), true );

// ---- Terms, users, options -------------------------------------------------
Groups::save( array( 'key' => 'group_gftest_tax', 'title' => 'Tax', 'location' => array( array( array( 'param' => 'taxonomy', 'operator' => '==', 'value' => 'category' ) ), array( array( 'param' => 'user_role', 'operator' => '==', 'value' => 'all' ) ) ), 'fields' => array( array( 'label' => 'Icon', 'name' => 'gf_icon', 'type' => 'text' ) ) ) );
$term = wp_insert_term( 'GF cat ' . wp_rand(), 'category' );
$tctx = array( 'kind' => 'term', 'id' => $term['term_id'] );
Values::save( $tctx, Locations::groups_for( Locations::info_for( $tctx ) ), array( 'gf_icon' => 'star', 'price' => 5 ) );
check( 'term value', magicfields_get( 'gf_icon', 'term_' . $term['term_id'] ), 'star' );
check( 'term value via WP_Term', magicfields_get( 'gf_icon', get_term( $term['term_id'] ) ), 'star' );
check( 'post-only field not saved on term', metadata_exists( 'term', $term['term_id'], 'price' ), false );
$uctx = array( 'kind' => 'user', 'id' => $subscriber );
Values::save( $uctx, Locations::groups_for( Locations::info_for( $uctx ) ), array( 'gf_icon' => 'user' ) );
check( 'user value', magicfields_get( 'gf_icon', 'user_' . $subscriber ), 'user' );

Groups::save( array( 'key' => 'group_gftest_opt', 'title' => 'Opt', 'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'gftest' ) ) ), 'fields' => array( array( 'label' => 'Phone', 'name' => 'gf_phone', 'type' => 'text', 'default' => '000' ) ) ) );
$octx = array( 'kind' => 'option', 'id' => 0, 'page' => 'gftest' );
check( 'option default', magicfields_get( 'gf_phone', 'option' ), '000' );
Values::save( $octx, Locations::groups_for( Locations::info_for( $octx ) ), array( 'gf_phone' => '+34 600' ) );
check( 'option value', array( magicfields_get( 'gf_phone', 'option' ), get_option( 'options_gf_phone' ) ), array( '+34 600', '+34 600' ) );
Values::save( $octx, Locations::groups_for( Locations::info_for( $octx ) ), array( 'gf_phone' => '' ) );
check( 'emptied option does not fall back to default', magicfields_get( 'gf_phone', 'option' ), '' );
check( 'other options page gets nothing', count( Locations::groups_for( array( 'kind' => 'option', 'options_page' => 'other' ) ) ), 0 );

// ---- ACF import ------------------------------------------------------------
$acf = json_decode(
	'[{"key":"group_acftest","title":"ACF group","fields":[
	{"key":"field_a1","label":"Kind","name":"kind","type":"select","choices":{"a":"Alpha","b":"Beta"},"default_value":"b","wrapper":{"width":"50"}},
	{"key":"field_a2","label":"Day","name":"day","type":"date_picker","conditional_logic":[[{"field":"field_a1","operator":"==","value":"a"}]]},
	{"key":"field_a3","label":"Rel","name":"rel","type":"relationship","post_type":["page"]},
	{"key":"field_a4","label":"Flex","name":"flex","type":"flexible_content","layouts":{"layout_1":{"key":"layout_1","name":"hero","label":"Hero","sub_fields":[{"key":"field_a7","label":"Title","name":"title","type":"text"}]}}},
	{"key":"field_a8","label":"Shared","name":"shared","type":"clone","clone":["group_gftest"],"display":"group"},
	{"key":"field_a9","label":"Map","name":"map","type":"google_map"},
	{"key":"field_a5","label":"Rows","name":"rows","type":"repeater","sub_fields":[{"key":"field_a6","label":"T","name":"t","type":"color_picker"}]}
	],"location":[[{"param":"post_type","operator":"==","value":"page"}],[{"param":"comment","operator":"==","value":"all"}]],"menu_order":3,"position":"side","active":true}]',
	true
);
$request = new WP_REST_Request( 'POST', '/magicfields/v1/import' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body( wp_json_encode( array( 'groups' => $acf ) ) );
$result = $server->dispatch( $request )->get_data();
check( 'ACF import count', $result['imported'], array( 'ACF group' ) );
check( 'ACF import warnings', count( $result['warnings'] ), 2 );
$imported = Groups::get( 'group_acftest' );
$types    = wp_list_pluck( $imported['fields'], 'type' );
check( 'ACF types mapped', $types, array( 'select', 'date', 'post_object', 'flexible', 'clone', 'repeater' ) );
check( 'ACF flexible layouts', array( $imported['fields'][3]['layouts'][0]['name'], $imported['fields'][3]['layouts'][0]['sub_fields'][0]['name'] ), array( 'hero', 'title' ) );
check( 'ACF clone targets', $imported['fields'][4]['clone'], array( 'group_gftest' ) );
check( 'ACF choices/default/width', array( $imported['fields'][0]['choices'][1], $imported['fields'][0]['default'], $imported['fields'][0]['width'] ), array( array( 'value' => 'b', 'label' => 'Beta' ), 'b', 50 ) );
check( 'ACF condition mapped to name', $imported['fields'][1]['conditions'], array( array( 'field' => 'kind', 'operator' => '==', 'value' => 'a' ) ) );
check( 'ACF relationship is multiple', array( $imported['fields'][2]['multiple'], $imported['fields'][2]['post_types'] ), array( true, array( 'page' ) ) );
check( 'ACF sub field type', $imported['fields'][5]['sub_fields'][0]['type'], 'color' );
check( 'ACF order/position/keys', array( $imported['order'], $imported['position'], $imported['fields'][0]['key'] ), array( 3, 'side', 'field_a1' ) );

// ---- Flexible content ------------------------------------------------------
Groups::save(
	array(
		'key'      => 'group_gftest_shared',
		'title'    => 'Shared',
		'location' => array(),
		'fields'   => array(
			array( 'key' => 'field_sh1', 'label' => 'CTA label', 'name' => 'cta_label', 'type' => 'text', 'default' => 'Go' ),
			array( 'key' => 'field_sh2', 'label' => 'CTA url', 'name' => 'cta_url', 'type' => 'url' ),
		),
	)
);
Groups::save(
	array(
		'key'      => 'group_gftest_flex',
		'title'    => 'Flex',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields'   => array(
			array(
				'label'   => 'Sections',
				'name'    => 'sections',
				'type'    => 'flexible',
				'layouts' => array(
					array( 'label' => 'Hero', 'name' => 'hero', 'sub_fields' => array( array( 'label' => 'Title', 'name' => 'title', 'type' => 'text' ), array( 'label' => 'Big', 'name' => 'big', 'type' => 'true_false' ) ) ),
					array( 'label' => 'Quote', 'sub_fields' => array( array( 'label' => 'Text', 'name' => 'text', 'type' => 'textarea' ) ) ),
					array( 'label' => 'Hero again', 'name' => 'hero' ),
				),
			),
			array( 'label' => 'Seamless', 'name' => 'ignored', 'type' => 'clone', 'clone' => array( 'field_sh1' ) ),
			array( 'label' => 'Button', 'name' => 'button', 'type' => 'clone', 'display' => 'group', 'clone' => array( 'group_gftest_shared' ) ),
			array( 'label' => 'Loop', 'name' => 'loop', 'type' => 'clone', 'clone' => array( 'group_gftest_flex' ) ),
		),
	)
);
$stored = Groups::get( 'group_gftest_flex' );
check( 'flexible layouts saved, duplicate names dropped', wp_list_pluck( $stored['fields'][0]['layouts'], 'name' ), array( 'hero', 'quote' ) );
check( 'clone kept as is in storage', $stored['fields'][1]['type'], 'clone' );
$live = Groups::all()['group_gftest_flex'];
check( 'clone resolved when used', wp_list_pluck( $live['fields'], 'name' ), array( 'sections', 'cta_label', 'button' ) );
check( 'clone as group holds the fields', array( $live['fields'][2]['type'], wp_list_pluck( $live['fields'][2]['sub_fields'], 'name' ) ), array( 'group', array( 'cta_label', 'cta_url' ) ) );

$pctx = array( 'kind' => 'post', 'id' => $page_id );
Values::save(
	$pctx,
	array( $live ),
	array(
		'sections'  => array(
			array( 'acf_fc_layout' => 'hero', 'title' => 'Welcome', 'big' => 1, 'text' => 'wrong layout' ),
			array( 'acf_fc_layout' => 'nope', 'title' => 'x' ),
			array( 'acf_fc_layout' => 'quote', 'text' => "Line 1\nLine 2" ),
		),
		'cta_label' => 'Buy',
		'button'    => array( 'cta_label' => 'More', 'cta_url' => 'https://example.com/a' ),
	)
);
$sections = magicfields_get( 'sections', $page_id );
check( 'flexible rows', $sections, array( array( 'acf_fc_layout' => 'hero', 'title' => 'Welcome', 'big' => true ), array( 'acf_fc_layout' => 'quote', 'text' => "Line 1\nLine 2" ) ) );
check( 'seamless clone value', magicfields_get( 'cta_label', $page_id ), 'Buy' );
check( 'group clone value', magicfields_get( 'button', $page_id ), array( 'cta_label' => 'More', 'cta_url' => 'https://example.com/a' ) );
$layouts = array();
while ( have_rows( 'sections', $page_id ) ) {
	the_row();
	$layouts[] = get_row_layout();
}
check( 'get_row_layout loop', $layouts, array( 'hero', 'quote' ) );

$acf_page = wp_insert_post( array( 'post_title' => 'ACF flex', 'post_status' => 'publish', 'post_type' => 'page' ) );
update_post_meta( $acf_page, 'sections', array( 'quote', 'hero' ) );
update_post_meta( $acf_page, 'sections_0_text', 'Q' );
update_post_meta( $acf_page, 'sections_1_title', 'H' );
update_post_meta( $acf_page, 'button_cta_label', 'Flat' );
$sections = magicfields_get( 'sections', $acf_page );
check( 'ACF flattened flexible read', array( $sections[0]['acf_fc_layout'], $sections[0]['text'], $sections[1]['title'] ), array( 'quote', 'Q', 'H' ) );
check( 'ACF flattened group clone read', magicfields_get( 'button', $acf_page )['cta_label'], 'Flat' );
wp_delete_post( $acf_page, true );

// ---- Fields as blocks ------------------------------------------------------
Groups::save(
	array(
		'key'    => 'group_gftest_block',
		'title'  => 'Test card',
		'block'  => array(
			'enabled'  => true,
			'slug'     => 'Test Card!',
			'template' => '<h3 style="color:{{tone|raw}}">{{title}}</h3>{{#big}}<b>BIG</b>{{/big}}{{^big}}<i>small</i>{{/big}}<ul>{{#items}}<li>{{label}} ({{qty}})</li>{{/items}}</ul>{{^items}}none{{/items}}{{unknown}}',
		),
		'fields' => array(
			array( 'label' => 'Title', 'name' => 'title', 'type' => 'text', 'default' => 'Untitled' ),
			array( 'label' => 'Tone', 'name' => 'tone', 'type' => 'color' ),
			array( 'label' => 'Big', 'name' => 'big', 'type' => 'true_false' ),
			array( 'label' => 'Items', 'name' => 'items', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'Label', 'name' => 'label', 'type' => 'text' ), array( 'label' => 'Qty', 'name' => 'qty', 'type' => 'number' ) ) ),
		),
	)
);
check( 'block slug sanitized', Groups::get( 'group_gftest_block' )['block']['slug'], 'test-card' );
MagicFields\Blocks::register();
check( 'block registered', WP_Block_Type_Registry::get_instance()->is_registered( 'magicfields/test-card' ), true );
$render = static function ( array $data ) {
	return render_block( array( 'blockName' => 'magicfields/test-card', 'attrs' => array( 'data' => $data ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
};
$html = $render( array( 'title' => 'A <script>x</script>& B', 'tone' => '"><script>alert(1)</script>', 'big' => 1, 'items' => array( array( 'label' => '<b>One</b>', 'qty' => 2 ), array( 'label' => 'Two', 'qty' => 'x' ) ) ) );
check( 'block template rendered and escaped', $html, '<div class="wp-block-magicfields-test-card"><h3 style="color:">A &amp; B</h3><b>BIG</b><ul><li>One (2)</li><li>Two ()</li></ul></div>' );
check( 'block template defaults and empty sections', $render( array() ), '<div class="wp-block-magicfields-test-card"><h3 style="color:">Untitled</h3><i>small</i><ul></ul>none</div>' );

// ---- Fields on blocks ------------------------------------------------------
Groups::save(
	array(
		'key'      => 'group_gftest_onblock',
		'title'    => 'On block',
		'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => 'core/paragraph' ) ) ),
		'fields'   => array( array( 'label' => 'Tracking id', 'name' => 'tracking', 'type' => 'text' ), array( 'label' => 'Hide', 'name' => 'hide', 'type' => 'true_false' ) ),
	)
);
$paragraph = array( 'blockName' => 'core/paragraph', 'attrs' => array( 'magicfields' => array( 'tracking' => 'cta <1>', 'hide' => 1, 'other' => 'x' ) ), 'innerBlocks' => array(), 'innerHTML' => '<p>Hi</p>', 'innerContent' => array( '<p>Hi</p>' ) );
check( 'block field values', array_intersect_key( magicfields_get_block_fields( $paragraph ), array( 'tracking' => 1, 'hide' => 1, 'other' => 1 ) ), array( 'tracking' => 'cta', 'hide' => true ) );
$heading = $paragraph;
$heading['blockName'] = 'core/heading';
check( 'block fields only on targeted blocks', array_key_exists( 'tracking', magicfields_get_block_fields( $heading ) ), false );
$filter = static function ( $content, $fields ) {
	return $fields['hide'] ? '' : $content;
};
add_filter( 'magicfields_block_output', $filter, 10, 2 );
check( 'magicfields_block_output filter', render_block( $paragraph ), '' );
remove_filter( 'magicfields_block_output', $filter, 10 );
check( 'block untouched without filter', (bool) preg_match( '#^<p[^>]*>Hi</p>$#', trim( render_block( $paragraph ) ) ), true );

// ---- Two groups reusing a field name ---------------------------------------
Groups::save( array( 'key' => 'group_gftest_same', 'title' => 'Same name', 'order' => -50, 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'attachment' ) ) ), 'fields' => array( array( 'label' => 'Price', 'name' => 'price', 'type' => 'text' ), array( 'label' => 'Related', 'name' => 'related', 'type' => 'text' ) ) ) );
check( 'field read with the group shown on the object', array( magicfields_get( 'price', $post_id ), magicfields_get( 'related', $post_id ) ), array( 42, array( $page_id ) ) );
Groups::delete( 'group_gftest_same' );

// ---- PHP-registered groups -------------------------------------------------
magicfields_register_group( array( 'key' => 'group_gflocal', 'title' => 'Local', 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ), 'fields' => array( array( 'label' => 'L', 'name' => 'gf_local', 'type' => 'text', 'default' => 'loc' ) ) ) );
check( 'local group read', magicfields_get( 'gf_local', $page_id ), 'loc' );
check( 'local group cannot be overwritten', is_wp_error( Groups::save( array( 'key' => 'group_gflocal', 'title' => 'X' ) ) ), true );
check( 'local group cannot be deleted', Groups::delete( 'group_gflocal' ), false );

// ---- Inactive groups -------------------------------------------------------
$again['active'] = false;
Groups::save( $again );
check( 'inactive group not applied', in_array( 'group_gftest', wp_list_pluck( Locations::groups_for( Locations::post_info( $post_id ) ), 'key' ), true ), false );
check( 'inactive group still listed for admin', isset( Groups::all( false )['group_gftest'] ), true );

// ---- Cleanup ---------------------------------------------------------------
foreach ( array( $post_id, $page_id, $acf_post, $private ) as $id ) {
	wp_delete_post( $id, true );
}
wp_delete_term( $term['term_id'], 'category' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $subscriber );
delete_option( 'options_gf_phone' );
foreach ( $test_groups as $k ) {
	Groups::delete( $k );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
