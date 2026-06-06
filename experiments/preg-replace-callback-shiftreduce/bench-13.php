<?php
/**
 * Per-call cost of the mega-pattern (shift-reduce) on the encoded
 * corpus, across four preg operations.
 *
 *   preg_match            (first match only)
 *   preg_match_all        (no offsets)
 *   preg_match_all        (PREG_OFFSET_CAPTURE)
 *   preg_replace_callback (no-op identity)
 *
 * Methodology: pre-lex + pre-encode all queries (excluded from timing); warm
 * the JIT; best-of-N. QPS = queries / best_run_time.
 */

set_error_handler(
	function ( $s, $m, $f, $l ) {
		throw new ErrorException( $m, 0, $s, $f, $l );
	}
);

const TOKEN_OFFSET = 0x4000;
const NT_OFFSET    = 0x40000;

$src = '/Users/janjakes/.superset/worktrees/SQLite/performance/packages/mysql-on-sqlite/src';
require_once "$src/parser/class-wp-parser-grammar.php";
require_once "$src/parser/class-wp-parser-token.php";
require_once "$src/mysql/class-wp-mysql-token.php";
require_once "$src/mysql/class-wp-mysql-lexer.php";

function tok_char( $tid ) {
	return mb_chr( $tid + TOKEN_OFFSET, 'UTF-8' );
}
function nt_char( $rid ) {
	return mb_chr( $rid + NT_OFFSET, 'UTF-8' );
}

$grammar = new WP_Parser_Grammar( require "$src/mysql/mysql-grammar.php" );
$low_nt  = $grammar->lowest_non_terminal_id;
$rules   = $grammar->rules;

$alts = array();
foreach ( $rules as $rid => $branches ) {
	foreach ( $branches as $branch ) {
		if ( count( $branch ) === 0 ) {
			continue;
		}
		$s = '';
		foreach ( $branch as $sym ) {
			$s .= ( $sym < $low_nt ) ? tok_char( $sym ) : nt_char( $sym );
		}
		$alts[] = preg_quote( $s, '/' );
	}
}
usort(
	$alts,
	function ( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	}
);
$pattern = '/(?:' . implode( '|', $alts ) . ')/u';

ini_set( 'pcre.jit', '1' );
ini_set( 'pcre.backtrack_limit', '100000000' );
ini_set( 'pcre.recursion_limit', '10000000' );

// Load + encode corpus (token-only, no non-terminals: this is the raw input
// a bottom-up reducer would see on pass 1).
$limit   = (int) ( $argv[1] ?? 5000 );
$handle  = fopen( __DIR__ . '/../../corpus/mysql-server-tests-queries.csv', 'r' );
$queries = array();
while ( ( $r = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$q = $r[0] ?? null;
	if ( null === $q || '' === $q ) {
		continue;
	}
	$queries[] = $q;
	if ( count( $queries ) >= $limit ) {
		break;
	}
}
fclose( $handle );

$encoded = array();
foreach ( $queries as $q ) {
	$tokens = ( new WP_MySQL_Lexer( $q ) )->remaining_tokens();
	$s      = '';
	foreach ( $tokens as $t ) {
		$s .= tok_char( $t->id );
	}
	$encoded[] = $s;
}
$n = count( $encoded );

$identity = function ( $m ) {
	return $m[0];
};

$ops = array(
	'preg_match (first)'        => function () use ( $pattern, $encoded ) {
		foreach ( $encoded as $s ) {
			preg_match( $pattern, $s );
		}
	},
	'preg_match_all (no off)'   => function () use ( $pattern, $encoded ) {
		foreach ( $encoded as $s ) {
			preg_match_all( $pattern, $s, $m );
		}
	},
	'preg_match_all (offsets)'  => function () use ( $pattern, $encoded ) {
		foreach ( $encoded as $s ) {
			preg_match_all( $pattern, $s, $m, PREG_OFFSET_CAPTURE );
		}
	},
	'preg_replace_callback noop' => function () use ( $pattern, $encoded, $identity ) {
		foreach ( $encoded as $s ) {
			preg_replace_callback( $pattern, $identity, $s );
		}
	},
);

$warmup = 2;
$runs   = 7;
printf( "corpus encoded: %d queries\n", $n );
foreach ( $ops as $name => $fn ) {
	for ( $w = 0; $w < $warmup; $w++ ) {
		$fn();
	}
	$best = INF;
	for ( $r = 0; $r < $runs; $r++ ) {
		$t = microtime( true );
		$fn();
		$d    = microtime( true ) - $t;
		$best = min( $best, $d );
	}
	printf( "%-28s %8.0f QPS\n", $name, $n / $best );
}
