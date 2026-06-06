<?php
/**
 * Benchmark the compiled MySQL parser against the interpreter.
 *
 * Expects a generated parser at /tmp/compiled.php (produced by
 * tests/tools/compile-grammar.php).
 */

set_error_handler(
	function ( $s, $m, $f, $l ) {
		throw new ErrorException( $m, 0, $s, $f, $l );
	}
);

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-node.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-parser.php';
require_once '/tmp/compiled.php';

$runs  = 5;
$limit = PHP_INT_MAX;
foreach ( $argv as $arg ) {
	if ( preg_match( '/^--runs=(\d+)$/', $arg, $m ) ) {
		$runs = (int) $m[1];
	}
	if ( preg_match( '/^--limit=(\d+)$/', $arg, $m ) ) {
		$limit = (int) $m[1];
	}
}

$grammar = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$handle  = fopen( __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv', 'r' );
$queries = array();
$header  = true;
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

$all_tokens = array();
foreach ( $queries as $q ) {
	$all_tokens[] = ( new WP_MySQL_Lexer( $q ) )->remaining_tokens();
}
echo 'Loaded ', count( $queries ), " queries\n";

function bench( $label, callable $factory, array $tokens_list, $runs ) {
	$results = array();
	for ( $r = 0; $r < $runs; $r++ ) {
		$fail  = 0;
		$start = microtime( true );
		foreach ( $tokens_list as $tokens ) {
			$parser = $factory( $tokens );
			$ast    = $parser->parse();
			if ( null === $ast ) {
				++$fail;
			}
		}
		$dur       = microtime( true ) - $start;
		$results[] = $dur;
		printf( "%-15s run %d: %.4fs, %d QPS, %d failures\n", $label, $r + 1, $dur, count( $tokens_list ) / $dur, $fail );
	}
	sort( $results );
	$best = $results[0];
	$avg  = array_sum( $results ) / count( $results );
	printf( "%-15s best %.4fs (%d QPS) avg %.4fs (%d QPS)\n", $label, $best, count( $tokens_list ) / $best, $avg, count( $tokens_list ) / $avg );
}

bench(
	'interpreted',
	fn( $tokens ) => new WP_MySQL_Parser( $grammar, $tokens ),
	$all_tokens,
	$runs
);
bench(
	'compiled',
	fn( $tokens ) => new WP_MySQL_Compiled_Parser( $grammar, $tokens ),
	$all_tokens,
	$runs
);
