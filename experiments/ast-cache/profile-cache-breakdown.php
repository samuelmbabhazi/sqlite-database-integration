<?php

/**
 * Detailed breakdown of cache-hit cost.
 *
 * Reports per-query us across:
 *   - parse only (baseline)
 *   - signature only
 *   - sha1 of buffer alone
 *   - lookup only (precomputed key, no clone)
 *   - clone only (precomputed entry, no signature/lookup)
 *   - full hit path
 *
 * Identifies which sub-phase to optimize.
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

$tokenized = array();
foreach ( $queries as $q ) {
	$lexer       = new WP_MySQL_Lexer( $q );
	$tokenized[] = $lexer->remaining_tokens();
}

$cache = new WP_MySQL_Parser_Ast_Cache( $grammar_version, max( 1, count( $queries ) ) );

// Pre-fill cache and pre-parse to obtain ASTs for the clone bench.
$asts       = array();
$signatures = array();
foreach ( $tokenized as $tokens ) {
	$parser = new WP_MySQL_Parser( $grammar, $tokens, $cache );
	$parser->next_query();
	$ast          = $parser->get_query_ast();
	$asts[]       = $ast;
	$signatures[] = $cache->compute_signature( $tokens, 0, count( $tokens ) );
}

// Helper: time a closure for $count operations, return min/median/best QPS samples.
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

function us_per( array $qps ): float {
	$best = max( $qps );
	return $best > 0 ? 1e6 / $best : 0;
}

// 1. Parse only.
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

// 2. Signature only.
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

// 3. sha1 of typical buffer alone.
$buf      = str_repeat( "\x00\x01\x02\x03", 32 );  // 128 bytes representative
$sha1_qps = bench(
	function () use ( $buf ) {
		for ( $i = 0; $i < 5000; ++$i ) {
			sha1( $buf, true );
		}
	},
	$iters,
	5000
);

// 4. Lookup only (precomputed key, full hit path WITHOUT clone). We mock by
// using a closure that does the isset+LRU bookkeeping but skips clone.
class BenchCacheNoClone {
	public $entries;
	public $hits = 0;

	public function __construct( array $entries ) {
		$this->entries = $entries;
	}

	public function lookup_no_clone( string $key ): bool {
		if ( ! isset( $this->entries[ $key ] ) ) {
			return false;
		}
		++$this->hits;
		$entry = $this->entries[ $key ];
		unset( $this->entries[ $key ] );
		$this->entries[ $key ] = $entry;
		return true;
	}
}

$bench_entries = array();
foreach ( $signatures as $i => $key ) {
	$bench_entries[ $key ] = array( $asts[ $i ], 0 );
}
$bench_no_clone = new BenchCacheNoClone( $bench_entries );

$lookup_qps = bench(
	function () use ( $signatures, $bench_no_clone ) {
		foreach ( $signatures as $key ) {
			$bench_no_clone->lookup_no_clone( $key );
		}
	},
	$iters,
	count( $signatures )
);

// 5. Clone only (using cache lookup_by_key which clones).
$clone_qps = bench(
	function () use ( $signatures, $tokenized, $cache ) {
		$n = count( $signatures );
		for ( $i = 0; $i < $n; ++$i ) {
			$cache->lookup_by_key( $signatures[ $i ], $tokenized[ $i ], 0 );
		}
	},
	$iters,
	count( $signatures )
);

// 6. Full hit path via parser.
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

// 7. Per-query "build a fresh AST that's just the cached one with current tokens"
// by walking and mutating in-place (UNSAFE - measures upper bound for an
// in-place implementation that skips cloning).
function walk_tokens_in_place( $node, array $tokens, int $start, int &$index ): void {
	foreach ( $node->get_children() as $i => $child ) {
		if ( $child instanceof WP_Parser_Token ) {
			++$index;
		} else {
			walk_tokens_in_place( $child, $tokens, $start, $index );
		}
	}
}

$walk_qps = bench(
	function () use ( $asts, $tokenized ) {
		$n = count( $asts );
		for ( $i = 0; $i < $n; ++$i ) {
			$idx = 0;
			walk_tokens_in_place( $asts[ $i ], $tokenized[ $i ], 0, $idx );
		}
	},
	$iters,
	count( $asts )
);

$jit = function_exists( 'opcache_get_status' ) && ( opcache_get_status( false )['jit']['on'] ?? false );
echo 'PHP ' . PHP_VERSION . '  JIT=' . ( $jit ? 'on' : 'off' ) . '  queries=' . count( $tokenized ) . "  iters=$iters\n\n";

printf( "  %-22s best %8.0f QPS  (%5.2f us/query)\n", 'parse only', max( $parse_qps ), us_per( $parse_qps ) );
printf( "  %-22s best %8.0f QPS  (%5.2f us/query)\n", 'signature only', max( $sig_qps ), us_per( $sig_qps ) );
printf( "  %-22s best %8.0f QPS  (%5.2f us/buf)\n", 'sha1 128 bytes', max( $sha1_qps ), us_per( $sha1_qps ) );
printf( "  %-22s best %8.0f QPS  (%5.2f us/query)\n", 'lookup-only (no clone)', max( $lookup_qps ), us_per( $lookup_qps ) );
printf( "  %-22s best %8.0f QPS  (%5.2f us/query)\n", 'clone only (lookup_by_key)', max( $clone_qps ), us_per( $clone_qps ) );
printf( "  %-22s best %8.0f QPS  (%5.2f us/query)\n", 'full hit path', max( $hit_qps ), us_per( $hit_qps ) );
printf( "  %-22s best %8.0f QPS  (%5.2f us/query)\n", 'walk-only (no alloc)', max( $walk_qps ), us_per( $walk_qps ) );

// Sub-phase costs (rough decomposition).
$parse_us  = us_per( $parse_qps );
$sig_us    = us_per( $sig_qps );
$lookup_us = us_per( $lookup_qps );
$clone_us  = us_per( $clone_qps ); // includes signature recompute? no, clone_only used precomputed key
$walk_us   = us_per( $walk_qps );
$hit_us    = us_per( $hit_qps );

printf( "\n  signature cost: %.2f us/query\n", $sig_us );
printf( "  lookup-bookkeeping: %.2f us/query\n", $lookup_us );
printf( "  clone (lookup_by_key) total: %.2f us/query\n", $clone_us );
printf( "  walk-only (no allocations): %.2f us/query (lower bound for clone walk)\n", $walk_us );
printf( "  full hit path: %.2f us/query\n", $hit_us );
printf( "  parse cost: %.2f us/query\n", $parse_us );
printf( "  speedup ceiling at 100%% hit: %.2fx\n", $parse_us / $hit_us );
