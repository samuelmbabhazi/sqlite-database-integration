<?php

/**
 * Microbench: signature cost vs parse cost.
 *
 * Verifies the spec invariant that the cache key must be cheaper than
 * the parse it might skip. If signature work dominates, the cache is
 * the wrong shape.
 *
 * Usage:
 *   php profile-ast-cache-signature.php [--queries=N] [--iters=N]
 */

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-node.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-parser.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-parser-ast-cache.php';

$opts        = getopt( '', array( 'queries::', 'iters::' ) );
$query_count = (int) ( $opts['queries'] ?? 5000 );
$iters       = (int) ( $opts['iters'] ?? 5 );

$grammar         = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$grammar_version = (string) md5_file( __DIR__ . '/../../src/mysql/mysql-grammar.php' );

// Use a representative slice of the corpus.
$path   = __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv';
$handle = fopen( $path, 'r' );
fgetcsv( $handle, null, ',', '"', '\\' );
$queries = array();
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$query = $record[0] ?? null;
	if ( null === $query || '' === $query ) {
		continue;
	}
	$queries[] = $query;
	if ( count( $queries ) >= $query_count ) {
		break;
	}
}
fclose( $handle );

// Pre-tokenize.
$tokenized = array();
foreach ( $queries as $q ) {
	$lexer       = new WP_MySQL_Lexer( $q );
	$tokenized[] = $lexer->remaining_tokens();
}

$cache = new WP_MySQL_Parser_Ast_Cache( $grammar_version, max( 1, count( $queries ) ) );

// Pre-fill cache so all queries are hits.
foreach ( $tokenized as $tokens ) {
	$parser = new WP_MySQL_Parser( $grammar, $tokens, $cache );
	$parser->next_query();
}

function bench( callable $work, int $iters, int $count ): array {
	$samples = array();
	for ( $i = 0; $i < $iters; ++$i ) {
		$start = microtime( true );
		$work();
		$elapsed   = microtime( true ) - $start;
		$samples[] = $count / $elapsed;
	}
	sort( $samples );
	return $samples;
}

// Bench: parse only (no cache).
$parse_qps = bench(
	function () use ( $tokenized, $grammar ) {
		foreach ( $tokenized as $tokens ) {
			$parser = new WP_MySQL_Parser( $grammar, $tokens );
			$parser->next_query();
		}
	},
	$iters,
	count( $tokenized )
);

// Bench: signature only.
$sig_qps = bench(
	function () use ( $tokenized, $cache ) {
		$count = count( $tokenized );
		for ( $i = 0; $i < $count; ++$i ) {
			$tokens = $tokenized[ $i ];
			$cache->compute_signature( $tokens, 0, count( $tokens ) );
		}
	},
	$iters,
	count( $tokenized )
);

// Bench: full cache hit path (signature + lookup_by_key + clone).
$hit_qps = bench(
	function () use ( $tokenized, $grammar, $cache ) {
		foreach ( $tokenized as $tokens ) {
			$parser = new WP_MySQL_Parser( $grammar, $tokens, $cache );
			$parser->next_query();
		}
	},
	$iters,
	count( $tokenized )
);

function report( string $label, array $qps, int $count ): void {
	$best   = max( $qps );
	$median = $qps[ (int) ( count( $qps ) / 2 ) ];
	$us     = $best > 0 ? 1e6 / $best : 0;
	printf(
		"  %-22s best %8.0f QPS  median %8.0f QPS  (%.2f us/query)\n",
		$label,
		$best,
		$median,
		$us
	);
}

$jit = function_exists( 'opcache_get_status' ) && ( opcache_get_status( false )['jit']['on'] ?? false );
echo 'PHP ' . PHP_VERSION . '  JIT=' . ( $jit ? 'on' : 'off' ) . '  queries=' . count( $tokenized ) . "  iters=$iters\n\n";

report( 'parse only', $parse_qps, count( $tokenized ) );
report( 'signature only', $sig_qps, count( $tokenized ) );
report( 'cache hit path', $hit_qps, count( $tokenized ) );

$parse_us = 1e6 / max( $parse_qps );
$sig_us   = 1e6 / max( $sig_qps );
$hit_us   = 1e6 / max( $hit_qps );
$savings  = $parse_us - $hit_us;
printf(
	"\n  signature is %.1f%% of parse cost\n  hit path saves %.2f us/query (%.1f%% of parse)\n",
	100 * $sig_us / $parse_us,
	$savings,
	100 * $savings / $parse_us
);
