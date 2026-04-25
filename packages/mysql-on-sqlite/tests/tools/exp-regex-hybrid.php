<?php
/**
 * Hybrid: regex pre-validation followed by the AST-building parser.
 *
 * Hypothesis: a PCRE2 match is a fast yes/no gate; if regex confirms
 * the input parses, the AST builder can run. Tests whether this
 * hybrid is faster than just running the parser.
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

const TOKEN_OFFSET = 0x4000;

// Reuse the regex compiler from exp-regex-v3 (a simplified inline copy).
function compile_regex( WP_Parser_Grammar $grammar ): string {
	$low_nt   = $grammar->lowest_non_terminal_id;
	$rules    = $grammar->rules;
	$nullable = array();
	$first    = array();
	foreach ( $rules as $rid => $_ ) {
		$nullable[ $rid ] = false;
		$first[ $rid ]    = array();
	}
	do {
		$changed = false;
		foreach ( $rules as $rid => $branches ) {
			foreach ( $branches as $branch ) {
				$bn = true;
				foreach ( $branch as $sym ) {
					if ( $sym < $low_nt ) {
						if ( ! isset( $first[ $rid ][ $sym ] ) ) {
							$first[ $rid ][ $sym ] = true;
							$changed               = true;
						}
						$bn = false;
						break;
					}
					foreach ( $first[ $sym ] as $tid => $_ ) {
						if ( ! isset( $first[ $rid ][ $tid ] ) ) {
							$first[ $rid ][ $tid ] = true;
							$changed               = true;
						}
					}
					if ( ! $nullable[ $sym ] ) {
						$bn = false;
						break;
					}
				}
				if ( $bn && ! $nullable[ $rid ] ) {
					$nullable[ $rid ] = true;
					$changed          = true;
				}
			}
		}
	} while ( $changed );

	$single_candidate_rules = $grammar->single_candidate_rules ?? array();
	$select_rid             = $grammar->get_rule_id( 'selectStatement' );
	$into_char              = mb_chr( WP_MySQL_Lexer::INTO_SYMBOL + TOKEN_OFFSET, 'UTF-8' );

	$compiled = array();
	$compile  = function ( $rid ) use ( &$compile, &$compiled, $rules, $low_nt, $single_candidate_rules, $select_rid, $into_char ) {
		if ( isset( $compiled[ $rid ] ) ) {
			return $compiled[ $rid ];
		}
		$alts = array();
		$st   = isset( $single_candidate_rules[ $rid ] );
		foreach ( $rules[ $rid ] as $branch ) {
			$alt = '';
			foreach ( $branch as $i => $sym ) {
				if ( $sym < $low_nt ) {
					$alt .= mb_chr( $sym + TOKEN_OFFSET, 'UTF-8' );
				} else {
					$alt .= "RREF{$sym}RREF";
				}
				if ( 0 === $i && $st ) {
					$alt .= '(*THEN)';
				}
			}
			$alts[] = $alt;
		}
		$body = '(?:' . implode( '|', $alts ) . ')';
		if ( $rid === $select_rid ) {
			$body .= '(?!' . $into_char . ')';
		}
		$compiled[ $rid ] = $body;
		return $compiled[ $rid ];
	};
	foreach ( array_keys( $rules ) as $rid ) {
		$compile( $rid );
	}

	// Inline single-use rules.
	do {
		$changed = false;
		$refs    = array();
		foreach ( $compiled as $rid => $_ ) {
			$refs[ $rid ] = 0;
		}
		foreach ( $compiled as $rid => $body ) {
			if ( preg_match_all( '/RREF(\d+)RREF/', $body, $m ) ) {
				foreach ( $m[1] as $r ) {
					$refs[ (int) $r ] = ( $refs[ (int) $r ] ?? 0 ) + 1;
				}
			}
		}
		foreach ( $compiled as $rid => $body ) {
			if ( ( $refs[ $rid ] ?? 0 ) !== 1 || strpos( $body, "RREF{$rid}RREF" ) !== false ) {
				continue;
			}
			foreach ( $compiled as $cr => $cb ) {
				if ( strpos( $cb, "RREF{$rid}RREF" ) !== false ) {
					$compiled[ $cr ] = str_replace( "RREF{$rid}RREF", $body, $cb );
					unset( $compiled[ $rid ] );
					$changed = true;
					break 2;
				}
			}
		}
	} while ( $changed );

	$rule_to_idx = array();
	foreach ( $compiled as $rid => $_ ) {
		$rule_to_idx[ $rid ] = count( $rule_to_idx );
	}
	$define = '';
	foreach ( $compiled as $rid => $body ) {
		$body    = preg_replace_callback(
			'/RREF(\d+)RREF/',
			function ( $m ) use ( $rule_to_idx ) {
				return '(?&r' . $rule_to_idx[ (int) $m[1] ] . ')';
			},
			$body
		);
		$define .= "(?<r{$rule_to_idx[$rid]}>{$body})";
	}
	$start_rid = $grammar->get_rule_id( 'query' );
	return '/(?(DEFINE)' . $define . ')\\A(?&r' . $rule_to_idx[ $start_rid ] . ')\\z/u';
}

$grammar = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$pattern = compile_regex( $grammar );

ini_set( 'pcre.backtrack_limit', '1000000000' );
ini_set( 'pcre.recursion_limit', '10000000' );
ini_set( 'pcre.jit', '1' );
ini_set( 'pcre.jit_stacksize', '32M' );

$handle  = fopen( __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv', 'r' );
$queries = array();
$header  = true;
while ( ( $r = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	if ( $header ) {
		$header = false;
		continue;
	}
	if ( null !== $r[0] ) {
		$queries[] = $r[0];
	}
}
$queries = array_slice( $queries, 0, (int) ( $argv[1] ?? 10000 ) );

// Pre-tokenize and pre-encode.
$pairs = array();
foreach ( $queries as $q ) {
	$tokens = ( new WP_MySQL_Lexer( $q ) )->remaining_tokens();
	$enc    = '';
	foreach ( $tokens as $t ) {
		$enc .= mb_chr( $t->id + TOKEN_OFFSET, 'UTF-8' );
	}
	$pairs[] = array( $tokens, $enc );
}
printf( "Loaded %d queries\n", count( $pairs ) );

// 1. Just regex match.
$start = microtime( true );
$ok    = 0;
foreach ( $pairs as $p ) {
	if ( @preg_match( $pattern, $p[1] ) === 1 ) {
		++$ok;
	}
}
$d = microtime( true ) - $start;
printf( "regex only:        %.4fs (%d QPS, %d/%d match)\n", $d, count( $pairs ) / $d, $ok, count( $pairs ) );

// 2. Just parser (build AST).
$start = microtime( true );
$ok    = 0;
foreach ( $pairs as $p ) {
	if ( ( new WP_MySQL_Parser( $grammar, $p[0] ) )->parse() ) {
		++$ok;
	}
}
$d = microtime( true ) - $start;
printf( "parser only (AST): %.4fs (%d QPS, %d/%d match)\n", $d, count( $pairs ) / $d, $ok, count( $pairs ) );

// 3. Hybrid: regex first; on success run the parser to build AST. Pure
//    overhead: same parser runs, plus the regex.
$start        = microtime( true );
$ok           = 0;
$regex_failed = 0;
foreach ( $pairs as $p ) {
	if ( @preg_match( $pattern, $p[1] ) !== 1 ) {
		++$regex_failed;
		continue;
	}
	if ( ( new WP_MySQL_Parser( $grammar, $p[0] ) )->parse() ) {
		++$ok;
	}
}
$d = microtime( true ) - $start;
printf(
	"regex + parser:    %.4fs (%d QPS, %d/%d match, %d regex-rejected)\n",
	$d,
	count( $pairs ) / $d,
	$ok,
	count( $pairs ),
	$regex_failed
);
