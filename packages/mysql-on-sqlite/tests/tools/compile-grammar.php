<?php
/**
 * Compile the MySQL grammar into a dedicated PHP class.
 *
 * Emits one method per reachable rule with branch dispatch unrolled as a
 * switch-on-token-id, terminal matches inlined, and the non-fragment vs
 * fragment distinction resolved at compile time so every call site gets
 * minimal per-iteration work.
 *
 * Usage:
 *   php tests/tools/compile-grammar.php \
 *     > src/mysql/class-wp-mysql-compiled-parser.php
 */

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-node.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-parser.php';

$grammar     = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$query_rid   = $grammar->get_rule_id( 'query' );
$select_rid  = $grammar->get_rule_id( 'selectStatement' );
$htid        = $grammar->highest_terminal_id;
$into_symbol = WP_MySQL_Lexer::INTO_SYMBOL;

// Reachability + fragment reference count.
$visited = array();
$refs    = array();
$queue   = array( $query_rid );
while ( $queue ) {
	$r = array_pop( $queue );
	if ( isset( $visited[ $r ] ) ) {
		continue;
	}
	$visited[ $r ] = true;
	foreach ( $grammar->rules[ $r ] as $branch ) {
		foreach ( $branch as $sym ) {
			if ( $sym > $htid ) {
				$refs[ $sym ] = ( $refs[ $sym ] ?? 0 ) + 1;
				if ( ! isset( $visited[ $sym ] ) ) {
					$queue[] = $sym;
				}
			}
		}
	}
}

// Decide which rules get inlined.
// Inline a fragment only if it is reachable AND single-branch (the simple
// case where we can splice its symbols into the parent branch). Multi-branch
// fragments require splatting which can explode parent branch counts; keep
// them as methods for now.
$inline_fragments = array();
foreach ( $grammar->fragment_ids as $rid => $_ ) {
	if (
		isset( $visited[ $rid ] )
		&& isset( $grammar->rules[ $rid ] )
		&& 1 === count( $grammar->rules[ $rid ] )
	) {
		$inline_fragments[ $rid ] = true;
	}
}

// Rules that will get a method.
$kept = array();
foreach ( $visited as $rid => $_ ) {
	if ( ! isset( $inline_fragments[ $rid ] ) ) {
		$kept[ $rid ] = true;
	}
}

/**
 * Compute the flattened symbol sequence for a branch, splicing any inlined
 * single-use fragments in place. Cycles fall back to leaving the reference.
 */
$flatten = function ( array $branch ) use ( &$flatten, $grammar, $inline_fragments, $htid ) {
	static $expanding = array();
	$out              = array();
	foreach ( $branch as $sym ) {
		if ( $sym <= $htid ) {
			$out[] = $sym;
			continue;
		}
		if ( ! isset( $inline_fragments[ $sym ] ) ) {
			$out[] = $sym;
			continue;
		}
		if ( count( $grammar->rules[ $sym ] ) !== 1 ) {
			// Multi-branch single-use fragment: keep as call to avoid
			// exponential parent-branch explosion. Future work could splat
			// selected cases where branch count stays small.
			$out[] = $sym;
			continue;
		}
		if ( isset( $expanding[ $sym ] ) ) {
			$out[] = $sym;
			continue;
		}
		$expanding[ $sym ] = true;
		foreach ( $flatten( $grammar->rules[ $sym ][0] ) as $s ) {
			$out[] = $s;
		}
		unset( $expanding[ $sym ] );
	}
	return $out;
};

/**
 * PHP-safe method name for a rule id.
 */
$method_name = function ( $rid ) use ( $grammar ) {
	$raw = $grammar->rule_names[ $rid ];
	// Fragment names start with "%" - turn that into "f_".
	$clean = '%' === $raw[0] ? 'f_' . substr( $raw, 1 ) : $raw;
	$clean = preg_replace( '/[^A-Za-z0-9_]/', '_', $clean );
	return 'r_' . $clean . '_' . $rid;
};

/**
 * Emit code that matches a single symbol in a branch, appending on success
 * and jumping to $fail_label (via `goto`) on failure. We use goto because
 * PHP `break`/`continue` can only target immediate loops, and we want to
 * roll back the position in a shared failure path.
 *
 * For single-branch rules there is no rollback label - failure just returns
 * immediately so the label is reused inline.
 */
$emit_symbol = function ( $sym, $indent, $fail_stmt, $skip_check = false ) use ( $grammar, $htid, $inline_fragments, &$method_name, &$flatten, &$emit_symbol ) {
	$out = '';
	if ( $sym <= $htid ) {
		// Inline terminal match. The caller may tell us the token at the
		// current position is already known to match (via switch case
		// dispatch), in which case the check is redundant.
		if ( ! $skip_check ) {
			$out .= $indent . "if (\$tokens[\$this->position]->id !== $sym) $fail_stmt\n";
		}
		$out .= $indent . "\$children[] = \$tokens[\$this->position];\n";
		$out .= $indent . "++\$this->position;\n";
		return $out;
	}

	$is_fragment = isset( $grammar->fragment_ids[ $sym ] );
	$method      = $method_name( $sym );
	$out        .= $indent . "\$sub = \$this->$method();\n";
	$out        .= $indent . "if (false === \$sub) $fail_stmt\n";
	$nullable    = isset( $grammar->nullable_branches[ $sym ] );
	if ( $is_fragment ) {
		if ( $nullable ) {
			$out .= $indent . "if (true !== \$sub) { foreach (\$sub as \$c) \$children[] = \$c; }\n";
		} else {
			$out .= $indent . "foreach (\$sub as \$c) \$children[] = \$c;\n";
		}
	} else {
		if ( $nullable ) {
			$out .= $indent . "if (true !== \$sub) \$children[] = \$sub;\n";
		} else {
			$out .= $indent . "\$children[] = \$sub;\n";
		}
	}
	return $out;
};

/**
 * Emit the body of a rule method.
 */
$emit_method = function ( $rid ) use ( $grammar, $htid, $select_rid, $into_symbol, $inline_fragments, &$method_name, &$flatten, &$emit_symbol ) {
	$name        = $method_name( $rid );
	$is_fragment = isset( $grammar->fragment_ids[ $rid ] );
	$is_select   = $rid === $select_rid;
	$rule_name   = $grammar->rule_names[ $rid ];
	$nullable    = isset( $grammar->nullable_branches[ $rid ] );

	// Per-token selector. Entries are lists of branch symbol sequences (the
	// runtime format). Group tokens whose branch list is identical so their
	// switch cases share a body.
	$selector = $grammar->branches_for_token[ $rid ] ?? array();
	$groups   = array();
	foreach ( $selector as $tid => $branch_seqs ) {
		$sig_parts = array();
		foreach ( $branch_seqs as $seq ) {
			$sig_parts[] = implode( ',', $seq );
		}
		$key                        = implode( '|', $sig_parts );
		$groups[ $key ]['branches'] = $branch_seqs;
		$groups[ $key ]['tids'][]   = $tid;
	}

	$code  = "\tprivate function $name() {\n";
	$code .= "\t\t\$tokens = \$this->tokens;\n";
	$code .= "\t\t\$position = \$this->position;\n";
	$code .= "\t\t\$tid = \$tokens[\$position]->id;\n";

	// "One of N terminals" fast path. When every branch is a single
	// terminal, the entire rule collapses to: check accept set, consume
	// one token, return. A rule like `%f1282` (406 terminal choices)
	// compiles to ~8 lines instead of ~2.8k.
	$all_single_terminal = true;
	$accept              = array();
	foreach ( $grammar->rules[ $rid ] as $b ) {
		if ( 1 !== count( $b ) || $b[0] > $htid || 0 === $b[0] ) {
			$all_single_terminal = false;
			break;
		}
		$accept[ $b[0] ] = true;
	}
	if ( $all_single_terminal && $accept ) {
		$keys = array_keys( $accept );
		sort( $keys );
		$lookup = '[' . implode( '=>1,', $keys ) . '=>1]';
		$code  .= "\t\tstatic \$ok = $lookup;\n";
		$code  .= "\t\tif (!isset(\$ok[\$tid])) return " . ( $nullable ? 'true' : 'false' ) . ";\n";
		$code  .= "\t\t\$t = \$tokens[\$position];\n";
		$code  .= "\t\t\$this->position = \$position + 1;\n";
		if ( $is_select ) {
			// selectStatement is never single-terminal, but guard anyway.
			$code .= "\t\tif (\$tokens[\$position + 1]->id === $into_symbol) { \$this->position = \$position; return false; }\n";
		}
		if ( $is_fragment ) {
			$code .= "\t\treturn array(\$t);\n";
		} else {
			$code .= "\t\treturn new WP_Parser_Node($rid, " . var_export( $rule_name, true ) . ", array(\$t));\n";
		}
		$code .= "\t}\n";
		return $code;
	}

	if ( count( $groups ) === 1 ) {
		// All accepting tokens reach the same branch list. A bare isset()
		// check against a shared lookup table is much smaller than the
		// equivalent 200-way switch case list and lets PHP resolve
		// dispatch in a single hash lookup.
		$only = reset( $groups );
		$tids = $only['tids'];
		sort( $tids );
		$lookup = '[' . implode( '=>1,', $tids ) . '=>1]';
		$code  .= "\t\tstatic \$first = $lookup;\n";
		$code  .= "\t\tif (!isset(\$first[\$tid])) return " . ( $nullable ? 'true' : 'false' ) . ";\n";
		// We cannot hand $known_tids here: the single-branch-group fast
		// path covers many tokens, so the branch's first symbol may not be
		// a specific one of them.
		$code .= emit_group_body( $only['branches'], $grammar, $rid, $rule_name, $is_fragment, $is_select, $into_symbol, $htid, $inline_fragments, $method_name, $flatten, $emit_symbol, false );
		// All branches failed; emit_group_body already reset the position.
		$code .= "\t\treturn " . ( $nullable ? 'true' : 'false' ) . ";\n";
	} else {
		$code .= "\t\tswitch (\$tid) {\n";
		foreach ( $groups as $g ) {
			foreach ( $g['tids'] as $tid ) {
				$code .= "\t\t\tcase $tid:\n";
			}
			$code .= emit_group_body( $g['branches'], $grammar, $rid, $rule_name, $is_fragment, $is_select, $into_symbol, $htid, $inline_fragments, $method_name, $flatten, $emit_symbol, true, $g['tids'] );
		}
		$code .= "\t\t}\n";
		$code .= "\t\treturn " . ( $nullable ? 'true' : 'false' ) . ";\n";
	}
	$code .= "\t}\n";
	return $code;
};

function emit_group_body( array $branch_seqs, WP_Parser_Grammar $g, $rid, $rule_name, $is_fragment, $is_select, $into_symbol, $htid, $inline_fragments, $method_name, $flatten, $emit_symbol, $in_switch = true, array $known_tids = array() ) {
	$indent = $in_switch ? "\t\t\t\t" : "\t\t";
	$out    = '';
	$count  = count( $branch_seqs );

	foreach ( $branch_seqs as $n => $raw_branch ) {
		$branch  = $flatten( $raw_branch );
		$is_last = ( $n === $count - 1 );

		// The switch dispatch guarantees the current token matches a case
		// label, so if there's exactly one label and the branch starts
		// with that same terminal we can skip the redundant id check.
		$first_is_known_terminal = false;
		if ( count( $known_tids ) === 1 && $branch && $branch[0] === $known_tids[0] ) {
			$first_is_known_terminal = true;
		}

		if ( $count > 1 ) {
			// Multi-branch: wrap each attempt in do-while(false). Break
			// falls through to the next attempt; the final break falls
			// through to the switch-level break / rule-level fall-through.
			$out         .= $indent . "do {\n";
			$inner_indent = $indent . "\t";
			$fail_stmt    = 'break;';
			$out         .= $inner_indent . "\$children = array();\n";
			$out         .= $inner_indent . "\$this->position = \$position;\n";
			foreach ( $branch as $i => $sym ) {
				$skip_check = ( 0 === $i && $first_is_known_terminal );
				$out       .= $emit_symbol( $sym, $inner_indent, $fail_stmt, $skip_check );
			}
			if ( $is_select ) {
				$out .= $inner_indent . "if (\$tokens[\$this->position]->id === $into_symbol) break;\n";
			}
			$out .= emit_branch_return( $inner_indent, $rid, $rule_name, $is_fragment );
			$out .= $indent . "} while (false);\n";
		} else {
			// Single branch: no alternatives to try, just inline.
			$out      .= $indent . "\$children = array();\n";
			$fail_stmt = '{ $this->position = $position; return false; }';
			foreach ( $branch as $i => $sym ) {
				$skip_check = ( 0 === $i && $first_is_known_terminal );
				$out       .= $emit_symbol( $sym, $indent, $fail_stmt, $skip_check );
			}
			if ( $is_select ) {
				$out .= $indent . "if (\$tokens[\$this->position]->id === $into_symbol) { \$this->position = \$position; return false; }\n";
			}
			$out .= emit_branch_return( $indent, $rid, $rule_name, $is_fragment );
			if ( $in_switch ) {
				$out .= $indent . "break;\n";
			}
			return $out;
		}
	}
	// Multi-branch group fell through all do-while attempts: reset and
	// break out of the switch (or return to the rule-level fallback).
	$out .= $indent . "\$this->position = \$position;\n";
	if ( $in_switch ) {
		$out .= $indent . "break;\n";
	}
	return $out;
}

function emit_branch_return( $indent, $rid, $rule_name, $is_fragment ) {
	$out  = '';
	$out .= $indent . "if (!\$children) return true;\n";
	if ( $is_fragment ) {
		$out .= $indent . "return \$children;\n";
	} else {
		$out .= $indent . 'return new WP_Parser_Node(' . $rid . ', ' . var_export( $rule_name, true ) . ", \$children);\n";
	}
	return $out;
}

// Emit the class. The generated parser is self-contained: it bakes every
// FIRST set, rule name, and branch structure into the emitted code, so no
// WP_Parser_Grammar has to be loaded at runtime.
echo "<?php\n\n";
echo "/**\n * AUTO-GENERATED. Do not modify by hand.\n * Regenerate with tests/tools/compile-grammar.php.\n */\n";
echo "class WP_MySQL_Compiled_Parser {\n";
echo "\tprivate \$tokens;\n";
echo "\tprivate \$position;\n\n";
echo "\tpublic function __construct( array \$tokens ) {\n";
echo "\t\t\$tokens[] = new WP_Parser_Token( 0, 0, 0, '' );\n";
echo "\t\t\$this->tokens = \$tokens;\n";
echo "\t\t\$this->position = 0;\n";
echo "\t}\n\n";
echo "\tpublic function parse() {\n";
echo "\t\t\$ast = \$this->" . $method_name( $query_rid ) . "();\n";
echo "\t\treturn false === \$ast ? null : \$ast;\n";
echo "\t}\n\n";

// Sort for deterministic output.
ksort( $kept );
foreach ( $kept as $rid => $_ ) {
	echo $emit_method( $rid );
	echo "\n";
}

echo "}\n";
