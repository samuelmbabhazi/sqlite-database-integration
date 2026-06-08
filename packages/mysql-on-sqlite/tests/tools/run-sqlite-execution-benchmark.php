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
 *   --workload=MODE    select-found-rows, update, or mixed. Default: mixed.
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$json       = in_array( '--json', $argv, true );
$iterations = 1000;
$warmup     = 100;
$row_count  = 1000;
$workload   = 'mixed';
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
	if ( 0 === strpos( $arg, '--workload=' ) ) {
		$workload = substr( $arg, strlen( '--workload=' ) );
	}
}

$workloads = array( 'select-found-rows', 'update', 'mixed' );
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

function sqlite_execution_benchmark_select_found_rows( WP_PDO_MySQL_On_SQLite $driver, int $expected_found_rows ): int {
	$stmt = $driver->query(
		"SELECT SQL_CALC_FOUND_ROWS ID, post_title FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT 10",
		PDO::FETCH_ASSOC
	);
	$rows = $stmt->fetchAll();
	if ( 10 !== count( $rows ) ) {
		throw new RuntimeException( sprintf( 'Expected 10 rows, got %d.', count( $rows ) ) );
	}
	$found_rows = (int) $driver->query( 'SELECT FOUND_ROWS()' )->fetchColumn();
	if ( $expected_found_rows !== $found_rows ) {
		throw new RuntimeException( sprintf( 'Expected %d FOUND_ROWS(), got %d.', $expected_found_rows, $found_rows ) );
	}

	return $found_rows + (int) $rows[0]['ID'] + strlen( $rows[0]['post_title'] );
}

function sqlite_execution_benchmark_update( WP_PDO_MySQL_On_SQLite $driver ): int {
	$stmt = $driver->query( 'UPDATE wp_posts SET menu_order = menu_order + 1 WHERE ID = 1' );
	return $stmt->rowCount();
}

function sqlite_execution_benchmark_run_workload(
	WP_PDO_MySQL_On_SQLite $driver,
	string $workload,
	int $expected_found_rows,
	int $iterations
): int {
	$checksum = 0;
	for ( $i = 0; $i < $iterations; $i++ ) {
		if ( 'select-found-rows' === $workload || 'mixed' === $workload ) {
			$checksum += sqlite_execution_benchmark_select_found_rows( $driver, $expected_found_rows );
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
	$expected_found_rows = $row_count - intdiv( $row_count, 3 );

	sqlite_execution_benchmark_run_workload( $driver, $workload, $expected_found_rows, $warmup );

	$start    = hrtime( true );
	$checksum = sqlite_execution_benchmark_run_workload( $driver, $workload, $expected_found_rows, $iterations );
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
