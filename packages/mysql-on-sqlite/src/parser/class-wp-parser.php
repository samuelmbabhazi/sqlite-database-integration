<?php

/**
 * A recursive descent parser.
 *
 * This is a dynamic recursive descent parser that can parse LL grammars.
 *
 * @TODO: Add a detailed description and list the properties that a grammar must
 *        satisfy in order to be supported by this parser (e.g., no left recursion).
 */
class WP_Parser {
	protected $grammar;
	protected $tokens;
	protected $token_count;
	protected $position;

	// Grammar data cached as instance fields so the hot path avoids an extra
	// property hop via $this->grammar on every recursive call.
	private $rules;
	private $rule_names;
	private $fragment_ids;
	private $branches_for_token;
	private $nullable_branches;
	private $highest_terminal_id;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar             = $grammar;
		$this->tokens              = $tokens;
		$this->token_count         = count( $tokens );
		$this->position            = 0;
		$this->rules               = $grammar->rules;
		$this->rule_names          = $grammar->rule_names;
		$this->fragment_ids        = $grammar->fragment_ids ?? array();
		$this->branches_for_token  = $grammar->branches_for_token;
		$this->nullable_branches   = $grammar->nullable_branches;
		$this->highest_terminal_id = $grammar->highest_terminal_id;
	}

	public function parse() {
		// @TODO: Make the starting rule lookup non-grammar-specific.
		$query_rule_id = $this->grammar->get_rule_id( 'query' );
		$ast           = $this->parse_recursive( $query_rule_id );
		return false === $ast ? null : $ast;
	}

	/**
	 * Parse a single non-terminal rule.
	 *
	 * This function is only called for non-terminal rule ids. Terminals are
	 * matched inline inside the branch loop below to avoid a function-call
	 * round trip per consumed token.
	 */
	private function parse_recursive( $rule_id ) {
		$tokens      = $this->tokens;
		$token_count = $this->token_count;
		$position    = $this->position;

		// Narrow the set of branches worth trying using the precomputed FIRST
		// sets. When no entry exists for the current token but the rule is
		// nullable, all candidate branches would match empty, so we return
		// immediately without entering any branch.
		$tid = $position < $token_count ? $tokens[ $position ]->id : WP_Parser_Grammar::EMPTY_RULE_ID;
		if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
			$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
		} elseif ( isset( $this->nullable_branches[ $rule_id ] ) ) {
			return true;
		} else {
			return false;
		}

		$highest_terminal_id = $this->highest_terminal_id;
		$branches            = $this->rules[ $rule_id ];
		$fragment_ids        = $this->fragment_ids;
		$rule_name           = $this->rule_names[ $rule_id ];
		$is_select_statement = 'selectStatement' === $rule_name;
		$branch_matches      = false;
		$children            = array();
		foreach ( $candidate_branches as $idx ) {
			$branch         = $branches[ $idx ];
			$this->position = $position;
			$children       = array();
			$branch_matches = true;
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					if (
						$this->position < $token_count
						&& $tokens[ $this->position ]->id === $subrule_id
					) {
						$children[] = $tokens[ $this->position ];
						++$this->position;
						continue;
					}
					$branch_matches = false;
					break;
				}

				$subnode = $this->parse_recursive( $subrule_id );
				if ( false === $subnode ) {
					$branch_matches = false;
					break;
				}
				if ( true === $subnode ) {
					continue;
				}
				if ( isset( $fragment_ids[ $subrule_id ] ) ) {
					foreach ( $subnode->get_children_ref() as $c ) {
						$children[] = $c;
					}
				} else {
					$children[] = $subnode;
				}
			}

			// Negative lookahead for INTO after a valid SELECT statement.
			// If we match a SELECT statement, but there is an INTO keyword after it,
			// we're in the wrong branch and need to leave matching to a later rule.
			// @TODO: Extract this to the "WP_MySQL_Parser" class, or add support
			//        for right-associative rules, which could solve this.
			//        See: https://github.com/mysql/mysql-workbench/blob/8.0.38/library/parsers/grammars/MySQLParser.g4#L994
			//        See: https://github.com/antlr/antlr4/issues/488
			if (
				$branch_matches
				&& $is_select_statement
				&& $this->position < $token_count
				&& WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $this->position ]->id
			) {
				$branch_matches = false;
			}

			if ( $branch_matches ) {
				break;
			}
		}

		if ( ! $branch_matches ) {
			$this->position = $position;
			return false;
		}

		if ( ! $children ) {
			return true;
		}

		return new WP_Parser_Node( $rule_id, $rule_name, $children );
	}
}
