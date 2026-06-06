<?php
/**
 * Per-call floor is independent of binary encoding width.
 *
 * We build the SAME mega-pattern (alternation of every non-empty branch RHS)
 * under different symbol encodings and measure the no-op
 * preg_replace_callback cost on the encoded corpus.
 *
 * Encodings:
 *   utf8  : 3-byte UTF-8 codepoints, /u   (the #13 encoding)
 *   byte4 : fixed 4-byte binary records (2-byte type tag + 2-byte id), /s
 *           Each symbol -> pack('n', tag) . pack('n', id):
 *             tag 0 = terminal, tag 1 = non-terminal.
 *
 * For byte4 we anchor each symbol on its 4-byte boundary implicitly: because
 * every record is exactly 4 bytes and the alternation only contains whole
 * records, matches always land on boundaries. /s makes '.' (unused here) span
 * newlines and disables UTF-8 validation; the bytes are arbitrary binary.
 *
 * The claim under test: regardless of encoding, the dominant cost is PCRE2's
 * "scan the subject, find all non-overlapping matches of a 4223-alt pattern" —
 * a per-byte automaton walk whose cost tracks subject length, not encoding
 * width. So all encodings hit the same floor.
 */

set_error_handler(
	function ( $s, $m, $f, $l ) {
		// Respect the @ silence operator so probe-compile failures are catchable.
		if ( 0 === ( error_reporting() & $s ) ) {
			return false;
		}
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

$grammar = new WP_Parser_Grammar( require "$src/mysql/mysql-grammar.php" );
$low_nt  = $grammar->lowest_non_terminal_id;
$rules   = $grammar->rules;

// ---- UTF-8 codepoint encoding ----
function u_tok( $t ) {
	return mb_chr( $t + TOKEN_OFFSET, 'UTF-8' );
}
function u_nt( $r ) {
	return mb_chr( $r + NT_OFFSET, 'UTF-8' );
}
// ---- 4-byte binary record encoding (2-byte tag + 2-byte id) ----
function b_tok( $t ) {
	return pack( 'nn', 0, $t );
}
function b_nt( $r ) {
	return pack( 'nn', 1, $r );
}
// ---- 2-byte binary record encoding ----
// 16-bit value, high bit = non-terminal flag, low 15 bits = id.
// Token ids and (rule_id - low_nt) both fit in 15 bits (< 32768).
function b2_tok( $t ) {
	return pack( 'n', $t & 0x7fff );
}
function b2_nt( $r ) {
	return pack( 'n', 0x8000 | ( $r & 0x7fff ) );
}
// ---- 3-byte binary record encoding (raw, not UTF-8) ----
function b3_tok( $t ) {
	return chr( 0 ) . pack( 'n', $t );
}
function b3_nt( $r ) {
	return chr( 1 ) . pack( 'n', $r );
}

function build_pattern( $rules, $low_nt, $tokfn, $ntfn, $flags ) {
	$alts = array();
	foreach ( $rules as $rid => $branches ) {
		foreach ( $branches as $branch ) {
			if ( count( $branch ) === 0 ) {
				continue;
			}
			$s = '';
			foreach ( $branch as $sym ) {
				$s .= ( $sym < $low_nt ) ? $tokfn( $sym ) : $ntfn( $sym );
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
	return '/(?:' . implode( '|', $alts ) . ')/' . $flags;
}

// Each encoding: [label, tok-fn, nt-fn, flags].
$encodings = array(
	'utf8 (3B codepoint, /u)' => array( 'u_tok', 'u_nt', 'u' ),
	'byte4 (2B tag+2B id, /s)' => array( 'b_tok', 'b_nt', 's' ),
	'byte3 (1B tag+2B id, /s)' => array( 'b3_tok', 'b3_nt', 's' ),
	'byte2 (16b tag+id, /s)'   => array( 'b2_tok', 'b2_nt', 's' ),
);

ini_set( 'pcre.jit', '1' );
ini_set( 'pcre.backtrack_limit', '100000000' );
ini_set( 'pcre.recursion_limit', '10000000' );

// ---- corpus ----
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

// Pre-lex once; encode the token stream under each encoding's terminal fn.
$token_ids = array();
foreach ( $queries as $q ) {
	$ids = array();
	foreach ( ( new WP_MySQL_Lexer( $q ) )->remaining_tokens() as $t ) {
		$ids[] = $t->id;
	}
	$token_ids[] = $ids;
}
$n = count( $queries );

$identity = function ( $m ) {
	return $m[0];
};

function bench_noop_callback( $pattern, $encoded, $identity, $warmup = 2, $runs = 7 ) {
	$fn = function () use ( $pattern, $encoded, $identity ) {
		foreach ( $encoded as $s ) {
			preg_replace_callback( $pattern, $identity, $s );
		}
	};
	for ( $w = 0; $w < $warmup; $w++ ) {
		$fn();
	}
	$best = INF;
	for ( $r = 0; $r < $runs; $r++ ) {
		$t = microtime( true );
		$fn();
		$best = min( $best, microtime( true ) - $t );
	}
	return count( $encoded ) / $best;
}

printf( "corpus: %d queries\n\n", $n );
printf( "%-28s %10s %8s %10s\n", 'encoding', 'pat_bytes', 'compiles', 'noop_QPS' );
foreach ( $encodings as $label => $cfg ) {
	list( $tokfn, $ntfn, $flags ) = $cfg;
	$pattern = build_pattern( $rules, $low_nt, $tokfn, $ntfn, $flags );
	// Compile test with a valid single-symbol probe.
	$probe = $tokfn( 1 );
	$ok    = @preg_match( $pattern, $probe );
	if ( false === $ok ) {
		printf( "%-28s %10s %8s %10s  (%s)\n", $label, number_format( strlen( $pattern ) ), 'NO', '-', preg_last_error_msg() );
		continue;
	}
	// Encode corpus for this encoding.
	$encoded = array();
	foreach ( $token_ids as $ids ) {
		$s = '';
		foreach ( $ids as $id ) {
			$s .= $tokfn( $id );
		}
		$encoded[] = $s;
	}
	$qps = bench_noop_callback( $pattern, $encoded, $identity );
	printf( "%-28s %10s %8s %10.0f\n", $label, number_format( strlen( $pattern ) ), 'yes', $qps );
}
