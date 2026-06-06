<?php
/**
 * Shared mega-pattern builder (shift-reduce + binary bottom-up).
 *
 * Build a "mega-pattern": an alternation of EVERY branch RHS across all
 * rules. Each RHS is a sequence of codepoint-encoded symbols. Terminals
 * (token ids) and non-terminals (rule ids) both get a codepoint; we put
 * non-terminals in a separate high plane so the two never collide.
 *
 * This is the pattern used by a bottom-up shift-reduce reducer: one
 * preg_replace_callback pass finds RHS occurrences and rewrites them to the
 * LHS non-terminal's codepoint, iterating to fixpoint.
 *
 * Encodings supported (for the #14 per-call-floor demonstration):
 *   utf8  : 3-byte UTF-8 codepoints (the #13 encoding, mb_chr(T+0x4000))
 *   byte1 : single-byte symbols (only valid when symbol-space < 256; we test
 *           the per-call floor on a TRUNCATED grammar that fits)
 *   byte4 : fixed 4-byte binary records (2-byte type tag + 2-byte slot), /s
 */

set_error_handler(
	function ( $s, $m, $f, $l ) {
		throw new ErrorException( $m, 0, $s, $f, $l );
	}
);

$src = '/Users/janjakes/.superset/worktrees/SQLite/performance/packages/mysql-on-sqlite/src';
require_once "$src/parser/class-wp-parser-grammar.php";
require_once "$src/parser/class-wp-parser-token.php";
require_once "$src/mysql/class-wp-mysql-token.php";
require_once "$src/mysql/class-wp-mysql-lexer.php";

const TOKEN_OFFSET = 0x4000; // terminals: token id -> mb_chr(id + 0x4000)
const NT_OFFSET    = 0x40000; // non-terminals: rule id -> mb_chr(rid + 0x40000) (separate plane)

function tok_char( $tid ) {
	return mb_chr( $tid + TOKEN_OFFSET, 'UTF-8' );
}
function nt_char( $rid ) {
	return mb_chr( $rid + NT_OFFSET, 'UTF-8' );
}

$grammar = new WP_Parser_Grammar( require "$src/mysql/mysql-grammar.php" );
$low_nt  = $grammar->lowest_non_terminal_id;
$rules   = $grammar->rules;

/**
 * Build the mega-pattern alternation over UTF-8 codepoint encoding.
 * Returns [pattern, alt_count, empty_branch_count].
 */
function build_mega_utf8( $rules, $low_nt ) {
	$alts          = array();
	$empty         = 0;
	foreach ( $rules as $rid => $branches ) {
		foreach ( $branches as $branch ) {
			if ( count( $branch ) === 0 ) {
				++$empty;
				continue; // epsilon branch: cannot be a bottom-up RHS pattern.
			}
			$s = '';
			foreach ( $branch as $sym ) {
				$s .= ( $sym < $low_nt ) ? tok_char( $sym ) : nt_char( $sym );
			}
			$alts[] = preg_quote( $s, '/' );
		}
	}
	// Sort longest-first so the alternation prefers maximal RHS (greedy reduce).
	usort(
		$alts,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	$pattern = '/(?:' . implode( '|', $alts ) . ')/u';
	return array( $pattern, count( $alts ), $empty );
}

if ( ( $argv[1] ?? '' ) === 'info' ) {
	list( $pattern, $altc, $empty ) = build_mega_utf8( $rules, $low_nt );
	printf( "rules=%d\n", count( $rules ) );
	printf( "alt_count (non-empty branches)=%d\n", $altc );
	printf( "empty/epsilon branches=%d\n", $empty );
	printf( "pattern bytes=%s\n", number_format( strlen( $pattern ) ) );

	ini_set( 'pcre.jit', '1' );
	$t  = microtime( true );
	$ok = @preg_match( $pattern, "\xff", $m );
	printf(
		"compile: %.2fms ok=%s err=%s\n",
		( microtime( true ) - $t ) * 1000,
		var_export( $ok, true ),
		preg_last_error_msg()
	);
	$study   = @preg_match( $pattern . 'S', "\xff" ); // not jit probe; do explicit below
	// JIT probe: run a tiny match many times; PCRE JIT is on if jit ini set & supported.
	printf( "pcre.jit ini=%s\n", ini_get( 'pcre.jit' ) );
	echo "PCRE version: " . ( defined( 'PCRE_VERSION' ) ? PCRE_VERSION : 'n/a' ) . "\n";
}

if ( ( $argv[1] ?? '' ) === 'compile' ) {
	list( $pattern, $altc, $empty ) = build_mega_utf8( $rules, $low_nt );
	ini_set( 'pcre.jit', '1' );
	// Valid UTF-8 probe (a known token codepoint).
	$probe = tok_char( 1 );
	$t     = microtime( true );
	$ok    = preg_match( $pattern, $probe, $m );
	printf(
		"compile+match valid probe: %.2fms ok=%s err=%s match=%s\n",
		( microtime( true ) - $t ) * 1000,
		var_export( $ok, true ),
		preg_last_error_msg(),
		$ok ? '"' . bin2hex( $m[0] ) . '"' : '-'
	);
	// Force a large run to confirm JIT engages without error.
	$reps = 200000;
	$t    = microtime( true );
	for ( $i = 0; $i < $reps; $i++ ) {
		preg_match( $pattern, $probe );
	}
	$d = microtime( true ) - $t;
	printf( "warm preg_match probe: %.0f QPS (err=%s)\n", $reps / $d, preg_last_error_msg() );
}
