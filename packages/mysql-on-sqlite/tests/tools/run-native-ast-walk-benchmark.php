<?php

/**
 * Benchmark for the native AST walk path with the per-AST identity cache.
 *
 * Parses every query in the MySQL server suite, then walks each AST
 * exhaustively through `get_descendants()` and `get_first_child_node()`
 * loops to exercise the bridge accessors and the identity map. Reports
 * wall time, peak memory, and a basic identity-stability check so the
 * cache cost can be compared against the no-cache baseline.
 *
 * Usage:
 *   php run-native-ast-walk-benchmark.php          # walks via accessors
 *   php run-native-ast-walk-benchmark.php --no-walk # parse only, baseline
 *
 * The script auto-detects the native extension. Without it, the walk
 * exercises the pure-PHP WP_Parser_Node path, which is useful as the
 * "no-cache cost" reference point.
 */

set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

require_once __DIR__ . '/../../src/load.php';

$walk_tree = ! in_array( '--no-walk', $argv, true );

$grammar_data = include __DIR__ . '/../../src/mysql/mysql-grammar.php';
$grammar      = new WP_Parser_Grammar( $grammar_data );

$data_dir = __DIR__ . '/../mysql/data';
$handle   = fopen( "$data_dir/mysql-server-tests-queries.csv", 'r' );
$records  = array();
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$records[] = $record;
}
fclose( $handle );

$total       = 0;
$walked      = 0;
$identity_ok = true;
$failures    = 0;

if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$start = microtime( true );

for ( $i = 1; $i < count( $records ); $i += 1 ) {
	$query = $records[ $i ][0];
	if ( null === $query ) {
		continue;
	}

	try {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		$parser = new WP_MySQL_Parser( $grammar, $tokens );
		$ast    = $parser->parse();
		if ( null === $ast ) {
			++$failures;
			continue;
		}
		++$total;

		if ( ! $walk_tree ) {
			continue;
		}

		// Exhaustive descendant walk — exercises both the per-call accessor
		// path and (when the native extension is loaded) the identity map.
		$descendants = $ast->get_descendants();
		$walked     += count( $descendants );

		// Re-read the first child a few times and confirm identity is
		// stable. With the cache, this must be the same instance every
		// call; a regression would surface as a cheap, deterministic flag.
		$first = $ast->get_first_child_node();
		if ( null !== $first ) {
			$again = $ast->get_first_child_node();
			if ( $first !== $again ) {
				$identity_ok = false;
			}
		}
	} catch ( Throwable $e ) {
		++$failures;
	}
}

$duration = microtime( true ) - $start;
$peak_mb  = memory_get_peak_usage( true ) / 1024 / 1024;
$native   = class_exists( 'WP_MySQL_Native_Parser', false ) ? 'native' : 'php';

printf(
	"path=%s walk=%s parsed=%d walked_nodes=%d failures=%d duration=%.4fs qps=%d peak_mem=%.1fMB identity_ok=%s\n",
	$native,
	$walk_tree ? 'yes' : 'no',
	$total,
	$walked,
	$failures,
	$duration,
	$total > 0 ? (int) ( $total / $duration ) : 0,
	$peak_mb,
	$identity_ok ? 'true' : 'FALSE'
);

if ( ! $identity_ok ) {
	fwrite( STDERR, "Identity check failed: get_first_child_node() returned different instances.\n" );
	exit( 1 );
}
