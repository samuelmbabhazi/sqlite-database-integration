<?php
/**
 * Final multi-config benchmark for the parser exploration.
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

$runs = (int) ( $argv[1] ?? 10 );

$grammar = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$handle  = fopen( __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv', 'r' );
$queries = array();
$header  = true;
while ( ( $r = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	if ( $header ) {
		$header = false;
		continue; }
	if ( null !== $r[0] ) {
		$queries[] = $r[0];
	}
}
fclose( $handle );

$all_tokens = array();
foreach ( $queries as $q ) {
	$all_tokens[] = ( new WP_MySQL_Lexer( $q ) )->remaining_tokens();
}
$count = count( $queries );
printf( "Loaded %d queries\n", $count );

$durations = array();
for ( $i = 0; $i < $runs; $i++ ) {
	$start = microtime( true );
	$fail  = 0;
	foreach ( $all_tokens as $t ) {
		if ( null === ( new WP_MySQL_Parser( $grammar, $t ) )->parse() ) {
			++$fail;
		}
	}
	$d           = microtime( true ) - $start;
	$durations[] = $d;
}
sort( $durations );
$best = $durations[0];
$med  = $durations[ (int) ( count( $durations ) / 2 ) ];
$avg  = array_sum( $durations ) / count( $durations );
printf( "best %.4fs  %6d QPS\n", $best, $count / $best );
printf( "med  %.4fs  %6d QPS\n", $med, $count / $med );
printf( "avg  %.4fs  %6d QPS\n", $avg, $count / $avg );
