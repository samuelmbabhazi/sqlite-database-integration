<?php
/**
 * Alternative AST data-structure variants benchmark.
 *
 * Four variants of the hot parse_recursive loop, all sharing the verified
 * performance-branch grammar + lexer. The ONLY difference between variants is
 * what parse_recursive produces on success:
 *
 *   V_Object : new WP_Parser_Node($rid, $name, $children)   (current / baseline)
 *   V_NoAST  : never build nodes/children; recognition only (true/false)
 *   V_Array  : array($rid, $children)
 *   V_Tape   : flat append-only int tape of (rule_id, child_count) events,
 *              with rollback (truncate) on branch failure.
 *
 * Parse-only: queries are pre-lexed once; only parse() is timed.
 * Usage: php bench-variants.php --src=/abs/.../src --variant=object|noast|array|tape
 *        [--warmup=2 --runs=7 --reuse --json --limit=N]
 *
 * Each variant runs in its own process (single --variant per invocation) to
 * avoid any cross-variant JIT / class-loading interference.
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$src     = null;
$variant = 'object';
$warmup  = 2;
$runs    = 7;
$limit   = PHP_INT_MAX;
$reuse   = in_array( '--reuse', $argv, true );
$json    = in_array( '--json', $argv, true );
foreach ( $argv as $arg ) {
	if ( preg_match( '/^--src=(.+)$/', $arg, $m ) ) {
		$src = rtrim( $m[1], '/' );
	}
	if ( preg_match( '/^--variant=(\w+)$/', $arg, $m ) ) {
		$variant = strtolower( $m[1] );
	}
	if ( preg_match( '/^--warmup=(\d+)$/', $arg, $m ) ) {
		$warmup = (int) $m[1];
	}
	if ( preg_match( '/^--runs=(\d+)$/', $arg, $m ) ) {
		$runs = (int) $m[1];
	}
	if ( preg_match( '/^--limit=(\d+)$/', $arg, $m ) ) {
		$limit = (int) $m[1];
	}
}
if ( null === $src ) {
	fwrite( STDERR, "Missing --src=PATH\n" );
	exit( 1 );
}

require_once "$src/parser/class-wp-parser-grammar.php";
require_once "$src/parser/class-wp-parser-node.php";
require_once "$src/parser/class-wp-parser-token.php";
require_once "$src/mysql/class-wp-mysql-token.php";
require_once "$src/mysql/class-wp-mysql-lexer.php";

require_once __DIR__ . '/parser-variants.php';

$class_map = array(
	'object' => 'WP_Variant_Parser_Object',
	'noast'  => 'WP_Variant_Parser_NoAst',
	'array'  => 'WP_Variant_Parser_Array',
	'tape'   => 'WP_Variant_Parser_Tape',
);
if ( ! isset( $class_map[ $variant ] ) ) {
	fwrite( STDERR, "Unknown --variant=$variant\n" );
	exit( 1 );
}
$parser_class = $class_map[ $variant ];

$grammar_data = include "$src/mysql/mysql-grammar.php";
$grammar      = new WP_Parser_Grammar( $grammar_data );

$data_dir = dirname( __DIR__, 2 ) . '/corpus';
$handle   = fopen( "$data_dir/mysql-server-tests-queries.csv", 'r' );
$queries  = array();
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$query = $record[0] ?? null;
	if ( null === $query || '' === $query ) {
		continue;
	}
	$queries[] = $query;
	if ( count( $queries ) >= $limit ) {
		break;
	}
}
fclose( $handle );

$all_tokens = array();
foreach ( $queries as $query ) {
	$lexer        = new WP_MySQL_Lexer( $query );
	$all_tokens[] = $lexer->remaining_tokens();
}
$n = count( $queries );

$run_once = function () use ( $grammar, $all_tokens, $reuse, $parser_class ) {
	$failures = 0;
	$parser   = null;
	$start    = microtime( true );
	foreach ( $all_tokens as $tokens ) {
		if ( $reuse ) {
			if ( null === $parser ) {
				$parser = new $parser_class( $grammar, $tokens );
			} else {
				$parser->reset_tokens( $tokens );
			}
		} else {
			$parser = new $parser_class( $grammar, $tokens );
		}
		$ast = $parser->parse();
		if ( null === $ast || false === $ast ) {
			++$failures;
		}
	}
	return array( microtime( true ) - $start, $failures );
};

for ( $i = 0; $i < $warmup; $i++ ) {
	$run_once();
}

$qpss = array();
$fail = 0;
for ( $r = 0; $r < $runs; $r++ ) {
	list( $duration, $failures ) = $run_once();
	$qpss[] = $n / $duration;
	$fail   = $failures;
}
sort( $qpss );
$best   = $qpss[ count( $qpss ) - 1 ];
$median = $qpss[ intdiv( count( $qpss ), 2 ) ];

$jit_on = false;
$status = opcache_get_status( false );
if ( is_array( $status ) && isset( $status['jit']['on'] ) ) {
	$jit_on = (bool) $status['jit']['on'];
}

if ( $json ) {
	echo json_encode(
		array(
			'variant'  => $variant,
			'queries'  => $n,
			'failures' => $fail,
			'qps_best' => $best,
			'qps_med'  => $median,
			'jit'      => $jit_on,
			'php'      => PHP_VERSION,
		)
	), "\n";
	exit;
}

printf(
	"variant=%-7s queries=%d failures=%d  best=%d QPS  median=%d QPS  jit=%s php=%s\n",
	$variant,
	$n,
	$fail,
	(int) $best,
	(int) $median,
	$jit_on ? 'on' : 'off',
	PHP_VERSION
);
