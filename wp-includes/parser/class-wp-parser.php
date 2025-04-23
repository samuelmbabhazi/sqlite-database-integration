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
	private $grammar;
	private $tokens;
	private $position;

	/**
	 * The current AST.
	 *
	 * @var WP_Parser_Node|null
	 */
	private $current_ast;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar  = $grammar;
		$this->tokens   = $tokens;
		$this->position = 0;
	}

	public function parse() {
		// @TODO: Make the starting rule lookup non-grammar-specific.
		$query_rule_id = $this->grammar->get_rule_id( 'query' );
		$ast           = $this->parse_recursive( $query_rule_id );
		return false === $ast ? null : $ast;
	}

	/**
	 * Parse the next AST from the input tokens.
	 *
	 * This method reads tokens from the input until an AST is successfully parsed.
	 * It starts from "$this->tokens[ $this->position ]", advances the number of
	 * tokens read, and returns a boolean indicating whether an AST was successfully
	 * parsed. When the end of the input is reached or a query could not be parsed,
	 * the method returns false.
	 *
	 * @return bool Whether an AST was successfully parsed.
	 */
	public function next_ast(): bool {
		if ( $this->position >= count( $this->tokens ) ) {
			return false;
		}
		$this->current_ast = $this->parse();
		return true;
	}

	/**
	 * Get the current AST.
	 *
	 * When no AST has been parsed yet, the parsing failed, or the end of the
	 * input was reached, this method returns null.
	 *
	 * @return WP_Parser_Node|null The current AST, or null if no AST was parsed.
	 */
	public function get_ast(): ?WP_Parser_Node {
		return $this->current_ast;
	}

	private function parse_recursive( $rule_id ) {
		$is_terminal = $rule_id <= $this->grammar->highest_terminal_id;
		if ( $is_terminal ) {
			if ( $this->position >= count( $this->tokens ) ) {
				return false;
			}

			if ( WP_Parser_Grammar::EMPTY_RULE_ID === $rule_id ) {
				return true;
			}

			if ( $this->tokens[ $this->position ]->id === $rule_id ) {
				++$this->position;
				return $this->tokens[ $this->position - 1 ];
			}
			return false;
		}

		$branches = $this->grammar->rules[ $rule_id ];
		if ( ! count( $branches ) ) {
			return false;
		}

		// Bale out from processing the current branch if none of its rules can
		// possibly match the current token.
		if ( isset( $this->grammar->lookahead_is_match_possible[ $rule_id ] ) ) {
			$token_id = $this->tokens[ $this->position ]->id;
			if (
				! isset( $this->grammar->lookahead_is_match_possible[ $rule_id ][ $token_id ] ) &&
				! isset( $this->grammar->lookahead_is_match_possible[ $rule_id ][ WP_Parser_Grammar::EMPTY_RULE_ID ] )
			) {
				return false;
			}
		}

		$rule_name         = $this->grammar->rule_names[ $rule_id ];
		$starting_position = $this->position;
		foreach ( $branches as $branch ) {
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
				} elseif ( is_array( $subnode ) && 0 === count( $subnode ) ) {
					continue;
				}
				if ( is_array( $subnode ) && ! count( $subnode ) ) {
					continue;
				}
				if ( isset( $this->grammar->fragment_ids[ $subrule_id ] ) ) {
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
			if ( $la && 'selectStatement' === $rule_name && WP_MySQL_Lexer::INTO_SYMBOL === $la->id ) {
				$branch_matches = false;
			}

			if ( true === $branch_matches ) {
				break;
			}
		}

		if ( ! $branch_matches ) {
			$this->position = $starting_position;
			return false;
		}

		if ( ! $node->has_child() ) {
			return true;
		}

		return $node;
	}
}
