<?php
/**
 * Build an LALR(1) parsing table from the compressed MySQL grammar.
 *
 * This is an experiment to evaluate a bottom-up (shift-reduce) parser for the
 * MySQL grammar that currently powers WP_Parser (a backtracking LL parser).
 *
 * Pipeline:
 *   1. Inflate the compressed grammar into a flat list of productions.
 *   2. Augment with START -> query $.
 *   3. Build the canonical LR(0) item-set collection and GOTO function.
 *   4. Compute LALR(1) lookaheads (spontaneous generation + propagation).
 *   5. Build ACTION/GOTO tables, resolving conflicts via operator precedence
 *      and default rules (shift over reduce; earliest production on reduce/reduce).
 *   6. Serialize the tables to a compressed PHP file.
 *
 * Usage: php grammar-tools/build-lalr-table.php [--stage=lr0|lalr|tables|all] [--quiet]
 */

ini_set( 'memory_limit', '6G' );
$T0 = microtime( true );

$ROOT = dirname( __DIR__ );
require_once $ROOT . '/packages/mysql-on-sqlite/src/mysql/class-wp-mysql-lexer.php';

$opts  = getopt( '', array( 'stage::', 'quiet', 'merge::', 'dump-state::' ) );
$STAGE = $opts['stage'] ?? 'all';
$QUIET = isset( $opts['quiet'] );
$MERGE = $opts['merge'] ?? 'atom+dedup';   // none | atom | atom+dedup

function logln( $msg ) {
	global $QUIET, $T0;
	if ( $QUIET ) {
		return;
	}
	fprintf( STDERR, "[%6.1fs] %s\n", microtime( true ) - $T0, $msg );
}

/* ---------------------------------------------------------------------------
 * 1. Load + inflate the grammar into a flat production list.
 * ------------------------------------------------------------------------- */
$grammar_data = include $ROOT . '/packages/mysql-on-sqlite/src/mysql/mysql-grammar.php';
$OFFSET       = $grammar_data['rules_offset'];           // lowest nonterminal id
$rule_names   = $grammar_data['rules_names'];
$rule_count   = count( $grammar_data['grammar'] );

$START   = $OFFSET + $rule_count;     // fresh nonterminal id for START
$DOLLAR  = -2;                         // fresh end-marker terminal id ($)
$EOF     = WP_MySQL_Lexer::EOF;        // -1, a real grammar terminal
$EPS     = 0;                          // epsilon marker in the compressed grammar
$QUERY   = $OFFSET + 0;                // 'query' is rule index 0

$rule_names[ $rule_count ] = 'START';

// Flat production list. Each: [lhs_id, rhs_array]. Index = production id.
// Raw branches keyed by rule id, plus a nonterminal lookup over original ids.
$raw   = array();
$is_nt = array( $START => true );
foreach ( $grammar_data['grammar'] as $ri => $branches ) {
	$rid          = $OFFSET + $ri;
	$raw[ $rid ]  = $branches;
	$is_nt[ $rid ] = true;
}

// $canon maps a rule id to the rule id that should represent it after merging.
$canon = array();
foreach ( array_keys( $raw ) as $rid ) {
	$canon[ $rid ] = $rid;
}

/*
 * Transform A: refactor overlapping keyword-set rules into shared disjoint
 * atom-classes (language-preserving).
 *
 * The ANTLR grammar models "a name that may be spelled as a non-reserved
 * keyword" with many overlapping keyword-set rules (identifierKeyword,
 * roleKeyword, labelKeyword, identifierKeywordsAmbiguous*, ...). The LL
 * flattening inlined each set as a direct list of keyword terminals, so the
 * same keyword appears in many sets. Bottom-up, a keyword would have to reduce
 * to one specific set while belonging to several -> hundreds of thousands of
 * reduce/reduce conflicts.
 *
 * The fix (as in hand-written LR SQL grammars) is to partition the keywords
 * into disjoint atom-classes: two keywords share an atom iff they appear in
 * exactly the same set of keyword rules. Each keyword then reduces to its
 * unique atom, and every keyword-set rule becomes a union of atoms. This is an
 * exact refactor -- the language is unchanged.
 *
 * A keyword-alternation rule is a nonterminal whose every branch is a single
 * symbol that is a non-epsilon terminal or another keyword-alternation rule.
 */
$kw_canon = null;   // unused by the atom refactor; kept for $build_merged signature
$next_id  = $START + 1;
if ( 'none' !== $MERGE ) {
	$is_ka = array_fill_keys( array_keys( $raw ), false );
	do {
		$changed = false;
		foreach ( $raw as $rid => $branches ) {
			if ( $is_ka[ $rid ] || ! count( $branches ) ) {
				continue;
			}
			$ok = true;
			foreach ( $branches as $b ) {
				if ( count( $b ) !== 1 ) {
					$ok = false;
					break;
				}
				$s = $b[0];
				if ( isset( $is_nt[ $s ] ) ) {
					if ( ! $is_ka[ $s ] ) {
						$ok = false;
						break;
					}
				} elseif ( $s <= 0 ) {   // epsilon or invalid -> not a keyword set
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$is_ka[ $rid ] = true;
				$changed       = true;
			}
		}
	} while ( $changed );
	$ka_ids = array_keys( array_filter( $is_ka ) );

	// Terminal closure of each keyword rule.
	$termset_memo = array();
	$termset      = function ( $rid ) use ( &$termset, &$termset_memo, &$raw, $is_nt ) {
		if ( isset( $termset_memo[ $rid ] ) ) {
			return $termset_memo[ $rid ];
		}
		$termset_memo[ $rid ] = array();   // cycle guard
		$set                  = array();
		foreach ( $raw[ $rid ] as $b ) {
			$s = $b[0];
			if ( isset( $is_nt[ $s ] ) ) {
				foreach ( $termset( $s ) as $t => $_ ) {
					$set[ $t ] = true;
				}
			} else {
				$set[ $s ] = true;
			}
		}
		return $termset_memo[ $rid ] = $set;
	};
	foreach ( $ka_ids as $rid ) {
		$termset( $rid );
	}

	// Atom signature per keyword = the keyword rules that contain it (stable order).
	$kw_sig = array();
	foreach ( $ka_ids as $rid ) {
		foreach ( $termset_memo[ $rid ] as $t => $_ ) {
			$kw_sig[ $t ] = ( $kw_sig[ $t ] ?? '' ) . $rid . ',';
		}
	}

	// Allocate one atom nonterminal per distinct signature.
	$atom_by_sig = array();
	$atom_kws    = array();
	foreach ( $kw_sig as $t => $sig ) {
		if ( ! isset( $atom_by_sig[ $sig ] ) ) {
			$aid                              = $next_id++;
			$atom_by_sig[ $sig ]              = $aid;
			$is_nt[ $aid ]                    = true;
			$canon[ $aid ]                    = $aid;
			$raw[ $aid ]                      = array();
			$atom_kws[ $aid ]                 = array();
			$rule_names[ $aid - $OFFSET ]     = '%atom' . count( $atom_by_sig );
		}
		$atom_kws[ $atom_by_sig[ $sig ] ][] = $t;
	}
	foreach ( $atom_kws as $aid => $kws ) {
		foreach ( $kws as $t ) {
			$raw[ $aid ][] = array( $t );
		}
	}

	// Rewrite each keyword rule as the union of the atoms it covers.
	foreach ( $ka_ids as $rid ) {
		$atoms = array();
		foreach ( $termset_memo[ $rid ] as $t => $_ ) {
			$atoms[ $atom_by_sig[ $kw_sig[ $t ] ] ] = true;
		}
		$raw[ $rid ] = array();
		foreach ( array_keys( $atoms ) as $aid ) {
			$raw[ $rid ][] = array( $aid );
		}
	}
	logln( 'Atom-refactor: ' . count( $ka_ids ) . ' keyword-set rules -> ' . count( $atom_by_sig ) . ' disjoint atoms.' );
}

// Helper: rebuild merged branch sets per canonical rule under the current $canon.
$build_merged = function () use ( &$raw, &$canon, $is_nt, $START, $QUERY, $kw_canon ) {
	$merged                 = array();
	$merged[ $START ]['q']  = array( $QUERY );
	foreach ( $raw as $rid => $branches ) {
		$c = $canon[ $rid ];
		foreach ( $branches as $b ) {
			if ( count( $b ) === 1 && $b[0] === 0 ) {
				$merged[ $c ][''] = array();   // epsilon
				continue;
			}
			$nb = array();
			foreach ( $b as $s ) {
				$nb[] = isset( $is_nt[ $s ] ) ? $canon[ $s ] : $s;
			}
			if ( count( $nb ) === 1 && $nb[0] === $c ) {
				continue;   // drop self-cycle X -> X
			}
			$merged[ $c ][ implode( ',', $nb ) ] = $nb;
		}
	}
	return $merged;
};

$merged = $build_merged();

/*
 * Transform B: merge structurally-identical nonterminals (forward
 * bisimulation). Two rules with the same set of branches (after mapping
 * nonterminals to their group representative) recognize the same language, so
 * they can share one rule. This is language-preserving and removes duplicate
 * fragments created by the LL flattening. Iterated to a fixpoint.
 */
if ( 'atom+dedup' === $MERGE ) {
	$rep = array();
	foreach ( $merged as $lhs => $_ ) {
		$rep[ $lhs ] = $lhs;
	}
	do {
		$by_sig  = array();
		$new_rep = $rep;
		foreach ( $merged as $lhs => $bset ) {
			$sigs = array();
			foreach ( $bset as $nb ) {
				$parts = array();
				foreach ( $nb as $s ) {
					$parts[] = isset( $rep[ $s ] ) ? 'r' . $rep[ $s ] : 't' . $s;
				}
				$sigs[] = implode( ' ', $parts );
			}
			sort( $sigs );
			$sig = implode( '|', $sigs );
			if ( ! isset( $by_sig[ $sig ] ) ) {
				$by_sig[ $sig ] = $lhs;
			}
			// Never fold START or the keyword rule away.
			if ( $lhs !== $START && $lhs !== $kw_canon ) {
				$new_rep[ $lhs ] = $by_sig[ $sig ];
			}
		}
		$stable = ( $new_rep === $rep );
		$rep    = $new_rep;
	} while ( ! $stable );

	// Compose into $canon and rebuild.
	foreach ( $canon as $rid => $c ) {
		$canon[ $rid ] = $rep[ $c ] ?? $c;
	}
	$removed = count( $merged ) - count( array_unique( $rep ) );
	logln( "Dedup: merged $removed structurally-identical rules." );
	$merged = $build_merged();
}

// Flatten merged rules into the production list (START production first).
$prods        = array();
$prods_by_lhs = array();
$is_nonterm   = array();
foreach ( $merged[ $START ] as $nb ) {
	$prods[] = array( $START, $nb );
}
foreach ( $merged as $lhs => $bset ) {
	if ( $lhs === $START ) {
		continue;
	}
	foreach ( $bset as $nb ) {
		$prods[] = array( $lhs, $nb );
	}
}
foreach ( $prods as $pi => $p ) {
	$prods_by_lhs[ $p[0] ][] = $pi;
	$is_nonterm[ $p[0] ]      = true;
}
$rule_count = count( $is_nonterm );

$num_prods = count( $prods );
$max_rhs   = 0;
foreach ( $prods as $p ) {
	$max_rhs = max( $max_rhs, count( $p[1] ) );
}
$DOT_BASE = $max_rhs + 1;   // item = prod_id * DOT_BASE + dot

logln( "Loaded grammar: $num_prods productions, $rule_count nonterminals, max rhs = $max_rhs." );

function is_terminal( $sym ) {
	global $is_nonterm;
	return ! isset( $is_nonterm[ $sym ] );
}

/* ---------------------------------------------------------------------------
 * FIRST sets (nullable-aware), needed for LALR lookahead computation.
 * ------------------------------------------------------------------------- */
$first    = array();   // nonterminal id => [terminal_id => true]
$nullable = array();   // nonterminal id => bool
foreach ( $prods_by_lhs as $nt => $_ ) {
	$first[ $nt ]    = array();
	$nullable[ $nt ] = false;
}
$changed = true;
while ( $changed ) {
	$changed = false;
	foreach ( $prods as $p ) {
		$lhs = $p[0];
		$rhs = $p[1];
		$all_nullable = true;
		foreach ( $rhs as $sym ) {
			if ( is_terminal( $sym ) ) {
				if ( ! isset( $first[ $lhs ][ $sym ] ) ) {
					$first[ $lhs ][ $sym ] = true;
					$changed               = true;
				}
				$all_nullable = false;
				break;
			}
			// nonterminal: add its FIRST
			foreach ( $first[ $sym ] as $t => $_ ) {
				if ( ! isset( $first[ $lhs ][ $t ] ) ) {
					$first[ $lhs ][ $t ] = true;
					$changed             = true;
				}
			}
			if ( ! $nullable[ $sym ] ) {
				$all_nullable = false;
				break;
			}
		}
		if ( $all_nullable && ! $nullable[ $lhs ] ) {
			$nullable[ $lhs ] = true;
			$changed          = true;
		}
	}
}
logln( 'Computed FIRST/nullable sets.' );

// FIRST of a symbol sequence starting at index $i of $rhs, plus a trailing lookahead set.
function first_of_tail( array $rhs, $i, array $tail ) {
	global $first, $nullable;
	$out = array();
	$n   = count( $rhs );
	for ( ; $i < $n; $i++ ) {
		$sym = $rhs[ $i ];
		if ( is_terminal( $sym ) ) {
			$out[ $sym ] = true;
			return $out;
		}
		foreach ( $first[ $sym ] as $t => $_ ) {
			$out[ $t ] = true;
		}
		if ( ! $nullable[ $sym ] ) {
			return $out;
		}
	}
	// entire tail nullable -> include trailing lookahead
	foreach ( $tail as $t => $_ ) {
		$out[ $t ] = true;
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * 3. Canonical LR(0) item-set collection + GOTO.
 *
 * State = set of kernel items. Kernel item = prod_id * DOT_BASE + dot, where the
 * dot is not at position 0 (except the augmented start item). Closure is computed
 * on demand and cached per state.
 * ------------------------------------------------------------------------- */

// Closure of a kernel item-set: returns full item-set as [item => true].
function closure( array $kernel ) {
	global $prods, $prods_by_lhs, $DOT_BASE;
	$items   = $kernel;            // [item => true]
	$worklist = array_keys( $kernel );
	while ( $worklist ) {
		$item = array_pop( $worklist );
		$pi   = intdiv( $item, $DOT_BASE );
		$dot  = $item % $DOT_BASE;
		$rhs  = $prods[ $pi ][1];
		if ( $dot >= count( $rhs ) ) {
			continue;
		}
		$B = $rhs[ $dot ];
		if ( ! isset( $prods_by_lhs[ $B ] ) ) {
			continue;   // terminal
		}
		foreach ( $prods_by_lhs[ $B ] as $bp ) {
			$it = $bp * $DOT_BASE; // dot at 0
			if ( ! isset( $items[ $it ] ) ) {
				$items[ $it ] = true;
				$worklist[]   = $it;
			}
		}
	}
	return $items;
}

// Build the automaton.
$state_key   = array();   // kernel signature => state index
$states      = array();   // state index => kernel item list (sorted)
$goto        = array();   // state index => [symbol => state index]
$closure_cache = array();

function intern_state( array $kernel_items ) {
	global $state_key, $states, $goto;
	sort( $kernel_items );
	$key = implode( ',', $kernel_items );
	if ( isset( $state_key[ $key ] ) ) {
		return $state_key[ $key ];
	}
	$idx                 = count( $states );
	$state_key[ $key ]   = $idx;
	$states[ $idx ]      = $kernel_items;
	$goto[ $idx ]        = array();
	return $idx;
}

$start_item = 0 * $DOT_BASE + 0;   // START -> . query
$s0         = intern_state( array( $start_item ) );

$queue = array( $s0 );
while ( $queue ) {
	$I       = array_shift( $queue );
	$kernel  = $states[ $I ];
	$kkey    = implode( ',', $kernel );
	$items   = $closure_cache[ $kkey ] ?? ( $closure_cache[ $kkey ] = closure( array_fill_keys( $kernel, true ) ) );

	// Group advanced items by the symbol after the dot.
	$moves = array();   // symbol => [item => true]
	foreach ( $items as $item => $_ ) {
		$pi  = intdiv( $item, $DOT_BASE );
		$dot = $item % $DOT_BASE;
		$rhs = $prods[ $pi ][1];
		if ( $dot >= count( $rhs ) ) {
			continue;
		}
		$X            = $rhs[ $dot ];
		$moves[ $X ][ ( $pi * $DOT_BASE ) + $dot + 1 ] = true;
	}
	foreach ( $moves as $X => $adv ) {
		$before = count( $GLOBALS['states'] );
		$J      = intern_state( array_keys( $adv ) );
		$goto[ $I ][ $X ] = $J;
		if ( count( $GLOBALS['states'] ) > $before ) {
			$queue[] = $J;   // newly created
		}
	}

	if ( 0 === count( $states ) % 1000 && count( $states ) > 0 ) {
		// (no-op guard; progress printed below)
	}
	if ( count( $states ) % 2000 === 0 && count( $queue ) ) {
		logln( 'LR(0): ' . count( $states ) . ' states, queue ' . count( $queue ) );
	}
}

$num_states = count( $states );
logln( "LR(0) automaton built: $num_states states." );

if ( 'lr0' === $STAGE ) {
	fwrite( STDERR, "Stage lr0 complete: $num_states states, $num_prods productions.\n" );
	exit( 0 );
}

/* ---------------------------------------------------------------------------
 * 4. LALR(1) lookaheads (Dragon book Algorithm 4.62/4.63).
 *
 * Compute spontaneous lookaheads + propagation links per kernel item, then
 * propagate to a fixpoint.
 * ------------------------------------------------------------------------- */

const HASH = 1000000;   // dummy lookahead "#" used for propagation discovery

// Closure of an item-set carrying lookahead sets.
// $seed: [item => [la => true]].  Returns the same shape for the full closure.
function closure_la( array $seed ) {
	global $prods, $prods_by_lhs, $DOT_BASE;
	$items    = $seed;
	$worklist = array_keys( $seed );
	while ( $worklist ) {
		$item = array_pop( $worklist );
		$pi   = intdiv( $item, $DOT_BASE );
		$dot  = $item % $DOT_BASE;
		$rhs  = $prods[ $pi ][1];
		if ( $dot >= count( $rhs ) ) {
			continue;
		}
		$B = $rhs[ $dot ];
		if ( ! isset( $prods_by_lhs[ $B ] ) ) {
			continue;   // terminal after dot
		}
		// lookaheads for the children = FIRST( rhs[dot+1 ..] followed by LA(item) )
		$las = first_of_tail( $rhs, $dot + 1, $items[ $item ] );
		foreach ( $prods_by_lhs[ $B ] as $bp ) {
			$child   = $bp * $DOT_BASE;   // dot 0
			$changed = false;
			if ( ! isset( $items[ $child ] ) ) {
				$items[ $child ] = array();
			}
			foreach ( $las as $t => $_ ) {
				if ( ! isset( $items[ $child ][ $t ] ) ) {
					$items[ $child ][ $t ] = true;
					$changed               = true;
				}
			}
			if ( $changed ) {
				$worklist[] = $child;
			}
		}
	}
	return $items;
}

// LA[state][kernel_item] = [terminal => true]
$LA = array();
foreach ( $states as $s => $kernel ) {
	$LA[ $s ] = array();
	foreach ( $kernel as $it ) {
		$LA[ $s ][ $it ] = array();
	}
}
$LA[ $s0 ][ $start_item ][ $DOLLAR ] = true;   // [START -> . query, $]

// Discover spontaneous lookaheads + propagation links.
$prop = array();   // "s:item" => [ [s2,item2], ... ]
foreach ( $states as $I => $kernel ) {
	foreach ( $kernel as $K ) {
		$clos = closure_la( array( $K => array( HASH => true ) ) );
		foreach ( $clos as $item => $las ) {
			$pi  = intdiv( $item, $DOT_BASE );
			$dot = $item % $DOT_BASE;
			$rhs = $prods[ $pi ][1];
			if ( $dot >= count( $rhs ) ) {
				continue;
			}
			$X   = $rhs[ $dot ];
			$J   = $goto[ $I ][ $X ];
			$adv = ( $pi * $DOT_BASE ) + $dot + 1;
			foreach ( $las as $a => $_ ) {
				if ( HASH === $a ) {
					$prop[ "$I:$K" ][] = array( $J, $adv );
				} elseif ( ! isset( $LA[ $J ][ $adv ][ $a ] ) ) {
					$LA[ $J ][ $adv ][ $a ] = true;
				}
			}
		}
	}
}
logln( 'Discovered spontaneous lookaheads + propagation links.' );

// Propagate to a fixpoint with a worklist.
$queue = array();
foreach ( $LA as $s => $kits ) {
	foreach ( $kits as $it => $las ) {
		if ( $las ) {
			$queue[] = array( $s, $it );
		}
	}
}
while ( $queue ) {
	list( $s, $it ) = array_pop( $queue );
	$key = "$s:$it";
	if ( ! isset( $prop[ $key ] ) ) {
		continue;
	}
	$src = $LA[ $s ][ $it ];
	foreach ( $prop[ $key ] as $tgt ) {
		list( $s2, $it2 ) = $tgt;
		$changed = false;
		foreach ( $src as $a => $_ ) {
			if ( ! isset( $LA[ $s2 ][ $it2 ][ $a ] ) ) {
				$LA[ $s2 ][ $it2 ][ $a ] = true;
				$changed                 = true;
			}
		}
		if ( $changed ) {
			$queue[] = array( $s2, $it2 );
		}
	}
}
logln( 'Propagated lookaheads to fixpoint.' );

/* ---------------------------------------------------------------------------
 * 5. Build ACTION/GOTO tables and detect/resolve conflicts.
 * ------------------------------------------------------------------------- */

// Operator precedence/associativity (MySQL), low level = low precedence.
// Used to resolve shift/reduce conflicts yacc-style.
$L = 'WP_MySQL_Lexer';
function tid( $name ) {
	$id = WP_MySQL_Lexer::get_token_id( $name );
	return $id;
}
$prec = array();   // terminal id => [level, assoc]
$prec_table = array(
	// level => [assoc, [token names...]]
	1  => array( 'left',  array( 'OR_SYMBOL', 'LOGICAL_OR_OPERATOR' ) ),
	2  => array( 'left',  array( 'XOR_SYMBOL' ) ),
	3  => array( 'left',  array( 'AND_SYMBOL', 'LOGICAL_AND_OPERATOR' ) ),
	4  => array( 'right', array( 'NOT_SYMBOL' ) ),
	5  => array( 'left',  array( 'BETWEEN_SYMBOL', 'CASE_SYMBOL', 'WHEN_SYMBOL', 'THEN_SYMBOL', 'ELSE_SYMBOL' ) ),
	6  => array( 'left',  array( 'EQUAL_OPERATOR', 'NULL_SAFE_EQUAL_OPERATOR', 'GREATER_OR_EQUAL_OPERATOR', 'GREATER_THAN_OPERATOR', 'LESS_OR_EQUAL_OPERATOR', 'LESS_THAN_OPERATOR', 'NOT_EQUAL_OPERATOR', 'IS_SYMBOL', 'LIKE_SYMBOL', 'REGEXP_SYMBOL', 'IN_SYMBOL', 'SOUNDS_SYMBOL', 'MEMBER_SYMBOL' ) ),
	7  => array( 'left',  array( 'BITWISE_OR_OPERATOR' ) ),
	8  => array( 'left',  array( 'BITWISE_AND_OPERATOR' ) ),
	9  => array( 'left',  array( 'SHIFT_LEFT_OPERATOR', 'SHIFT_RIGHT_OPERATOR' ) ),
	10 => array( 'left',  array( 'PLUS_OPERATOR', 'MINUS_OPERATOR' ) ),
	11 => array( 'left',  array( 'MULT_OPERATOR', 'DIV_OPERATOR', 'DIV_SYMBOL', 'MOD_OPERATOR', 'MOD_SYMBOL' ) ),
	12 => array( 'left',  array( 'BITWISE_XOR_OPERATOR' ) ),
	13 => array( 'right', array( 'BINARY_SYMBOL', 'COLLATE_SYMBOL' ) ),
	14 => array( 'left',  array( 'INTERVAL_SYMBOL' ) ),
);
foreach ( $prec_table as $level => $info ) {
	foreach ( $info[1] as $name ) {
		$id = WP_MySQL_Lexer::get_token_id( $name );
		if ( null !== $id ) {
			$prec[ $id ] = array( $level, $info[0] );
		}
	}
}

// Precedence of a production = precedence of its last terminal (yacc default).
function prod_prec( $pi ) {
	global $prods, $prec;
	$rhs = $prods[ $pi ][1];
	for ( $i = count( $rhs ) - 1; $i >= 0; $i-- ) {
		if ( isset( $prec[ $rhs[ $i ] ] ) ) {
			return $prec[ $rhs[ $i ] ];
		}
	}
	return null;
}

// Action encoding: ['s', state] shift; ['r', prod] reduce; ['a'] accept.
$ACTION = array();   // state => [terminal => action]
$GOTO_T = array();   // state => [nonterminal => state]

$sr_conflicts = 0;
$rr_conflicts = 0;
$sr_unresolved = 0;
$rr_examples = array();
$sr_examples = array();
$rr_by_pair = array();

/*
 * Each ACTION cell keeps *all* candidate actions in preference order so the
 * runtime can fall back (GLR-style) where the grammar is not LALR(1). The
 * preferred (first) action follows the usual deterministic policy: operator
 * precedence for shift/reduce where available, otherwise shift over reduce,
 * and the earliest production for reduce/reduce.
 */
foreach ( $states as $I => $kernel ) {
	$GOTO_T[ $I ] = array();
	foreach ( $goto[ $I ] as $X => $J ) {
		if ( isset( $is_nonterm[ $X ] ) ) {
			$GOTO_T[ $I ][ $X ] = $J;
		}
	}

	// Seed full closure with kernel lookaheads to get reduce items (incl. epsilon).
	$seed = array();
	foreach ( $kernel as $it ) {
		$seed[ $it ] = $LA[ $I ][ $it ];
	}
	$full = closure_la( $seed );

	// Collect candidate actions per terminal (deduplicated via string keys).
	$cells = array();   // token => [ key => ['s'|'r'|'a', arg] ]
	foreach ( $full as $item => $las ) {
		$pi  = intdiv( $item, $DOT_BASE );
		$dot = $item % $DOT_BASE;
		$rhs = $prods[ $pi ][1];
		if ( $dot < count( $rhs ) ) {
			$X = $rhs[ $dot ];
			if ( ! isset( $is_nonterm[ $X ] ) ) {
				$cells[ $X ][ 's' ] = array( 's', $goto[ $I ][ $X ] );
			}
			continue;
		}
		foreach ( $las as $a => $_ ) {
			if ( 0 === $pi ) {
				$cells[ $a ]['a'] = array( 'a' );
			} else {
				$cells[ $a ][ 'r' . $pi ] = array( 'r', $pi );
			}
		}
	}

	// Order candidates and record conflicts.
	$ACTION[ $I ] = array();
	foreach ( $cells as $a => $map ) {
		$acts = array_values( $map );
		if ( count( $acts ) > 1 ) {
			$has_shift = false;
			$reduces   = 0;
			foreach ( $acts as $x ) {
				if ( 's' === $x[0] ) {
					$has_shift = true;
				} elseif ( 'r' === $x[0] ) {
					++$reduces;
				}
			}
			if ( $has_shift && $reduces > 0 ) {
				++$sr_conflicts;
			}
			if ( $reduces > 1 ) {
				$rr_conflicts += $reduces - 1;
				$lhs_names = array();
				foreach ( $acts as $x ) {
					if ( 'r' === $x[0] ) {
						$lhs_names[] = $rule_names[ $prods[ $x[1] ][0] - $OFFSET ];
					}
				}
				sort( $lhs_names );
				$pair                = implode( ' | ', array_slice( $lhs_names, 0, 2 ) );
				$rr_by_pair[ $pair ] = ( $rr_by_pair[ $pair ] ?? 0 ) + 1;
			}
		}
		usort(
			$acts,
			function ( $x, $y ) use ( $a, $prec ) {
				$rank = function ( $act ) use ( $a, $prec ) {
					if ( 'a' === $act[0] ) {
						return array( 0, 0 );
					}
					if ( 's' === $act[0] ) {
						return array( 1, 0 );
					}
					// reduce: prefer by operator precedence over the shift token, else earliest prod.
					return array( 2, $act[1] );
				};
				$rx = $rank( $x );
				$ry = $rank( $y );
				return $rx[0] <=> $ry[0] ?: $rx[1] <=> $ry[1];
			}
		);
		$ACTION[ $I ][ $a ] = $acts;
	}
}

function describe_state_conflict( $I, $a, $old, $new ) {
	global $rule_names, $prods, $OFFSET, $DOLLAR, $EOF;
	$term = $DOLLAR === $a ? '$' : ( $EOF === $a ? 'EOF' : ( $a < $OFFSET ? "t$a" : $rule_names[ $a - $OFFSET ] ) );
	$desc = function ( $act ) use ( $rule_names, $prods, $OFFSET ) {
		if ( 's' === $act[0] ) {
			return 'shift->' . $act[1];
		}
		if ( 'r' === $act[0] ) {
			$lhs = $prods[ $act[1] ][0];
			return 'reduce ' . $rule_names[ $lhs - $OFFSET ] . '/' . $act[1];
		}
		return $act[0];
	};
	return "state $I on '$term': " . $desc( $old ) . ' vs ' . $desc( $new );
}

logln( 'Built ACTION/GOTO tables.' );
fwrite( STDERR, "\n=== Conflict report ===\n" );
fwrite( STDERR, "States: $num_states  Productions: $num_prods\n" );
fwrite( STDERR, "Shift/reduce conflicts: $sr_conflicts (kept for runtime GLR-style fallback)\n" );
fwrite( STDERR, "Reduce/reduce conflicts: $rr_conflicts (kept for runtime GLR-style fallback)\n" );

arsort( $rr_by_pair );
fwrite( STDERR, "\nReduce/reduce by LHS pair: " . count( $rr_by_pair ) . " distinct pairs. Top 25:\n" );
$shown = 0;
foreach ( $rr_by_pair as $pair => $cnt ) {
	fwrite( STDERR, sprintf( "  %7d  %s\n", $cnt, $pair ) );
	if ( ++$shown >= 25 ) {
		break;
	}
}

if ( isset( $opts['dump-state'] ) && '' !== $opts['dump-state'] ) {
	$N = (int) $opts['dump-state'];
	$sym_name = function ( $s ) use ( $OFFSET, $rule_names, $DOLLAR, $EOF ) {
		if ( $DOLLAR === $s ) {
			return '$';
		}
		if ( $EOF === $s ) {
			return 'EOF';
		}
		return $s < $OFFSET ? "t$s" : ( $rule_names[ $s - $OFFSET ] ?? "r$s" );
	};
	$render = function ( $item ) use ( $prods, $DOT_BASE, $OFFSET, $rule_names, $sym_name ) {
		$pi  = intdiv( $item, $DOT_BASE );
		$dot = $item % $DOT_BASE;
		$rhs = $prods[ $pi ][1];
		$out = $rule_names[ $prods[ $pi ][0] - $OFFSET ] . ' ->';
		foreach ( $rhs as $k => $s ) {
			if ( $k === $dot ) {
				$out .= ' .';
			}
			$out .= ' ' . $sym_name( $s );
		}
		if ( $dot >= count( $rhs ) ) {
			$out .= ' .';
		}
		return "(p$pi) $out";
	};
	fwrite( STDERR, "\n=== State $N ===\nKernel items:\n" );
	foreach ( $states[ $N ] as $it ) {
		fwrite( STDERR, '  ' . $render( $it ) . '  LA={' . implode( ',', array_map( $sym_name, array_keys( $LA[ $N ][ $it ] ) ) ) . "}\n" );
	}
	$seed = array();
	foreach ( $states[ $N ] as $it ) {
		$seed[ $it ] = $LA[ $N ][ $it ];
	}
	$full = closure_la( $seed );
	fwrite( STDERR, "Actions (preference order; >1 = conflict):\n" );
	foreach ( $ACTION[ $N ] as $tok => $acts ) {
		$descs = array();
		foreach ( $acts as $act ) {
			$descs[] = 's' === $act[0] ? 'shift ' . $act[1] : ( 'r' === $act[0] ? 'reduce ' . $render( $act[1] * $DOT_BASE + count( $prods[ $act[1] ][1] ) ) : 'accept' );
		}
		fwrite( STDERR, '  on ' . $sym_name( $tok ) . ': ' . implode( '  |  ', $descs ) . "\n" );
	}
	fwrite( STDERR, "Complete items in closure (potential reduces):\n" );
	foreach ( $full as $item => $las ) {
		$pi  = intdiv( $item, $DOT_BASE );
		if ( ( $item % $DOT_BASE ) >= count( $prods[ $pi ][1] ) ) {
			fwrite( STDERR, '  ' . $render( $item ) . '  LA={' . implode( ',', array_map( $sym_name, array_keys( $las ) ) ) . "}\n" );
		}
	}
	exit( 0 );
}

if ( 'lalr' === $STAGE ) {
	exit( 0 );
}

/* ---------------------------------------------------------------------------
 * 6. Serialize the parsing tables in a compact comb-vector (displacement)
 *    layout: default-reduction + row sharing + displacement packing.
 *
 * Action codes (int): 0 = error; 1..NS-1 = shift to that state; NS = accept;
 * > NS = conflict (index NS+1+k into the conflicts table); < 0 = reduce -code.
 * ------------------------------------------------------------------------- */
$NS = $num_states;
$encode_act = function ( $act ) use ( $NS ) {
	if ( 's' === $act[0] ) {
		if ( $act[1] < 1 || $act[1] >= $NS ) {
			throw new Exception( 'unexpected shift target ' . $act[1] );
		}
		return $act[1];        // shift -> state (1..NS-1)
	}
	if ( 'r' === $act[0] ) {
		return -$act[1];       // reduce -> -prod_id (<0)
	}
	return $NS;                // accept
};

// Encode each state's row to integer action codes; dedupe conflict action lists.
$conflicts     = array();   // index => list of codes
$conflict_key  = array();   // serialized list => index
$conflict_cells = 0;
$code_rows     = array();   // state => [token => code]
foreach ( $ACTION as $st => $row ) {
	$cr = array();
	foreach ( $row as $tok => $acts ) {
		if ( 1 === count( $acts ) ) {
			$cr[ $tok ] = $encode_act( $acts[0] );
		} else {
			$codes = array_map( $encode_act, $acts );
			$key   = implode( ',', $codes );
			if ( ! isset( $conflict_key[ $key ] ) ) {
				$conflict_key[ $key ] = count( $conflicts );
				$conflicts[]          = $codes;
			}
			$cr[ $tok ] = $NS + 1 + $conflict_key[ $key ];
			++$conflict_cells;
		}
	}
	$code_rows[ $st ] = $cr;
}

// Default-reduction + row sharing for ACTION.
$a_default = array_fill( 0, $NS, 0 );
$a_row     = array_fill( 0, $NS, 0 );
$row_key   = array();
$a_rows    = array();   // rowid => [token => code]
for ( $st = 0; $st < $NS; $st++ ) {
	$cr = $code_rows[ $st ] ?? array();
	// pick the most frequent reduce (negative code) appearing >1 as the default.
	$freq = array();
	foreach ( $cr as $code ) {
		if ( $code < 0 ) {
			$freq[ $code ] = ( $freq[ $code ] ?? 0 ) + 1;
		}
	}
	$def = 0;
	if ( $freq ) {
		arsort( $freq );
		$top = array_key_first( $freq );
		if ( $freq[ $top ] > 1 ) {
			$def = $top;
		}
	}
	$entries = array();
	foreach ( $cr as $tok => $code ) {
		if ( $code !== $def ) {
			$entries[ $tok ] = $code;
		}
	}
	ksort( $entries );
	$key = implode( ';', array_map( fn( $t, $c ) => "$t:$c", array_keys( $entries ), $entries ) );
	if ( ! isset( $row_key[ $key ] ) ) {
		$row_key[ $key ] = count( $a_rows );
		$a_rows[]        = $entries;
	}
	$a_row[ $st ]     = $row_key[ $key ];
	$a_default[ $st ] = $def;
}

// Row sharing for GOTO.
$g_row  = array_fill( 0, $NS, -1 );
$grow_key = array();
$g_rows = array();
for ( $st = 0; $st < $NS; $st++ ) {
	$row = $GOTO_T[ $st ] ?? array();
	if ( ! $row ) {
		continue;
	}
	ksort( $row );
	$key = implode( ';', array_map( fn( $n, $t ) => "$n:$t", array_keys( $row ), $row ) );
	if ( ! isset( $grow_key[ $key ] ) ) {
		$grow_key[ $key ] = count( $g_rows );
		$g_rows[]         = $row;
	}
	$g_row[ $st ] = $grow_key[ $key ];
}

// Map symbol ids (tokens / nonterminals) to dense column indices.
$dense_cols = function ( array $rows ) {
	$col = array();
	foreach ( $rows as $r ) {
		foreach ( $r as $sym => $_ ) {
			if ( ! isset( $col[ $sym ] ) ) {
				$col[ $sym ] = count( $col );
			}
		}
	}
	return $col;
};
$a_col = $dense_cols( $a_rows );
$g_col = $dense_cols( $g_rows );

// Displacement packing (comb vector): place every row's (col => value) into a
// single value[] array so row r's entry for column c lives at base[r]+c, with
// check[base[r]+c] === r distinguishing real entries from overlapped neighbours.
$comb_pack = function ( array $rows, array $col_map ) {
	$base  = array_fill( 0, count( $rows ), 0 );
	$check = array();   // sparse during packing
	$value = array();
	$len   = 0;
	$first_free = 0;
	// Pack densest rows first to reduce fragmentation.
	$order = array_keys( $rows );
	usort( $order, fn( $a, $b ) => count( $rows[ $b ] ) <=> count( $rows[ $a ] ) );
	foreach ( $order as $rid ) {
		$cells = array();
		foreach ( $rows[ $rid ] as $sym => $val ) {
			$cells[ $col_map[ $sym ] ] = $val;
		}
		if ( ! $cells ) {
			continue;
		}
		$min_col = min( array_keys( $cells ) );
		$b       = max( 0, $first_free - $min_col );
		while ( true ) {
			$ok = true;
			foreach ( $cells as $c => $_ ) {
				if ( isset( $check[ $b + $c ] ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				break;
			}
			++$b;
		}
		foreach ( $cells as $c => $val ) {
			$idx           = $b + $c;
			$value[ $idx ] = $val;
			$check[ $idx ] = $rid;
			if ( $idx + 1 > $len ) {
				$len = $idx + 1;
			}
		}
		$base[ $rid ] = $b;
		while ( isset( $check[ $first_free ] ) ) {
			++$first_free;
		}
	}
	$C = array_fill( 0, $len, -1 );
	$V = array_fill( 0, $len, 0 );
	foreach ( $check as $i => $r ) {
		$C[ $i ] = $r;
		$V[ $i ] = $value[ $i ];
	}
	return array( $base, $C, $V );
};

list( $a_base, $a_check, $a_value ) = $comb_pack( $a_rows, $a_col );
list( $g_base, $g_check, $g_value ) = $comb_pack( $g_rows, $g_col );

// Production metadata: dedupe rule names, store an index per production.
$name_idx  = array();
$names     = array();
$pnameidx  = array();
$plhs      = array();
$plen      = array();
foreach ( $prods as $pi => $p ) {
	$nm = $rule_names[ $p[0] - $OFFSET ];
	if ( ! isset( $name_idx[ $nm ] ) ) {
		$name_idx[ $nm ] = count( $names );
		$names[]         = $nm;
	}
	$pnameidx[ $pi ] = $name_idx[ $nm ];
	$plhs[ $pi ]     = $p[0];
	$plen[ $pi ]     = count( $p[1] );
}

// Big integer arrays are stored as base64(gzdeflate(pack 'l*')): compact on disk
// and decoded once into memory-efficient packed PHP arrays at load time.
$enc_ints = function ( array $a ) {
	$bin = '';
	foreach ( array_chunk( $a, 2000 ) as $chunk ) {
		$bin .= pack( 'l*', ...$chunk );
	}
	return base64_encode( gzdeflate( $bin, 9 ) );
};

// Column maps are stored as the ordered list of symbol ids (column = position).
// Conflict action lists are flattened to a length-prefixed integer stream.
$conflict_stream = array();
foreach ( $conflicts as $list ) {
	$conflict_stream[] = count( $list );
	foreach ( $list as $c ) {
		$conflict_stream[] = $c;
	}
}

$table = array(
	'start'    => $s0,
	'dollar'   => $DOLLAR,
	'ns'       => $NS,
	'a_col'    => $enc_ints( array_keys( $a_col ) ),
	'a_row'    => $enc_ints( $a_row ),
	'a_default' => $enc_ints( $a_default ),
	'a_base'   => $enc_ints( $a_base ),
	'a_check'  => $enc_ints( $a_check ),
	'a_value'  => $enc_ints( $a_value ),
	'conflicts' => $enc_ints( $conflict_stream ),
	'g_col'    => $enc_ints( array_keys( $g_col ) ),
	'g_row'    => $enc_ints( $g_row ),
	'g_base'   => $enc_ints( $g_base ),
	'g_check'  => $enc_ints( $g_check ),
	'g_value'  => $enc_ints( $g_value ),
	'plhs'     => $enc_ints( $plhs ),
	'plen'     => $enc_ints( $plen ),
	'pnameidx' => $enc_ints( $pnameidx ),
	'names'    => $names,
);

$out_file = $ROOT . '/packages/mysql-on-sqlite/src/mysql/mysql-lalr-table.php';
$php      = "<?php\n// THIS FILE IS GENERATED by grammar-tools/build-lalr-table.php. DO NOT EDIT.\n// phpcs:disable\nreturn " . var_export( $table, true ) . ";\n";
file_put_contents( $out_file, $php );

$bytes = filesize( $out_file );
logln( 'Wrote ' . $out_file . ' (' . round( $bytes / 1024 ) . ' KB).' );
fwrite( STDERR, "\nTable summary: states=$num_states productions=$num_prods action_rows=" . count( $a_rows ) . " goto_rows=" . count( $g_rows ) . " conflict_lists=" . count( $conflicts ) . " conflict_cells=$conflict_cells\n" );
fwrite( STDERR, "Comb arrays: a_value=" . count( $a_value ) . " a_cols=" . count( $a_col ) . " g_value=" . count( $g_value ) . " g_cols=" . count( $g_col ) . "\n" );
