<?php
/**
 * Regex grammar compiler v3: aggressively inline single-use rules and
 * use (*THEN) on every branch's first symbol so the matcher can't
 * backtrack into a sibling alternative once a token has been consumed.
 */

set_error_handler(
	function ( $s, $m, $f, $l ) {
		throw new ErrorException( $m, 0, $s, $f, $l );
	}
);

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';

const TOKEN_OFFSET = 0x4000;

function token_char( $tid ) {
	return mb_chr( $tid + TOKEN_OFFSET, 'UTF-8' );
}

$grammar = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$low_nt  = $grammar->lowest_non_terminal_id;

// Count how many times each rule is referenced.
function ref_counts( WP_Parser_Grammar $g ) {
	$low_nt = $g->lowest_non_terminal_id;
	$refs   = array();
	foreach ( $g->rules as $rid => $branches ) {
		$refs[ $rid ] = 0;
	}
	foreach ( $g->rules as $rid => $branches ) {
		foreach ( $branches as $b ) {
			foreach ( $b as $sym ) {
				if ( $sym >= $low_nt ) {
					$refs[ $sym ] = ( $refs[ $sym ] ?? 0 ) + 1;
				}
			}
		}
	}
	return $refs;
}

// FIRST and NULLABLE.
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

// Compile each rule into a "regex body" string. Inline single-use
// non-recursive rules into their callers transitively via memoization.
$single_candidate_rules = $grammar->single_candidate_rules ?? array();
$select_rid             = $grammar->get_rule_id( 'selectStatement' );
$into_char              = token_char( WP_MySQL_Lexer::INTO_SYMBOL );
$compiled               = array();
$visiting               = array();
$compile_rule           = function ( $rid ) use ( &$compile_rule, &$compiled, &$visiting, $rules, $first, $nullable, $low_nt, $single_candidate_rules, $select_rid, $into_char ) {
	if ( isset( $compiled[ $rid ] ) ) {
		return $compiled[ $rid ];
	}
	$visiting[ $rid ] = true;
	$alts             = array();
	$safe_then        = isset( $single_candidate_rules[ $rid ] );
	foreach ( $rules[ $rid ] as $branch ) {
		$alt = '';
		foreach ( $branch as $i => $sym ) {
			if ( $sym < $low_nt ) {
				$alt .= token_char( $sym );
			} else {
				$alt .= "RREF{$sym}RREF";
			}
			// (*THEN) commits the alternative once the first symbol matches.
			// Only safe when sibling branches of this rule have disjoint
			// FIRST sets - that property is captured by
			// $grammar->single_candidate_rules. Outside that set, multiple
			// branches can share a first token and committing prematurely
			// would yield spurious match failures.
			if ( 0 === $i && $safe_then ) {
				$alt .= '(*THEN)';
			}
		}
		$alts[] = $alt;
	}
	unset( $visiting[ $rid ] );
	$body = '(?:' . implode( '|', $alts ) . ')';
	if ( $rid === $select_rid ) {
		// Mirror the negative lookahead the parser uses: a successful
		// selectStatement match must not be followed by INTO. Otherwise
		// the surrounding rule should pick a different alternative.
		$body .= '(?!' . $into_char . ')';
	}
	$compiled[ $rid ] = $body;
	return $compiled[ $rid ];
};

// First pass: compile every rule once.
foreach ( array_keys( $rules ) as $rid ) {
	$compile_rule( $rid );
}

// Second pass: inline single-use non-recursive rules. A rule is
// inlinable if its body doesn't reference itself transitively. Repeat
// to fixpoint - inlining changes ref counts.
$inlined_count = 0;
do {
	$changed = false;
	$refs    = array();
	foreach ( $compiled as $rid => $body ) {
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
		if ( ( $refs[ $rid ] ?? 0 ) !== 1 ) {
			continue;
		}
		// Don't inline recursive rules.
		if ( strpos( $body, "RREF{$rid}RREF" ) !== false ) {
			continue;
		}
		// Replace the single reference somewhere.
		foreach ( $compiled as $caller_rid => $caller_body ) {
			if ( strpos( $caller_body, "RREF{$rid}RREF" ) !== false ) {
				$compiled[ $caller_rid ] = str_replace( "RREF{$rid}RREF", $body, $caller_body );
				unset( $compiled[ $rid ] );
				++$inlined_count;
				$changed = true;
				break 2; // restart from top so refs recount with the new state
			}
		}
	}
} while ( $changed );

// Now compile remaining rules with named subroutines.
$rule_to_idx = array();
$idx_to_rule = array();
foreach ( $compiled as $rid => $_ ) {
	$rule_to_idx[ $rid ] = count( $idx_to_rule );
	$idx_to_rule[]       = $rid;
}

$define = '';
foreach ( $idx_to_rule as $rid ) {
	$body = $compiled[ $rid ];
	// Replace RREF placeholders with named-group references.
	$body    = preg_replace_callback(
		'/RREF(\d+)RREF/',
		function ( $m ) use ( $rule_to_idx ) {
			$rid = (int) $m[1];
			return '(?&r' . $rule_to_idx[ $rid ] . ')';
		},
		$body
	);
	$define .= "(?<r{$rule_to_idx[$rid]}>{$body})";
}

$start_rid = $grammar->get_rule_id( 'query' );
$pattern   = '/(?(DEFINE)' . $define . ')\\A(?&r' . $rule_to_idx[ $start_rid ] . ')\\z/u';
printf(
	"Inlined %d rules. Final rules: %d. Pattern: %s bytes\n",
	$inlined_count,
	count( $idx_to_rule ),
	number_format( strlen( $pattern ) )
);

ini_set( 'pcre.backtrack_limit', '1000000000' );
ini_set( 'pcre.recursion_limit', '10000000' );
ini_set( 'pcre.jit', '1' );

$t  = microtime( true );
$ok = @preg_match( $pattern, "\xff", $m );
printf(
	"Compile: %.2fms, ok=%s, err=%s\n",
	( microtime( true ) - $t ) * 1000,
	var_export( $ok, true ),
	preg_last_error_msg()
);
if ( false === $ok && PREG_BAD_UTF8_ERROR !== preg_last_error() ) {
	echo "Pattern doesn't compile cleanly. Bailing.\n";
	exit( 1 );
}

$handle  = fopen( __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv', 'r' );
$queries = array();
$header  = true;
while ( ( $r = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	if ( $header ) {
		$header = false;
		continue; }
	if ( null !== $r[0] ) {
		$queries[] = $r[0];
	}
}
$queries = array_slice( $queries, 0, (int) ( $argv[1] ?? 5000 ) );

$encoded = array();
foreach ( $queries as $q ) {
	$tokens = ( new WP_MySQL_Lexer( $q ) )->remaining_tokens();
	$s      = '';
	foreach ( $tokens as $t ) {
		$s .= token_char( $t->id );
	}
	$encoded[] = $s;
}

$t               = microtime( true );
$matched         = 0;
$failed          = 0;
$errors          = 0;
$failed_examples = array();
$slow            = array();
foreach ( $encoded as $i => $s ) {
	$qstart = microtime( true );
	$r      = @preg_match( $pattern, $s );
	$qd     = microtime( true ) - $qstart;
	if ( 1 === $r ) {
		++$matched;
	} elseif ( 0 === $r ) {
		++$failed;
		if ( count( $failed_examples ) < 10 ) {
			$failed_examples[] = substr( str_replace( "\n", ' ', $queries[ $i ] ), 0, 120 );
		}
	} else {
		++$errors; }
	if ( $qd > 0.005 && count( $slow ) < 3 ) {
		$slow[] = sprintf( '%6.0fms: %s', $qd * 1000, substr( str_replace( "\n", ' ', $queries[ $i ] ), 0, 100 ) );
	}
}
$d = microtime( true ) - $t;
printf(
	"Matched=%d, Failed=%d, Errors=%d, time=%.4fs (%d QPS)\n",
	$matched,
	$failed,
	$errors,
	$d,
	count( $encoded ) / $d
);
echo "\nFailed queries:\n";
foreach ( $failed_examples as $e ) {
	echo "  $e\n";
}
echo "\nSlow queries:\n";
foreach ( $slow as $e ) {
	echo "  $e\n";
}
