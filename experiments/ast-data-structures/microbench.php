<?php
/**
 * Microbench: object construction vs array literal, warm JIT.
 *   A) new WP_Parser_Node(1,'x',[])   (final class on the performance branch)
 *   B) [1, []]                         (array literal)
 * ~1e7 iters, best-of-N after warmup. Sinks results to defeat DCE.
 */
$src = "/Users/janjakes/.superset/worktrees/SQLite/performance/packages/mysql-on-sqlite/src";
require_once "$src/parser/class-wp-parser-node.php";

$iters = 10000000;
$empty = array();

$bench = function ( callable $fn ) use ( $iters ) {
	// Warmup.
	$s = 0;
	for ( $w = 0; $w < 2; $w++ ) {
		$t = microtime( true );
		$sink = null;
		for ( $i = 0; $i < $iters; $i++ ) {
			$sink = $fn( $i );
		}
		$s = microtime( true ) - $t;
	}
	$best = INF;
	for ( $r = 0; $r < 7; $r++ ) {
		$t = microtime( true );
		$sink = null;
		for ( $i = 0; $i < $iters; $i++ ) {
			$sink = $fn( $i );
		}
		$d = microtime( true ) - $t;
		if ( $d < $best ) {
			$best = $d;
		}
	}
	return $best;
};

$obj = $bench(
	function ( $i ) use ( $empty ) {
		return new WP_Parser_Node( 1, 'x', $empty );
	}
);
$arr = $bench(
	function ( $i ) use ( $empty ) {
		return array( 1, $empty );
	}
);

$obj_ns = $obj / $iters * 1e9;
$arr_ns = $arr / $iters * 1e9;

$jit = false;
$st  = opcache_get_status( false );
if ( is_array( $st ) && isset( $st['jit']['on'] ) ) {
	$jit = (bool) $st['jit']['on'];
}

printf(
	"WP_Parser_Node: %.2f ns/op   array literal: %.2f ns/op   ratio (obj/arr): %.2fx   jit=%s php=%s\n",
	$obj_ns,
	$arr_ns,
	$obj_ns / $arr_ns,
	$jit ? 'on' : 'off',
	PHP_VERSION
);
