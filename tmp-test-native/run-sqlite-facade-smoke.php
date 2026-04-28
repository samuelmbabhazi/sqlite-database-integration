<?php

const DEFAULT_QUERY_COUNT = 2000;
const LONG_INSERT_ROWS    = 2000;
const TEST_TABLE          = 'wp_lazy_native_test';

function smoke_ok( string $message ): void {
	echo "OK: {$message}\n";
}

function smoke_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function create_driver(): WP_PDO_MySQL_On_SQLite {
	$db = new WP_PDO_MySQL_On_SQLite( 'mysql-on-sqlite:path=:memory:;dbname=wp;' );
	$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$db->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
	return $db;
}

function seed_database( WP_PDO_MySQL_On_SQLite $db ): void {
	$db->query(
		'CREATE TABLE `' . TEST_TABLE . "` (
			`id` bigint unsigned NOT NULL AUTO_INCREMENT,
			`tenant_id` int NOT NULL,
			`label` varchar(191) NOT NULL,
			`score` int DEFAULT 0,
			`payload` text,
			PRIMARY KEY (`id`),
			KEY `tenant_score` (`tenant_id`, `score`),
			KEY `label_score` (`label`, `score`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);

	for ( $i = 1; $i <= 64; $i++ ) {
		$db->query( make_insert_query( $i ) );
	}
}

function sql_string( string $value ): string {
	return "'" . str_replace( "'", "''", $value ) . "'";
}

function make_insert_query( int $i ): string {
	$tenant  = $i % 17;
	$score   = ( $i * 13 ) % 997;
	$label   = sql_string( "label_{$i}_tenant_{$tenant}" );
	$payload = sql_string( '{"seed":' . $i . ',"tenant":' . $tenant . '}' );

	return 'INSERT INTO `' . TEST_TABLE . "` (`tenant_id`, `label`, `score`, `payload`) VALUES ({$tenant}, {$label}, {$score}, {$payload})";
}

function make_long_multi_insert_query( int $i ): string {
	$rows = array();
	for ( $row = 0; $row < LONG_INSERT_ROWS; $row++ ) {
		$tenant  = ( $i + $row ) % 17;
		$score   = ( $i * 31 + $row * 7 ) % 997;
		$label   = sql_string( "bulk_{$i}_{$row}_tenant_{$tenant}" );
		$payload = sql_string( '{"bulk":' . $i . ',"row":' . $row . ',"tenant":' . $tenant . '}' );
		$rows[]  = "({$tenant}, {$label}, {$score}, {$payload})";
	}

	return 'INSERT INTO `' . TEST_TABLE . '` (`tenant_id`, `label`, `score`, `payload`) VALUES ' . implode( ",\n", $rows );
}

function make_select_query( int $i ): string {
	$tenant = $i % 17;
	$min    = ( $i * 7 ) % 400;
	$limit  = ( $i % 5 ) + 1;

	return 'SELECT `id`, `label`, `score`, CASE WHEN `score` >= ' . $min . " THEN 'high' ELSE 'low' END AS `bucket`
		FROM `" . TEST_TABLE . "`
		WHERE `tenant_id` = {$tenant}
			AND `score` BETWEEN {$min} AND 1000
			AND `label` LIKE 'label_%'
		ORDER BY `score` DESC, `id` ASC
		LIMIT {$limit}";
}

function make_aggregate_query( int $i ): string {
	$tenant_a = $i % 17;
	$tenant_b = ( $i + 3 ) % 17;

	return 'SELECT COUNT(*) AS `total`, COALESCE(MAX(`score`), 0) AS `max_score`, COALESCE(MIN(`score`), 0) AS `min_score`
		FROM `' . TEST_TABLE . "`
		WHERE (`tenant_id`, `score`) IN (({$tenant_a}, " . ( ( $i * 13 ) % 997 ) . "), ({$tenant_b}, " . ( ( $i * 17 ) % 997 ) . '))
			OR `label` IN (' . sql_string( "label_{$i}_tenant_{$tenant_a}" ) . ', ' . sql_string( "missing_{$i}" ) . ')';
}

function make_update_query( int $i ): string {
	$tenant = $i % 17;
	$delta  = ( $i % 5 ) + 1;
	$cutoff = ( $i * 11 ) % 997;

	return 'UPDATE `' . TEST_TABLE . "`
		SET `score` = `score` + {$delta}
		WHERE `tenant_id` = {$tenant}
			AND `score` < {$cutoff}";
}

function make_workload_query( int $i ): string {
	if ( 0 === $i % 250 ) {
		return make_long_multi_insert_query( $i );
	}

	$factories = array(
		'make_insert_query',
		'make_select_query',
		'make_aggregate_query',
		'make_update_query',
		'make_select_query',
		'make_aggregate_query',
	);

	$factory = $factories[ $i % count( $factories ) ];
	return $factory( $i + 64 );
}

function parse_with_sqlite_driver( WP_PDO_MySQL_On_SQLite $db, string $sql ): int {
	$parser      = $db->create_parser( $sql );
	$descendants = 0;

	while ( $parser->next_query() ) {
		$ast = $parser->get_query_ast();
		if ( ! $ast instanceof WP_Parser_Node ) {
			smoke_fail( 'parser did not return a WP_Parser_Node' );
		}

		$descendants += count( $ast->get_descendants() );
	}

	return $descendants;
}

function execute_with_sqlite_driver( WP_PDO_MySQL_On_SQLite $db, string $sql ): int {
	$result = $db->query( $sql );
	if ( ! $result instanceof PDOStatement ) {
		return 0;
	}

	return count( $result->fetchAll( PDO::FETCH_ASSOC ) );
}

function run_workload(): void {
	require_once dirname( __DIR__ ) . '/packages/mysql-on-sqlite/src/load.php';

	$query_count = (int) ( getenv( 'TMP_TEST_NATIVE_QUERY_COUNT' ) ?: DEFAULT_QUERY_COUNT );
	$db          = create_driver();
	seed_database( $db );

	$start        = microtime( true );
	$rows         = 0;
	$descendants  = 0;
	$long_inserts = 0;

	for ( $i = 1; $i <= $query_count; $i++ ) {
		$sql           = make_workload_query( $i );
		$long_inserts += 0 === $i % 250 ? 1 : 0;
		$descendants  += parse_with_sqlite_driver( $db, $sql );
		$rows         += execute_with_sqlite_driver( $db, $sql );
	}

	if ( $descendants < $query_count * 10 ) {
		smoke_fail( "parsed too few descendants ({$descendants})" );
	}

	smoke_ok(
		sprintf(
			'processed %d queries, including %d x %d-row multi-inserts, %d AST descendants, %d fetched rows in %.3fs',
			$query_count,
			$long_inserts,
			LONG_INSERT_ROWS,
			$descendants,
			$rows,
			microtime( true ) - $start
		)
	);
}

run_workload();
