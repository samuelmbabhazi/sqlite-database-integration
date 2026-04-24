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
	private $rule_names;
	private $fragment_ids;
	private $branches_for_token;
	private $nullable_branches;
	private $highest_terminal_id;
	private $select_statement_rule_id;
	private $single_candidate_rules;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar     = $grammar;
		$this->token_count = count( $tokens );
		// Append an end-of-input sentinel token whose id is EMPTY_RULE_ID
		// (0). The hot path can then read $tokens[$pos]->id unconditionally
		// when $pos is the current cursor, because the sentinel naturally
		// fails to match any real grammar terminal while feeding the
		// nullable-fallback branch of the selector check.
		$tokens[]                     = new WP_Parser_Token( WP_Parser_Grammar::EMPTY_RULE_ID, 0, 0, '' );
		$this->tokens                 = $tokens;
		$this->position               = 0;
		$this->rule_names             = $grammar->rule_names;
		$this->fragment_ids           = $grammar->fragment_ids ?? array();
		$this->branches_for_token     = $grammar->branches_for_token;
		$this->nullable_branches      = $grammar->nullable_branches;
		$this->highest_terminal_id    = $grammar->highest_terminal_id;
		$this->single_candidate_rules = $grammar->single_candidate_rules ?? array();

		// The INTO negative-lookahead only fires for selectStatement. Cache
		// the rule id so the per-call check is an int compare instead of a
		// string compare.
		if ( null === $grammar->select_statement_rule_id ) {
			$grammar->select_statement_rule_id = $grammar->get_rule_id( 'selectStatement' );
		}
		$this->select_statement_rule_id = $grammar->select_statement_rule_id;
	}

	public function parse() {
		// @TODO: Make the starting rule lookup non-grammar-specific.
		// Cache the query rule id on the grammar - get_rule_id() does a
		// linear array_search over all rule names which, on the MySQL
		// grammar, costs a few microseconds per lookup.
		$grammar = $this->grammar;
		if ( null === $grammar->start_rule_id ) {
			$grammar->start_rule_id = $grammar->get_rule_id( 'query' );
		}
		$ast = $this->parse_recursive( $grammar->start_rule_id );
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
		$tokens   = $this->tokens;
		$position = $this->position;

		// Narrow the set of branches worth trying using the precomputed FIRST
		// sets. When no entry exists for the current token but the rule is
		// nullable, all candidate branches would match empty, so we return
		// immediately without entering any branch.
		$tid = $tokens[ $position ]->id;
		if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
			$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
		} elseif ( isset( $this->nullable_branches[ $rule_id ] ) ) {
			return true;
		} else {
			return false;
		}

		$highest_terminal_id = $this->highest_terminal_id;
		$is_fragment         = isset( $this->fragment_ids[ $rule_id ] );
		$is_select_statement = $rule_id === $this->select_statement_rule_id;

		// Fast path for rules where every (rule, token) selector entry
		// points to exactly one branch - about 55% of nonterminal calls
		// on the MySQL corpus. Skipping the outer foreach avoids the
		// foreach iterator setup for those calls.
		if ( isset( $this->single_candidate_rules[ $rule_id ] ) ) {
			$branch         = $candidate_branches[0];
			$branch_matches = true;
			$children       = array();
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					if ( $tokens[ $this->position ]->id === $subrule_id ) {
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
				if ( is_array( $subnode ) ) {
					foreach ( $subnode as $c ) {
						$children[] = $c;
					}
				} else {
					$children[] = $subnode;
				}
			}

			if (
				$branch_matches
				&& $is_select_statement
				&& WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $this->position ]->id
			) {
				$branch_matches = false;
			}

			if ( ! $branch_matches ) {
				$this->position = $position;
				return false;
			}
			if ( ! $children ) {
				return true;
			}
			if ( $is_fragment ) {
				return $children;
			}
			return new WP_Parser_Node( $rule_id, $this->rule_names[ $rule_id ], $children );
		}

		$branch_matches = false;
		$children       = array();
		foreach ( $candidate_branches as $branch ) {
			$this->position = $position;
			$children       = array();
			$branch_matches = true;
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					// The sentinel at $tokens[$token_count] has id 0 so it
					// cannot match any real terminal, making the range check
					// unnecessary here.
					if ( $tokens[ $this->position ]->id === $subrule_id ) {
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
				if ( is_array( $subnode ) ) {
					// Fragment results are returned directly as a children
					// array so the parser does not allocate a Parser_Node
					// that would immediately be unwrapped into the parent.
					foreach ( $subnode as $c ) {
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

		// Fragments exist only to group symbols for reuse; their "node" would
		// get inlined into the parent on the very next step. Return the raw
		// children array so the caller can splice it without allocating a
		// throwaway WP_Parser_Node.
		if ( $is_fragment ) {
			return $children;
		}

		return new WP_Parser_Node( $rule_id, $this->rule_names[ $rule_id ], $children );
	}
}
