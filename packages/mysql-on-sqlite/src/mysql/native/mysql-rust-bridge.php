<?php

/**
 * Bridge helpers for the optional Rust MySQL lexer/parser extension.
 * PHP keeps the grammar object, while Rust owns the exported parser state.
 */

/**
 * Export grammar internals for the native parser.
 *
 * @param WP_Parser_Grammar $grammar Parser grammar.
 * @return array<string, mixed>
 */
function wp_sqlite_mysql_native_export_grammar( WP_Parser_Grammar $grammar ): array {
	// The native parser only needs each rule's FIRST set to decide early
	// whether a rule can start with the current token; it builds its own
	// branch candidates from `rules`. Export the eagerly-computed FIRST sets
	// directly so the lazy per-token selector table is never materialized for
	// the native path.
	return array(
		'highest_terminal_id' => $grammar->highest_terminal_id,
		'rules'               => $grammar->rules,
		'first_sets'          => $grammar->first_sets,
		'nullable_branches'   => $grammar->nullable_branches,
		'rule_names'          => $grammar->rule_names,
		'fragment_ids'        => $grammar->fragment_ids,
	);
}
