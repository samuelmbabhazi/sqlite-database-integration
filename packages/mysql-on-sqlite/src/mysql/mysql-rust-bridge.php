<?php

/**
 * Bridge helpers for the optional Rust MySQL lexer/parser extension.
 *
 * The extension owns lexing and parsing. PHP keeps constructing the public
 * token and AST classes so callers receive the same objects with or without
 * the native extension loaded.
 */

/**
 * Create a public MySQL token object for the native lexer.
 *
 * @param int    $id           Token ID.
 * @param int    $start        Token start offset.
 * @param int    $length       Token length.
 * @param string $sql          Original SQL input.
 * @param bool   $no_backslash Whether NO_BACKSLASH_ESCAPES is active.
 * @return WP_MySQL_Token
 */
function wp_sqlite_mysql_native_new_token( int $id, int $start, int $length, string $sql, bool $no_backslash ): WP_MySQL_Token {
	return new WP_MySQL_Token( $id, $start, $length, $sql, $no_backslash );
}

/**
 * Create a public parser node object for the native parser.
 *
 * @param int    $rule_id   Rule ID.
 * @param string $rule_name Rule name.
 * @return WP_Parser_Node
 */
function wp_sqlite_mysql_native_new_node( int $rule_id, string $rule_name ): WP_Parser_Node {
	return new WP_Parser_Node( $rule_id, $rule_name );
}

/**
 * Export grammar internals for the native parser.
 *
 * @param WP_Parser_Grammar $grammar Parser grammar.
 * @return array<string, mixed>
 */
function wp_sqlite_mysql_native_export_grammar( WP_Parser_Grammar $grammar ): array {
	return array(
		'highest_terminal_id'         => $grammar->highest_terminal_id,
		'rules'                       => $grammar->rules,
		'lookahead_is_match_possible' => $grammar->lookahead_is_match_possible,
		'rule_names'                  => $grammar->rule_names,
		'fragment_ids'                => $grammar->fragment_ids,
	);
}
