<?php
/**
 * Run the experimental LALR(1) parser over the MySQL server test corpus.
 * Tracks parse failures/exceptions and measures throughput, mirroring
 * run-parser-benchmark.php so the two can be compared directly.
 *
 * Options:
 *   --json         Machine-readable output.
 *   --limit=N      Only the first N queries.
 *   --show=N       Print the first N failing queries (to stderr).
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$src   = __DIR__ . '/../../src';
require_once $src . '/parser/class-wp-parser-token.php';
require_once $src . '/parser/class-wp-parser-node.php';
require_once $src . '/mysql/class-wp-mysql-token.php';
require_once $src . '/mysql/class-wp-mysql-lexer.php';
require_once $src . '/mysql/class-wp-mysql-lalr-parser.php';

$json  = in_array( '--json', $argv, true );
$limit = null;
$show  = 0;
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--limit=' ) ) {
		$limit = max( 1, (int) substr( $arg, strlen( '--limit=' ) ) );
	}
	if ( 0 === strpos( $arg, '--show=' ) ) {
		$show = max( 0, (int) substr( $arg, strlen( '--show=' ) ) );
	}
}

$table  = include $src . '/mysql/mysql-lalr-table.php';
$parser = new WP_MySQL_LALR_Parser( $table );
if ( in_array( '--prefix', $argv, true ) ) {
	$parser->set_prefix_mode( true );   // accept a complete leading statement (LL-equivalent)
}

$data_dir = __DIR__ . '/../mysql/data';
$handle   = fopen( "$data_dir/mysql-server-tests-queries.csv", 'r' );
$queries  = array();
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$query = $record[0] ?? null;
	if ( null === $query || '' === $query ) {
		continue;
	}
	$queries[] = $query;
	if ( null !== $limit && count( $queries ) >= $limit ) {
		break;
	}
}
fclose( $handle );

$failures   = array();
$exceptions = array();
$processed  = 0;
$start      = microtime( true );
foreach ( $queries as $query ) {
	try {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer->remaining_tokens();
		if ( 0 === count( $tokens ) ) {
			throw new Exception( 'empty token stream' );
		}
		$ast = $parser->parse( $tokens );
		if ( null === $ast ) {
			$failures[] = $query;
		}
	} catch ( Throwable $e ) {
		$exceptions[] = array( $query, $e->getMessage() );
	}
	++$processed;
}
$duration = microtime( true ) - $start;
$qps      = $processed / $duration;

if ( $json ) {
	echo json_encode(
		array(
			'benchmark'  => 'mysql-lalr-parser',
			'queries'    => $processed,
			'duration'   => $duration,
			'qps'        => $qps,
			'failures'   => count( $failures ),
			'exceptions' => count( $exceptions ),
			'php_version' => PHP_VERSION,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
} else {
	printf(
		"LALR: parsed %d queries in %.3fs @ %d QPS | failures %d (%.2f%%) | exceptions %d\n",
		$processed,
		$duration,
		$qps,
		count( $failures ),
		count( $failures ) / $processed * 100,
		count( $exceptions )
	);
}

if ( $show > 0 ) {
	fwrite( STDERR, "\n--- first $show failures ---\n" );
	foreach ( array_slice( $failures, 0, $show ) as $q ) {
		fwrite( STDERR, '  ' . substr( str_replace( "\n", ' ', $q ), 0, 200 ) . "\n" );
	}
	if ( $exceptions ) {
		fwrite( STDERR, "\n--- first $show exceptions ---\n" );
		foreach ( array_slice( $exceptions, 0, $show ) as $e ) {
			fwrite( STDERR, '  [' . $e[1] . '] ' . substr( str_replace( "\n", ' ', $e[0] ), 0, 160 ) . "\n" );
		}
	}
}
