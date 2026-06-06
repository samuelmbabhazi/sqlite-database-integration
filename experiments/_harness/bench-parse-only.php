<?php
/**
 * Parse-only benchmark methodology:
 *   - Lex every query once, up front (lexer NOT part of timing).
 *   - Time parse() only, best-of-N after warmup iterations.
 *
 * Points at an arbitrary src tree so trunk / performance / experiment
 * branches can be measured with the identical harness:
 *
 *   php bench-parse-only.php --src=/abs/.../packages/mysql-on-sqlite/src \
 *       [--warmup=2] [--runs=5] [--limit=N] [--reuse] [--json]
 *
 * --reuse  reuse one parser via reset_tokens() (driver behaviour) instead of
 *          constructing a fresh parser per query.
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$src    = null;
$warmup = 2;
$runs   = 5;
$limit  = PHP_INT_MAX;
$reuse  = in_array( '--reuse', $argv, true );
$json   = in_array( '--json', $argv, true );
foreach ( $argv as $arg ) {
	if ( preg_match( '/^--src=(.+)$/', $arg, $m ) ) {
		$src = rtrim( $m[1], '/' );
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
require_once "$src/parser/class-wp-parser.php";
require_once "$src/mysql/class-wp-mysql-token.php";
require_once "$src/mysql/class-wp-mysql-lexer.php";
require_once "$src/mysql/class-wp-mysql-parser.php";

$grammar_data = include "$src/mysql/mysql-grammar.php";
$grammar      = new WP_Parser_Grammar( $grammar_data );

// Corpus loading identical to run-parser-benchmark.php (no header skip; drop
// null AND empty records).
$data_dir = __DIR__ . '/corpus';
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

// Pre-lex all queries (excluded from timing).
$all_tokens = array();
foreach ( $queries as $query ) {
	$lexer        = new WP_MySQL_Lexer( $query );
	$all_tokens[] = $lexer instanceof WP_MySQL_Native_Lexer
		? $lexer->native_token_stream()
		: $lexer->remaining_tokens();
}
$n = count( $queries );

$run_once = function () use ( $grammar, $all_tokens, $reuse ) {
	$failures = 0;
	$parser   = null;
	$start    = microtime( true );
	foreach ( $all_tokens as $tokens ) {
		if ( $reuse ) {
			if ( null === $parser ) {
				$parser = new WP_MySQL_Parser( $grammar, $tokens );
			} else {
				$parser->reset_tokens( $tokens );
			}
		} else {
			$parser = new WP_MySQL_Parser( $grammar, $tokens );
		}
		$ast = $parser->parse();
		if ( null === $ast ) {
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
	"queries=%d failures=%d  best=%d QPS  median=%d QPS  jit=%s php=%s\n",
	$n,
	$fail,
	$best,
	$median,
	$jit_on ? 'on' : 'off',
	PHP_VERSION
);
