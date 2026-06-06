<?php
/**
 * Parser performance benchmark with split timings.
 *
 * Separates lex time from parse time by pre-tokenizing all queries before
 * starting the parse-only timer. Reports total, average, and per-phase QPS.
 *
 * Usage:
 *   php bench-parser-split.php [--runs=N] [--limit=M]
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-node.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-parser.php';

$runs  = 1;
$limit = PHP_INT_MAX;
foreach ( $argv as $arg ) {
	if ( preg_match( '/^--runs=(\d+)$/', $arg, $m ) ) {
		$runs = (int) $m[1];
	}
	if ( preg_match( '/^--limit=(\d+)$/', $arg, $m ) ) {
		$limit = (int) $m[1];
	}
}

$grammar_data = include __DIR__ . '/../../src/mysql/mysql-grammar.php';
$grammar      = new WP_Parser_Grammar( $grammar_data );

$data_dir = __DIR__ . '/../mysql/data';
$handle   = fopen( "$data_dir/mysql-server-tests-queries.csv", 'r' );
$queries  = array();
$header   = true;
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	if ( $header ) {
		$header = false;
		continue;
	}
	if ( null !== $record[0] ) {
		$queries[] = $record[0];
	}
	if ( count( $queries ) >= $limit ) {
		break;
	}
}
fclose( $handle );
echo 'Loaded ', count( $queries ), " queries\n";

// Pre-tokenize all queries once. The tokens are reused across runs, so the
// parser starts from a cold AST cache each iteration but a warm token cache.
$lex_start  = microtime( true );
$all_tokens = array();
foreach ( $queries as $query ) {
	$lexer        = new WP_MySQL_Lexer( $query );
	$all_tokens[] = $lexer->remaining_tokens();
}
$lex_duration = microtime( true ) - $lex_start;
printf( "Lex: %.4fs, %d QPS\n", $lex_duration, count( $queries ) / $lex_duration );

// Parse benchmark.
$results = array();
for ( $r = 0; $r < $runs; $r++ ) {
	$failures = 0;
	$start    = microtime( true );
	foreach ( $all_tokens as $tokens ) {
		$parser = new WP_MySQL_Parser( $grammar, $tokens );
		$ast    = $parser->parse();
		if ( null === $ast ) {
			++$failures;
		}
	}
	$duration  = microtime( true ) - $start;
	$qps       = count( $queries ) / $duration;
	$results[] = array( $duration, $qps, $failures );
	printf( "Run %d: %.4fs, %d QPS, %d failures\n", $r + 1, $duration, $qps, $failures );
}

if ( $runs > 1 ) {
	$durations = array_column( $results, 0 );
	sort( $durations );
	$best = $durations[0];
	printf( "Best: %.4fs, %d QPS\n", $best, count( $queries ) / $best );
	$avg = array_sum( $durations ) / count( $durations );
	printf( "Avg:  %.4fs, %d QPS\n", $avg, count( $queries ) / $avg );
}
