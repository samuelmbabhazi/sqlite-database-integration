<?php
/**
 * Dump the post-inflation grammar state as a PHP file so the grammar can
 * be loaded without recomputing FIRST / NULLABLE / branch selectors at
 * runtime.
 *
 * Usage:
 *   php tests/tools/dump-inflated-grammar.php > /tmp/mysql-grammar-inflated.php
 */

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';

$g = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );

$data = array(
	'rules'                  => $g->rules,
	'rule_names'             => $g->rule_names,
	'fragment_ids'           => $g->fragment_ids ?? array(),
	'branches_for_token'     => $g->branches_for_token,
	'nullable_branches'      => $g->nullable_branches,
	'lowest_non_terminal_id' => $g->lowest_non_terminal_id,
	'highest_terminal_id'    => $g->highest_terminal_id,
);

echo "<?php\n// AUTO-GENERATED.\nreturn ";
echo var_export( $data, true );
echo ";\n";
