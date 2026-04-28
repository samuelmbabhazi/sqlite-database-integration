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
	return array(
		'highest_terminal_id'         => $grammar->get_highest_terminal_id(),
		'rules'                       => $grammar->get_rules(),
		'lookahead_is_match_possible' => $grammar->get_lookahead_is_match_possible(),
		'rule_names'                  => $grammar->get_rule_names(),
		'fragment_ids'                => $grammar->get_fragment_ids(),
	);
}
