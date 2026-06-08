<?php

/**
 * Benchmark MySQL-on-SQLite execution hot paths with and without the native
 * extension loaded.
 *
 * This script intentionally compares one process at a time. Run it once with
 * plain PHP and once with `-d extension=.../libwp_mysql_parser.dylib`.
 *
 * Options:
 *   --json             Print machine-readable benchmark output.
 *   --iterations=N     Timed workload iterations. Default: 1000.
 *   --warmup=N         Untimed workload iterations before measuring. Default: 100.
 *   --rows=N           Rows to seed in wp_posts. Default: 1000.
 *   --select-limit=N   LIMIT used by select workloads. Default: 10.
 *   --workload=MODE    select-found-rows, packed-select-found-rows, update,
 *                      or mixed. Default: mixed.
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$json         = in_array( '--json', $argv, true );
$iterations   = 1000;
$warmup       = 100;
$row_count    = 1000;
$select_limit = 10;
$workload     = 'mixed';
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--iterations=' ) ) {
		$iterations = max( 1, (int) substr( $arg, strlen( '--iterations=' ) ) );
	}
	if ( 0 === strpos( $arg, '--warmup=' ) ) {
		$warmup = max( 0, (int) substr( $arg, strlen( '--warmup=' ) ) );
	}
	if ( 0 === strpos( $arg, '--rows=' ) ) {
		$row_count = max( 20, (int) substr( $arg, strlen( '--rows=' ) ) );
	}
	if ( 0 === strpos( $arg, '--select-limit=' ) ) {
		$select_limit = max( 1, (int) substr( $arg, strlen( '--select-limit=' ) ) );
	}
	if ( 0 === strpos( $arg, '--workload=' ) ) {
		$workload = substr( $arg, strlen( '--workload=' ) );
	}
}

$workloads = array( 'select-found-rows', 'packed-select-found-rows', 'update', 'mixed' );
if ( ! in_array( $workload, $workloads, true ) ) {
	throw new InvalidArgumentException( sprintf( 'Unsupported --workload: %s', $workload ) );
}

require_once __DIR__ . '/../../src/load.php';

function sqlite_execution_benchmark_driver( string $db_path, int $row_count ): WP_PDO_MySQL_On_SQLite {
	$driver = new WP_PDO_MySQL_On_SQLite( sprintf( 'mysql-on-sqlite:path=%s;dbname=wp;', $db_path ) );
	$driver->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
	$driver->query(
		'CREATE TABLE wp_posts (' .
		'ID INTEGER PRIMARY KEY, ' .
		'post_status TEXT NOT NULL, ' .
		'post_title TEXT NOT NULL, ' .
		'menu_order INTEGER NOT NULL DEFAULT 0' .
		')'
	);

	for ( $i = 1; $i <= $row_count; $i++ ) {
		$status = 0 === $i % 3 ? 'draft' : 'publish';
		$driver->query(
			sprintf(
				"INSERT INTO wp_posts (ID, post_status, post_title, menu_order) VALUES (%d, '%s', 'Post %d', 0)",
				$i,
				$status,
				$i
			)
		);
	}

	return $driver;
}

function sqlite_execution_benchmark_select_found_rows( WP_PDO_MySQL_On_SQLite $driver, int $expected_found_rows, int $select_limit ): int {
	$stmt          = $driver->query(
		sprintf(
			"SELECT SQL_CALC_FOUND_ROWS ID, post_title FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT %d",
			$select_limit
		),
		PDO::FETCH_ASSOC
	);
	$rows          = $stmt->fetchAll();
	$expected_rows = min( $select_limit, $expected_found_rows );
	if ( count( $rows ) !== $expected_rows ) {
		throw new RuntimeException( sprintf( 'Expected %d rows, got %d.', $expected_rows, count( $rows ) ) );
	}
	$found_rows = (int) $driver->query( 'SELECT FOUND_ROWS()' )->fetchColumn();
	if ( $expected_found_rows !== $found_rows ) {
		throw new RuntimeException( sprintf( 'Expected %d FOUND_ROWS(), got %d.', $expected_found_rows, $found_rows ) );
	}

	return $found_rows + (int) $rows[0]['ID'] + strlen( $rows[0]['post_title'] );
}

function sqlite_execution_benchmark_checksum_packed_rows( string $packed_rows, int $found_rows, int $row_count, int $column_count ): int {
	$checksum = $found_rows + $row_count + $column_count;
	$length   = strlen( $packed_rows );
	for ( $i = 0; $i < $length; $i++ ) {
		$checksum += ord( $packed_rows[ $i ] );
	}
	return $checksum;
}

function sqlite_execution_benchmark_pack_value( $value ): string {
	if ( null === $value ) {
		return pack( 'V', 0xffffffff );
	}

	$bytes = (string) $value;
	return pack( 'V', strlen( $bytes ) ) . $bytes;
}

function sqlite_execution_benchmark_pack_rows( array $rows ): string {
	$packed_rows = '';
	foreach ( $rows as $row ) {
		foreach ( $row as $value ) {
			$packed_rows .= sqlite_execution_benchmark_pack_value( $value );
		}
	}
	return $packed_rows;
}

function sqlite_execution_benchmark_packed_select_found_rows(
	WP_PDO_MySQL_On_SQLite $driver,
	?object $native_connection,
	int $expected_found_rows,
	int $select_limit
): int {
	$sql           = sprintf(
		"SELECT SQL_CALC_FOUND_ROWS ID, post_title FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT %d",
		$select_limit
	);
	$expected_rows = min( $select_limit, $expected_found_rows );

	if ( null !== $native_connection ) {
		$result = $native_connection->queryMysqlPackedRows( $sql );
		if ( ! $result ) {
			throw new RuntimeException( 'Native packed query was not handled.' );
		}
		if ( $expected_found_rows !== $result->foundRows() ) {
			throw new RuntimeException(
				sprintf( 'Expected %d native packed FOUND_ROWS(), got %d.', $expected_found_rows, $result->foundRows() )
			);
		}
		if ( $expected_rows !== $result->rowCount() || 2 !== $result->columnCount() ) {
			throw new RuntimeException( 'Unexpected native packed result shape.' );
		}

		$packed_rows = method_exists( $result, 'takePackedRows' ) ? $result->takePackedRows() : $result->packedRows();
		return $result->checksum() + strlen( $packed_rows );
	}

	$stmt = $driver->query( $sql, PDO::FETCH_NUM );
	$rows = $stmt->fetchAll();
	if ( count( $rows ) !== $expected_rows ) {
		throw new RuntimeException( sprintf( 'Expected %d rows, got %d.', $expected_rows, count( $rows ) ) );
	}
	$found_rows = (int) $driver->query( 'SELECT FOUND_ROWS()' )->fetchColumn();
	if ( $expected_found_rows !== $found_rows ) {
		throw new RuntimeException( sprintf( 'Expected %d FOUND_ROWS(), got %d.', $expected_found_rows, $found_rows ) );
	}

	$packed_rows = sqlite_execution_benchmark_pack_rows( $rows );
	return sqlite_execution_benchmark_checksum_packed_rows( $packed_rows, $found_rows, count( $rows ), 2 ) + strlen( $packed_rows );
}

function sqlite_execution_benchmark_update( WP_PDO_MySQL_On_SQLite $driver ): int {
	$stmt = $driver->query( 'UPDATE wp_posts SET menu_order = menu_order + 1 WHERE ID = 1' );
	return $stmt->rowCount();
}

function sqlite_execution_benchmark_run_workload(
	WP_PDO_MySQL_On_SQLite $driver,
	?object $native_connection,
	string $workload,
	int $expected_found_rows,
	int $select_limit,
	int $iterations
): int {
	$checksum = 0;
	for ( $i = 0; $i < $iterations; $i++ ) {
		if ( 'select-found-rows' === $workload || 'mixed' === $workload ) {
			$checksum += sqlite_execution_benchmark_select_found_rows( $driver, $expected_found_rows, $select_limit );
		}
		if ( 'packed-select-found-rows' === $workload ) {
			$checksum += sqlite_execution_benchmark_packed_select_found_rows( $driver, $native_connection, $expected_found_rows, $select_limit );
		}
		if ( 'update' === $workload || 'mixed' === $workload ) {
			$checksum += sqlite_execution_benchmark_update( $driver );
		}
	}
	return $checksum;
}

$db_path = tempnam( sys_get_temp_dir(), 'wp-sqlite-exec-bench-' );
if ( ! is_string( $db_path ) ) {
	throw new RuntimeException( 'Unable to create temporary SQLite database path.' );
}

try {
	$driver              = sqlite_execution_benchmark_driver( $db_path, $row_count );
	$native_connection   = class_exists( 'WP_SQLite_Native_Connection', false ) ? new WP_SQLite_Native_Connection( $db_path ) : null;
	$expected_found_rows = $row_count - intdiv( $row_count, 3 );

	sqlite_execution_benchmark_run_workload( $driver, $native_connection, $workload, $expected_found_rows, $select_limit, $warmup );

	$start    = hrtime( true );
	$checksum = sqlite_execution_benchmark_run_workload( $driver, $native_connection, $workload, $expected_found_rows, $select_limit, $iterations );
	$duration = ( hrtime( true ) - $start ) / 1_000_000_000;
} finally {
	unlink( $db_path );
}

$queries_per_iteration = 'mixed' === $workload ? 3 : ( 'select-found-rows' === $workload ? 2 : 1 );
$result                = array(
	'benchmark'                  => 'sqlite-execution-hot-path',
	'implementation'             => class_exists( 'WP_MySQL_Native_Parser', false ) ? 'native-extension' : 'php',
	'native_sqlite'              => class_exists( 'WP_SQLite_Native_Connection', false ),
	'extension_loaded'           => extension_loaded( 'wp_mysql_parser' ),
	'workload'                   => $workload,
	'iterations'                 => $iterations,
	'warmup'                     => $warmup,
	'rows'                       => $row_count,
	'select_limit'               => $select_limit,
	'queries_per_iteration'      => $queries_per_iteration,
	'logical_queries'            => $iterations * $queries_per_iteration,
	'expected_found_rows'        => $expected_found_rows,
	'checksum'                   => $checksum,
	'duration'                   => $duration,
	'iterations_per_second'      => $iterations / $duration,
	'logical_queries_per_second' => ( $iterations * $queries_per_iteration ) / $duration,
	'php_version'                => PHP_VERSION,
);

if ( $json ) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
	exit;
}

printf(
	"%s %s: %.5fs, %.0f iterations/s, %.0f logical queries/s, checksum %d\n",
	$result['implementation'],
	$result['workload'],
	$result['duration'],
	$result['iterations_per_second'],
	$result['logical_queries_per_second'],
	$result['checksum']
);
