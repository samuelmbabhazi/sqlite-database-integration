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
	protected $position;
	private $rules;
	private $rule_names;
	private $fragment_ids;
	private $branch_candidates;
	private $highest_terminal_id;
	private $select_statement_rule_id;
	private $token_count;
	private $failed_matches;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar  = $grammar;
		$this->tokens   = $tokens;
		$this->position = 0;
	}

	public function parse() {
		$this->rules                       = $this->grammar->rules;
		$this->rule_names                  = $this->grammar->rule_names;
		$this->fragment_ids                = $this->grammar->fragment_ids;
		$this->branch_candidates           = $this->grammar->branch_candidates;
		$this->highest_terminal_id         = $this->grammar->highest_terminal_id;
		$this->select_statement_rule_id    = $this->grammar->get_rule_id( 'selectStatement' );
		$this->token_count                 = count( $this->tokens );
		$this->failed_matches              = array();

		// @TODO: Make the starting rule lookup non-grammar-specific.
		$query_rule_id = $this->grammar->get_rule_id( 'query' );
		$ast           = $this->parse_recursive( $query_rule_id );
		return false === $ast ? null : $ast;
	}

	private function parse_recursive( $rule_id ) {
		$is_terminal = $rule_id <= $this->highest_terminal_id;
		if ( $is_terminal ) {
			if ( $this->position >= $this->token_count ) {
				return false;
			}

			if ( WP_Parser_Grammar::EMPTY_RULE_ID === $rule_id ) {
				return true;
			}

			if ( $this->tokens[ $this->position ]->id === $rule_id ) {
				return $this->tokens[ $this->position++ ];
			}
			return false;
		}

		$starting_position = $this->position;
		if ( isset( $this->failed_matches[ $starting_position ][ $rule_id ] ) ) {
			return false;
		}

		$branches          = $this->rules[ $rule_id ];

		$token_id = $this->position < $this->token_count
			? $this->tokens[ $this->position ]->id
			: null;
		$rule_name          = $this->rule_names[ $rule_id ];
		$branch_candidates  = $this->branch_candidates[ $rule_id ];
		$branch_indexes     = null !== $token_id && isset( $branch_candidates[ $token_id ] )
			? $branch_candidates[ $token_id ]
			: ( $branch_candidates[ WP_Parser_Grammar::EMPTY_RULE_ID ] ?? array() );

		if ( ! count( $branch_indexes ) ) {
			$this->failed_matches[ $starting_position ][ $rule_id ] = true;
			return false;
		}

		$branch_matches     = false;
		$node               = null;
		foreach ( $branch_indexes as $branch_index ) {
			$branch         = $branches[ $branch_index ];
			$this->position = $starting_position;
			$node           = new WP_Parser_Node( $rule_id, $rule_name );
			$branch_matches = true;
			foreach ( $branch as $subrule_id ) {
				$subnode = $this->parse_recursive( $subrule_id );
				if ( false === $subnode ) {
					$branch_matches = false;
					break;
				} elseif ( true === $subnode ) {
					/*
					 * The subrule was matched without actually matching a token.
					 * This means a special empty "ε" (epsilon) rule was matched.
					 * An "ε" rule in a grammar matches an empty input of 0 bytes.
					 * It is used to represent optional grammar productions.
					 */
					continue;
				}
				if ( isset( $this->fragment_ids[ $subrule_id ] ) ) {
					$node->merge_fragment( $subnode );
				} else {
					$node->append_child( $subnode );
				}
			}

			// Negative lookahead for INTO after a valid SELECT statement.
			// If we match a SELECT statement, but there is an INTO keyword after it,
			// we're in the wrong branch and need to leave matching to a later rule.
			// @TODO: Extract this to the "WP_MySQL_Parser" class, or add support
			//        for right-associative rules, which could solve this.
			//        See: https://github.com/mysql/mysql-workbench/blob/8.0.38/library/parsers/grammars/MySQLParser.g4#L994
			//        See: https://github.com/antlr/antlr4/issues/488
			$la = $this->tokens[ $this->position ] ?? null;
			if (
				$la
				&& $rule_id === $this->select_statement_rule_id
				&& WP_MySQL_Lexer::INTO_SYMBOL === $la->id
			) {
				$branch_matches = false;
			}

			if ( true === $branch_matches ) {
				break;
			}
		}

		if ( ! $branch_matches ) {
			$this->position = $starting_position;
			$this->failed_matches[ $starting_position ][ $rule_id ] = true;
			return false;
		}

		if ( null === $node || ! $node->has_child() ) {
			return true;
		}

		return $node;
	}
}
