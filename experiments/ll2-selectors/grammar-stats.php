<?php
$src = $argv[1];
require_once "$src/parser/class-wp-parser-grammar.php";
require_once "$src/parser/class-wp-parser-token.php";
require_once "$src/mysql/class-wp-mysql-token.php";
require_once "$src/mysql/class-wp-mysql-lexer.php";
$g = new WP_Parser_Grammar( require "$src/mysql/mysql-grammar.php" ); $g->build_all_selectors();
$total_rules = count( $g->rule_names );
$frag = count( $g->fragment_ids ?? array() );
$single = count( $g->single_candidate_rules ?? array() );
// branches_for_token: rule_id => [token_id => branches]. Count rules that, for
// EVERY token they accept, resolve to exactly one branch.
$bft = $g->branches_for_token;
$rules_with_selectors = count( $bft );
$always_single = 0; $multi_anywhere = 0;
foreach ( $bft as $rid => $by_tok ) {
	$max = 0;
	foreach ( $by_tok as $branches ) { $max = max( $max, count( $branches ) ); }
	if ( $max <= 1 ) { $always_single++; } else { $multi_anywhere++; }
}
printf("total rule_names: %d\n", $total_rules);
printf("fragments: %d\n", $frag);
printf("rules with selectors (branches_for_token): %d\n", $rules_with_selectors);
printf("single_candidate_rules (flagged): %d (%.1f%% of all rules)\n", $single, 100*$single/$total_rules);
printf("rules always-single-per-token: %d (%.1f%% of selector rules)\n", $always_single, 100*$always_single/$rules_with_selectors);
printf("rules multi-candidate for some token: %d (%.1f%% of selector rules)\n", $multi_anywhere, 100*$multi_anywhere/$rules_with_selectors);
