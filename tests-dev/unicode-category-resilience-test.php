<?php
/**
 * Standalone test: four-byte Unicode guard, no perpetual-change loop, category
 * fallback decision, orphan category adoption (B19) and the utf8mb4 conversion
 * planner (ordering, skip, index-length decision).
 *
 * Run: php tests-dev/unicode-category-resilience-test.php
 *
 * PHP 7.4 compatible. No WordPress, no database, no network.
 */

require __DIR__ . '/stubs.php';

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) { unset( $GLOBALS['mobo_test_options'][ $name ] ); return true; }
}

$plugin = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $plugin . 'class-mobo-core-unicode-guard.php';
require $plugin . 'class-mobo-core-category-assignment-policy.php';
require $plugin . 'class-mobo-core-category-sync.php';
require $plugin . 'class-mobo-core-database-unicode-planner.php';
require $plugin . 'class-mobo-core-database-unicode-migration.php';

$G = 'Mobo_Core_Unicode_Guard';
$P = 'Mobo_Core_Database_Unicode_Planner';

$emoji  = "\xF0\x9F\x8E\x81"; // U+1F381 wrapped present.
$rocket = "\xF0\x9F\x9A\x80"; // U+1F680.
$math   = "\xF0\x9D\x90\x80"; // U+1D400 math bold A (not an emoji).
$fa     = 'هدیه';

/* ------------------------------------------------------------------ */
echo "Four-byte detection and replacement\n";
mobo_assert( $G::contains_supplementary( $fa . ' ' . $emoji ), 'detects emoji next to Persian' );
mobo_assert( ! $G::contains_supplementary( $fa . ' abc' ), 'Persian (2-byte) is not four-byte' );
mobo_assert( $G::contains_supplementary( $math ), 'detects non-emoji supplementary char' );
mobo_assert_same( $fa . ' &#x1f381;', $G::encode_supplementary( $fa . ' ' . $emoji ), 'entity format matches wp_encode_emoji (lowercase hex)' );
mobo_assert_same( '&#x1d400;', $G::encode_supplementary( $math ), 'non-emoji supplementary char encoded too' );
mobo_assert_same( $fa . ' ', $G::strip_supplementary( $fa . ' ' . $emoji ), 'strip removes only the four-byte char' );
mobo_assert_same( $G::encode_supplementary( $emoji . $rocket ), $G::encode_supplementary( $G::encode_supplementary( $emoji . $rocket ) ), 'encode is idempotent' );
mobo_assert_same( $fa . ' ' . $emoji, $G::canonical( $fa . ' &#x1f381;' ), 'canonical decodes supplementary entities' );
mobo_assert_same( 'A &amp; B &#1575;', $G::canonical( 'A &amp; B &#1575;' ), 'canonical leaves lower-plane entities alone' );
mobo_assert( $G::equivalent( 'Gift ' . $emoji, 'Gift &#x1f381;' ), 'raw source equivalent to guarded stored value' );
mobo_assert( ! $G::equivalent( 'Gift ' . $emoji, 'Gift' ), 'stripped value is not silently equivalent' );
mobo_assert_same( "Bad\x9F", $G::encode_supplementary( "Bad\xF0\x9F" ), 'dangling invalid lead byte removed (write cannot fail)' );
$json = $G::json_escape_supplementary( json_encode( array( 'title' => $fa . $emoji ), JSON_UNESCAPED_UNICODE ) );
mobo_assert( ! $G::contains_supplementary( $json ), 'JSON escape leaves no four-byte bytes' );
mobo_assert_same( array( 'title' => $fa . $emoji ), json_decode( $json, true ), 'JSON escape is lossless after json_decode' );
mobo_assert_same( array( 'k &#x1f381;' => array( 'v &#x1f680;' ) ), $G::downgrade_deep( array( 'k ' . $emoji => array( 'v ' . $rocket ) ) ), 'downgrade_deep handles serialized arrays incl. keys' );
$long = str_repeat( 'a', 195 ) . $emoji . $emoji;
mobo_assert_same( str_repeat( 'a', 195 ), $G::downgrade( $long, 200 ), 'falls back to strip when entities exceed column length' );

/* ------------------------------------------------------------------ */
echo "Per-column detection\n";
$charsets = array( 'wp_terms.name' => 'utf8mb3', 'wp_posts.post_title' => 'utf8', 'wp_postmeta.meta_value' => 'utf8mb4', 'wp_termmeta.meta_value' => null );
$calls = 0;
$G::set_test_resolver(
	function ( $table, $column ) use ( &$charsets, &$calls ) {
		$calls++;
		$k = $table . '.' . $column;
		return array_key_exists( $k, $charsets ) ? $charsets[ $k ] : 'utf8mb4';
	},
	'utf8mb4'
);
mobo_assert( ! $G::column_supports_supplementary( 'wp_terms', 'name' ), 'utf8mb3 column -> unsupported' );
mobo_assert( ! $G::column_supports_supplementary( 'wp_posts', 'post_title' ), 'utf8 column -> unsupported' );
mobo_assert( $G::column_supports_supplementary( 'wp_postmeta', 'meta_value' ), 'utf8mb4 column -> supported' );
mobo_assert( ! $G::column_supports_supplementary( 'wp_termmeta', 'meta_value' ), 'unknown metadata -> transform defensively' );
$before = $calls;
$G::column_supports_supplementary( 'wp_terms', 'name' );
mobo_assert_same( $before, $calls, 'result cached per request' );
mobo_assert_same( 'Shoes &#x1f381;', $G::prepare_for_column( 'Shoes ' . $emoji, 'wp_terms', 'name', 'test', 200 ), 'term name guarded on utf8mb3' );
mobo_assert_same( 'Shoes ' . $emoji, $G::prepare_for_column( 'Shoes ' . $emoji, 'wp_postmeta', 'meta_value', 'test' ), 'utf8mb4 column keeps the original' );
mobo_assert_same( $fa, $G::prepare_for_column( $fa, 'wp_terms', 'name', 'test' ), 'text without four-byte chars untouched' );
$events = $G::events();
mobo_assert( isset( $events['columns']['wp_terms.name'] ) && 1 === $events['count'], 'replacement recorded once per column per request' );

$G::set_test_resolver( function () { return 'utf8mb4'; }, 'utf8' );
mobo_assert( ! $G::column_supports_supplementary( 'wp_postmeta', 'meta_value' ), 'utf8 connection -> unsupported everywhere' );

/* ------------------------------------------------------------------ */
echo "No perpetual-change loop\n";
$charsets['wp_posts.post_title'] = 'utf8mb3';
$G::set_test_resolver( function ( $t, $c ) use ( &$charsets ) { $k = $t . '.' . $c; return array_key_exists( $k, $charsets ) ? $charsets[ $k ] : 'utf8mb4'; }, 'utf8mb4' );
$source = 'Phone case ' . $emoji;
$stored = $G::prepare_for_column( $source, 'wp_posts', 'post_title' ); // sync #1 writes this.
$writes = 0;
for ( $i = 0; $i < 5; $i++ ) { // syncs #2..#6 compare guarded source vs stored.
	if ( $G::prepare_for_column( $source, 'wp_posts', 'post_title' ) !== $stored ) {
		$writes++;
	}
}
mobo_assert_same( 0, $writes, 'unchanged source never looks changed on utf8mb3' );
$charsets['wp_posts.post_title'] = 'utf8mb4'; // table converted by the manual tool.
$G::set_test_resolver( function ( $t, $c ) use ( &$charsets ) { $k = $t . '.' . $c; return array_key_exists( $k, $charsets ) ? $charsets[ $k ] : 'utf8mb4'; }, 'utf8mb4' );
$writes = 0;
for ( $i = 0; $i < 5; $i++ ) {
	$desired = $G::prepare_for_column( $source, 'wp_posts', 'post_title' );
	if ( $desired !== $stored ) {
		$writes++;
		$stored = $desired;
	}
}
mobo_assert_same( 1, $writes, 'after conversion exactly one healing write restores the emoji' );
mobo_assert_same( $source, $stored, 'stored title is the original after healing' );

/* ------------------------------------------------------------------ */
echo "Category fallback decision\n";
$decide = function ( $over ) {
	return Mobo_Core_Category_Assignment_Policy::decide_missing_category_fallback(
		array_merge(
			array( 'is_new_product' => true, 'auto_categories_enabled' => true, 'mapping_enabled' => true, 'mapping_required' => false, 'resolved_count' => 0, 'missing_count' => 1, 'current_count' => 0, 'default_term_id' => 15, 'uncategorized_term_id' => 1 ),
			$over
		)
	);
};
$d = $decide( array() );
mobo_assert( 'assign_fallback' === $d['action'] && 15 === $d['term_id'] && 'default' === $d['kind'], 'new product, category not synced -> merchant default temporarily' );
mobo_assert( $d['pending'] && $d['request_category_sync'], 'marked pending + category sync requested' );
$d = $decide( array( 'default_term_id' => 0 ) );
mobo_assert( 'assign_fallback' === $d['action'] && 1 === $d['term_id'] && 'uncategorized' === $d['kind'], 'no default configured -> Uncategorized' );
$d = $decide( array( 'mapping_required' => true ) );
mobo_assert( 1 === $d['term_id'] && 'uncategorized' === $d['kind'] && $d['pending'], 'mapping required: fallback never replaces mapping -> Uncategorized, pending' );
$d = $decide( array( 'is_new_product' => false, 'current_count' => 2 ) );
mobo_assert( 'keep' === $d['action'] && $d['pending'], 'existing product keeps its categories, pending for re-apply' );
$d = $decide( array( 'resolved_count' => 1 ) );
mobo_assert( 'assign_resolved' === $d['action'] && $d['pending'], 'partially resolved -> assign resolved, remaining pending' );
$d = $decide( array( 'missing_count' => 0, 'resolved_count' => 2 ) );
mobo_assert( 'assign_resolved' === $d['action'] && ! $d['pending'] && ! $d['request_category_sync'], 'all resolved -> not pending' );
$d = $decide( array( 'auto_categories_enabled' => false, 'mapping_enabled' => false ) );
mobo_assert( ! $d['pending'] && $d['request_category_sync'], 'auto off + mapping off -> nothing can change it later, not pending' );
$d = $decide( array( 'auto_categories_enabled' => false, 'mapping_enabled' => true ) );
mobo_assert( $d['pending'], 'auto off but manual mapping on -> pending until mapped' );
$d = $decide( array( 'default_term_id' => 0, 'uncategorized_term_id' => 0 ) );
mobo_assert( 'none' === $d['action'], 'no usable category at all -> none (never blocks)' );

/* ------------------------------------------------------------------ */
echo "Orphan category adoption (B19)\n";
$guid  = '11111111-2222-4333-8444-555555555555';
$other = '99999999-2222-4333-8444-555555555555';
$C = 'Mobo_Core_Category_Sync';
mobo_assert( $C::orphan_term_is_adoptable( '', 0, 0, $guid, true ), 'orphan with same name/parent and no GUID is adopted' );
mobo_assert( $C::orphan_term_is_adoptable( $guid, 5, 5, $guid, true ), 'term already carrying this GUID is adopted' );
mobo_assert( ! $C::orphan_term_is_adoptable( $other, 0, 0, $guid, true ), 'term owned by another Source category is never stolen' );
mobo_assert( ! $C::orphan_term_is_adoptable( '', 3, 0, $guid, true ), 'different parent is not adopted' );
mobo_assert( ! $C::orphan_term_is_adoptable( '', 0, 0, $guid, false ), 'different name is not adopted' );
mobo_assert( ! $C::orphan_term_is_adoptable( $guid, 0, 0, $guid, true, true ), 'retired term with same GUID goes through the resurrection path instead' );

/* ------------------------------------------------------------------ */
echo "Conversion planner\n";
$modern = $P::server_info( '10.11.6-MariaDB' );
$old    = $P::server_info( '5.6.51-log', 'OFF', 'Antelope' );
$old_lp = $P::server_info( '5.6.51', 'ON', 'Barracuda' );
mobo_assert( $modern['modernPrefix'] && ! $modern['oldServer'] && $modern['isMariaDb'], 'MariaDB 10.11 is modern' );
mobo_assert( $old['oldServer'] && ! $old['largePrefixOn'], 'MySQL 5.6 without large prefix is old' );
mobo_assert( ! $P::server_info( '5.7.6' )['modernPrefix'] && $P::server_info( '5.7.7' )['modernPrefix'], 'MySQL 5.7.7 boundary' );

$s = $P::index_strategy( 'InnoDB', 'Dynamic', 764, $modern );
mobo_assert_same( 'convert', $s['strategy'], 'fits -> convert' );
$s = $P::index_strategy( 'InnoDB', 'Compact', 1020, $modern );
mobo_assert_same( 'convert_dynamic', $s['strategy'], 'COMPACT 767 limit exceeded on modern server -> ROW_FORMAT=DYNAMIC' );
$s = $P::index_strategy( 'InnoDB', 'Compact', 1020, $old );
mobo_assert_same( 'skip', $s['strategy'], 'old server without large prefix -> skip table with message' );
mobo_assert( '' !== $s['reason'], 'skip carries a clear reason' );
$s = $P::index_strategy( 'InnoDB', 'Compact', 1020, $old_lp );
mobo_assert_same( 'convert_dynamic', $s['strategy'], 'old server with large_prefix+Barracuda -> DYNAMIC' );
$s = $P::index_strategy( 'InnoDB', 'Dynamic', 4000, $modern );
mobo_assert_same( 'skip', $s['strategy'], 'beyond 3072 -> skip' );
mobo_assert_same( 'skip', $P::index_strategy( 'MyISAM', '', 1020, $modern )['strategy'], 'MyISAM over 1000 bytes -> skip' );
mobo_assert_same( 'convert', $P::index_strategy( 'MyISAM', '', 764, $modern )['strategy'], 'MyISAM under 1000 bytes -> convert' );

$idx = $P::index_bytes(
	array(
		array( 'INDEX_NAME' => 'slug', 'SUB_PART' => 191, 'DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => 200, 'CHARACTER_SET_NAME' => 'utf8mb3' ),
		array( 'INDEX_NAME' => 'meta_key', 'SUB_PART' => null, 'DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => 255, 'CHARACTER_SET_NAME' => 'utf8mb3' ),
		array( 'INDEX_NAME' => 'PRIMARY', 'SUB_PART' => null, 'DATA_TYPE' => 'bigint', 'CHARACTER_SET_NAME' => null ),
		array( 'INDEX_NAME' => 'ft', 'INDEX_TYPE' => 'FULLTEXT', 'DATA_TYPE' => 'text', 'CHARACTER_MAXIMUM_LENGTH' => 65535, 'CHARACTER_SET_NAME' => 'utf8mb3' ),
	)
);
mobo_assert_same( array( 'slug' => 764, 'meta_key' => 1020, 'PRIMARY' => 8, 'ft' => 0 ), $idx, 'index bytes: prefix x4, full varchar x4, int fixed, FULLTEXT ignored' );

$tables = array(
	array( 'TABLE_NAME' => 'wp_posts', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_COLLATION' => 'utf8mb3_general_ci', 'TABLE_ROWS' => 5000, 'DATA_LENGTH' => 9000000, 'INDEX_LENGTH' => 1000000 ),
	array( 'TABLE_NAME' => 'wp_options', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_COLLATION' => 'utf8mb3_general_ci', 'TABLE_ROWS' => 300, 'DATA_LENGTH' => 400000, 'INDEX_LENGTH' => 0 ),
	array( 'TABLE_NAME' => 'wp_mobo_category_map', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_COLLATION' => 'utf8mb3_general_ci', 'TABLE_ROWS' => 50, 'DATA_LENGTH' => 16384, 'INDEX_LENGTH' => 0 ),
	array( 'TABLE_NAME' => 'wp_termmeta', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Compact', 'TABLE_COLLATION' => 'utf8mb3_general_ci', 'TABLE_ROWS' => 80, 'DATA_LENGTH' => 32768, 'INDEX_LENGTH' => 0 ),
	array( 'TABLE_NAME' => 'wp_terms', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_COLLATION' => 'utf8mb3_general_ci', 'TABLE_ROWS' => 40, 'DATA_LENGTH' => 16384, 'INDEX_LENGTH' => 0 ),
	array( 'TABLE_NAME' => 'wp_users', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 3, 'DATA_LENGTH' => 16384, 'INDEX_LENGTH' => 0 ),
	array( 'TABLE_NAME' => 'wp_mixed', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 3, 'DATA_LENGTH' => 100, 'INDEX_LENGTH' => 0 ),
	array( 'TABLE_NAME' => 'other_site_posts', 'ENGINE' => 'InnoDB', 'ROW_FORMAT' => 'Dynamic', 'TABLE_COLLATION' => 'utf8mb3_general_ci', 'TABLE_ROWS' => 1, 'DATA_LENGTH' => 1, 'INDEX_LENGTH' => 0 ),
);
$columns = array(
	array( 'TABLE_NAME' => 'wp_users', 'COLUMN_NAME' => 'user_login', 'CHARACTER_SET_NAME' => 'utf8mb4' ),
	array( 'TABLE_NAME' => 'wp_mixed', 'COLUMN_NAME' => 'legacy', 'CHARACTER_SET_NAME' => 'utf8mb3' ),
	array( 'TABLE_NAME' => 'wp_terms', 'COLUMN_NAME' => 'name', 'CHARACTER_SET_NAME' => 'utf8mb3' ),
);
$index_rows = array(
	array( 'TABLE_NAME' => 'wp_termmeta', 'INDEX_NAME' => 'meta_key', 'SUB_PART' => null, 'DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => 255, 'CHARACTER_SET_NAME' => 'utf8mb3' ),
);
$plan  = $P::build( $tables, $columns, $index_rows, $modern, 'wp_' );
$order = array_column( $plan['entries'], 'table' );
mobo_assert_same( array( 'wp_terms', 'wp_termmeta', 'wp_mobo_category_map', 'wp_mixed', 'wp_options', 'wp_posts' ), $order, 'order: term tables, Mobo tables, then smallest first' );
mobo_assert_same( array( 'wp_users' ), $plan['alreadyReady'], 'utf8mb4 table skipped' );
mobo_assert( ! in_array( 'other_site_posts', $order, true ), 'tables outside the site prefix never planned' );
$by = array_combine( $order, $plan['entries'] );
mobo_assert_same( 'convert_dynamic', $by['wp_termmeta']['strategy'], 'COMPACT termmeta with 255-char key -> DYNAMIC' );
mobo_assert( in_array( 'legacy:utf8mb3', $by['wp_mixed']['badColumns'], true ), 'utf8mb4 table with a utf8mb3 column still planned' );
mobo_assert( $plan['totalEstimatedSeconds'] >= count( $order ), 'estimate is at least one second per table' );
$plan2 = $P::build( $tables, $columns, $index_rows, $old, 'wp_', array( 'wp_posts' ) );
$by2   = array_combine( array_column( $plan2['entries'], 'table' ), $plan2['entries'] );
mobo_assert_same( 'skip', $by2['wp_termmeta']['strategy'], 'old server: impossible index -> skip (no data loss)' );
mobo_assert( ! isset( $by2['wp_posts'] ), 'excluded (failed) tables left out of the re-plan' );
mobo_assert( $P::estimate_seconds( 100 * 1048576, 0, $old ) > $P::estimate_seconds( 100 * 1048576, 0, $modern ), 'old servers estimated slower' );

mobo_assert_same( 'ALTER TABLE `wp_terms` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', Mobo_Core_Database_Unicode_Migration::conversion_sql( 'wp_terms', 'utf8mb4_unicode_ci' ), 'conversion SQL' );
mobo_assert_same( 'ALTER TABLE `wp_x` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci', Mobo_Core_Database_Unicode_Migration::conversion_sql( 'wp_x', 'utf8mb4_unicode_520_ci', true ), 'conversion SQL with ROW_FORMAT=DYNAMIC' );
mobo_assert_same( 'ALTER TABLE `wp_x` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', Mobo_Core_Database_Unicode_Migration::conversion_sql( 'wp_x', 'latin1; DROP TABLE x' ), 'collation is whitelisted' );
mobo_assert( false === Mobo_Core_Database_Unicode_Migration::should_continue_automatically(), 'conversion never continues automatically (manual only)' );

mobo_finish();
