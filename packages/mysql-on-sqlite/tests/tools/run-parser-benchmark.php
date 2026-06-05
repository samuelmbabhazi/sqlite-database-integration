<?php

/**
 * This script runs the MySQL parser on all queries from the MySQL server suite.
 * It tracks parsing failures and exceptions and measures parsing performance.
 * This is an end-to-end benchmark that includes lexing time in the results.
 *
 * Options:
 *   --json       Print machine-readable benchmark output.
 *   --limit=N    Only benchmark the first N queries.
 *   --consume=MODE
 *                How much AST data to consume after parsing:
 *                none        Only require parse() to return an AST (default).
 *                descendants Walk all descendants with get_descendants().
 */

// Throw exception if anything fails.
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$json    = in_array( '--json', $argv, true );
$limit   = null;
$consume = 'none';
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--limit=' ) ) {
		$limit = max( 1, (int) substr( $arg, strlen( '--limit=' ) ) );
	}
	if ( 0 === strpos( $arg, '--consume=' ) ) {
		$consume = substr( $arg, strlen( '--consume=' ) );
	}
}

if ( ! in_array( $consume, array( 'none', 'descendants' ), true ) ) {
	throw new InvalidArgumentException( sprintf( 'Unsupported --consume mode: %s', $consume ) );
}

// Use the integration loader so an already-loaded native extension selects
// the same public lexer/parser classes that runtime code uses.
require_once __DIR__ . '/../../src/load.php';

function get_stats( $total, $failures, $exceptions ) {
	return sprintf(
		'Total: %5d  |  Failures: %4d / %2d%%  |  Exceptions: %4d / %2d%%',
		$total,
		$failures,
		$failures / $total * 100,
		$exceptions,
		$exceptions / $total * 100
	);
}

// Load the MySQL grammar.
$grammar_data = include __DIR__ . '/../../src/mysql/mysql-grammar.php';
$grammar      = new WP_Parser_Grammar( $grammar_data );

// Load the bounded checked-in corpus before timing so file IO is excluded
// from the benchmark.
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

// Run the parser.
$failures    = array();
$exceptions  = array();
$processed   = 0;
$descendants = 0;
// Reuse a single parser across queries, mirroring the driver
// (WP_PDO_MySQL_On_SQLite::reset_or_create_parser), which resets tokens on the
// same instance rather than constructing a fresh parser per query.
$parser = null;
$start  = microtime( true );
foreach ( $queries as $query ) {
	try {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		if ( ( is_array( $tokens ) ? count( $tokens ) : $tokens->count() ) === 0 ) {
			throw new Exception( 'Failed to tokenize query: ' . $query );
		}

		if ( null === $parser ) {
			$parser = new WP_MySQL_Parser( $grammar, $tokens );
		} else {
			$parser->reset_tokens( $tokens );
		}
		$ast = $parser->parse();
		if ( null === $ast ) {
			$failures[] = $query;
		} elseif ( 'descendants' === $consume ) {
			$descendants += count( $ast->get_descendants() );
		}
	} catch ( Exception $e ) {
		$exceptions[] = $query;
	}

	$processed += 1;
	if ( ! $json && $processed > 0 && 0 === $processed % 1000 ) {
		echo get_stats( $processed, count( $failures ), count( $exceptions ) ), "\n";
	}
}
$duration = microtime( true ) - $start;
$qps      = $processed / $duration;

if ( $json ) {
	echo json_encode(
		array(
			'benchmark'        => 'mysql-parser',
			'implementation'   => class_exists( 'WP_MySQL_Native_Parser', false ) ? 'native-extension' : 'php',
			'extension_loaded' => extension_loaded( 'wp_mysql_parser' ),
			'queries'          => $processed,
			'consume'          => $consume,
			'descendants'      => $descendants,
			'duration'         => $duration,
			'qps'              => $qps,
			'failures'         => count( $failures ),
			'exceptions'       => count( $exceptions ),
			'php_version'      => PHP_VERSION,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
	exit;
}

echo get_stats( $processed, count( $failures ), count( $exceptions ) ), "\n";
printf( "AST consumption: %s", $consume );
if ( 'descendants' === $consume ) {
	printf( " (%d descendants)", $descendants );
}
echo "\n";

// Print the results.
printf( "\nParsed %d queries in %.5fs @ %d QPS.\n", $processed, $duration, $qps );
