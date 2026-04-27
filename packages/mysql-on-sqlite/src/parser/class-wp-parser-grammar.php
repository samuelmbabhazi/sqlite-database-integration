<?php

/**
 * A parser grammar.
 *
 * This class represents a parser grammar that can be consumed by WP_Parser.
 * It loads a compressed grammar from a PHP array, inflates it to an internal
 * representation, and precomputes a lookup table for quick branch selection.
 *
 * @TODO: Add more details about the grammar implementation.
 */
class WP_Parser_Grammar {
	/**
	 * ID for a special grammar rule that represents an empty "ε" (epsilon) rule.
	 *
	 * An "ε" rule in a grammar is a rule that matches an empty input of 0 bytes.
	 * It can be used to represent optional grammar productions, and it is helpful
	 * for expanding 0-or-1, 0-or-more, and 1-or-more quantifiers into simple rules.
	 *
	 * @TODO Investigate whether we can prevent possible conflict with a token ID.
	 *       The MySQL grammar doesn't define a token with ID "0", but generally
	 *       token IDs are not guaranteed to always satisfy this condition.
	 */
	const EMPTY_RULE_ID = 0;

	/**
	 * @TODO: Review and document these properties and their visibility.
	 */
	public $rules;
	public $rule_names;
	public $fragment_ids;
	public $lookahead_is_match_possible = array();
	public $branch_candidates           = array();
	public $lowest_non_terminal_id;
	public $highest_terminal_id;
	private $rule_ids_by_name = array();

	public function __construct( array $rules ) {
		$this->inflate( $rules );
	}

	public function get_rule_name( $rule_id ) {
		return $this->rule_names[ $rule_id ];
	}

	public function get_rule_id( $rule_name ) {
		return $this->rule_ids_by_name[ $rule_name ] ?? false;
	}

	/**
	 * Inflate the grammar to an internal representation optimized for parsing.
	 *
	 * The input grammar is a compressed PHP array to minimize the file size.
	 * Every rule and token in the compressed grammar is encoded as an integer.
	 */
	private function inflate( $grammar ) {
		$this->lowest_non_terminal_id = $grammar['rules_offset'];
		$this->highest_terminal_id    = $this->lowest_non_terminal_id - 1;

		foreach ( $grammar['rules_names'] as $rule_index => $rule_name ) {
			$this->rule_names[ $rule_index + $grammar['rules_offset'] ] = $rule_name;
			$this->rules[ $rule_index + $grammar['rules_offset'] ]      = array();
			$this->rule_ids_by_name[ $rule_name ]                       = $rule_index + $grammar['rules_offset'];

			/**
			 * Treat all intermediate rules as fragments to inline before returning
			 * the final parse tree to the API consumer.
			 *
			 * The original grammar was too difficult to parse with rules like:
			 *
			 *    query ::= EOF | ((simpleStatement | beginWork) ((SEMICOLON_SYMBOL EOF?) | EOF))
			 *
			 * We've factored rule fragments, such as `EOF?`, into separate rules, such as `%EOF_zero_or_one`.
			 * This is super useful for parsing, but it limits the API consumer's ability to
			 * reason about the parse tree.
			 *
			 * Fragments are intermediate rules that are not part of the original grammar.
			 * They are prefixed with a "%" to be distinguished from the original rules.
			 */
			if ( '%' === $rule_name[0] ) {
				$this->fragment_ids[ $rule_index + $grammar['rules_offset'] ] = true;
			}
		}

		$this->rules = array();
		foreach ( $grammar['grammar'] as $rule_index => $branches ) {
			$rule_id                 = $rule_index + $grammar['rules_offset'];
			$this->rules[ $rule_id ] = $branches;
		}

		$this->compute_lookahead_tables();
	}

	/**
	 * Compute FIRST-set lookahead tables for rules and individual branches.
	 */
	private function compute_lookahead_tables(): void {
		$first_sets = array();
		foreach ( $this->rules as $rule_id => $_branches ) {
			$first_sets[ $rule_id ] = array();
		}

		do {
			$changed = false;
			foreach ( $this->rules as $rule_id => $branches ) {
				foreach ( $branches as $branch ) {
					$branch_first = $this->get_branch_first_set( $branch, $first_sets );
					foreach ( $branch_first as $token_id => $_ ) {
						if ( ! isset( $first_sets[ $rule_id ][ $token_id ] ) ) {
							$first_sets[ $rule_id ][ $token_id ] = true;
							$changed                             = true;
						}
					}
				}
			}
		} while ( $changed );

		$this->lookahead_is_match_possible = $first_sets;

		foreach ( $this->rules as $rule_id => $branches ) {
			$this->branch_candidates[ $rule_id ] = array();
			foreach ( $branches as $branch_index => $branch ) {
				$branch_first = $this->get_branch_first_set(
					$branch,
					$first_sets
				);
				foreach ( $branch_first as $token_id => $_ ) {
					$this->branch_candidates[ $rule_id ][ $token_id ][] = $branch_index;
				}
			}
			if ( isset( $this->branch_candidates[ $rule_id ][ self::EMPTY_RULE_ID ] ) ) {
				$empty_branches = $this->branch_candidates[ $rule_id ][ self::EMPTY_RULE_ID ];
				foreach ( $this->branch_candidates[ $rule_id ] as $token_id => $branch_indexes ) {
					if ( self::EMPTY_RULE_ID === $token_id ) {
						continue;
					}
					$this->branch_candidates[ $rule_id ][ $token_id ] = $this->merge_branch_indexes(
						$branch_indexes,
						$empty_branches
					);
				}
			}
		}
	}

	/**
	 * Compute the FIRST set for a single branch.
	 *
	 * @param int[] $branch     A sequence of terminal and non-terminal rule IDs.
	 * @param array $first_sets Already-known FIRST sets for non-terminals.
	 * @return array<int, true> Token IDs that can start the branch.
	 */
	private function get_branch_first_set( array $branch, array $first_sets ): array {
		$branch_first  = array();
		$allows_empty  = true;
		$branch_length = count( $branch );

		for ( $i = 0; $i < $branch_length; $i++ ) {
			$symbol = $branch[ $i ];

			if ( $symbol <= $this->highest_terminal_id ) {
				if ( self::EMPTY_RULE_ID !== $symbol ) {
					$branch_first[ $symbol ] = true;
					$allows_empty            = false;
					break;
				}
				continue;
			}

			$symbol_first = $first_sets[ $symbol ] ?? array();
			foreach ( $symbol_first as $token_id => $_ ) {
				if ( self::EMPTY_RULE_ID !== $token_id ) {
					$branch_first[ $token_id ] = true;
				}
			}

			if ( ! isset( $symbol_first[ self::EMPTY_RULE_ID ] ) ) {
				$allows_empty = false;
				break;
			}
		}

		if ( $allows_empty ) {
			$branch_first[ self::EMPTY_RULE_ID ] = true;
		}

		return $branch_first;
	}

	/**
	 * Merge two branch-index lists while preserving grammar order.
	 *
	 * @param int[] $left  First sorted branch-index list.
	 * @param int[] $right Second sorted branch-index list.
	 * @return int[] Merged sorted branch-index list.
	 */
	private function merge_branch_indexes( array $left, array $right ): array {
		$merged      = array();
		$left_index  = 0;
		$right_index = 0;
		$left_count  = count( $left );
		$right_count = count( $right );

		while ( $left_index < $left_count || $right_index < $right_count ) {
			if (
				$right_index >= $right_count ||
				(
					$left_index < $left_count &&
					$left[ $left_index ] < $right[ $right_index ]
				)
			) {
				$merged[] = $left[ $left_index++ ];
			} elseif (
				$left_index >= $left_count ||
				$right[ $right_index ] < $left[ $left_index ]
			) {
				$merged[] = $right[ $right_index++ ];
			} else {
				$merged[] = $left[ $left_index ];
				++$left_index;
				++$right_index;
			}
		}

		return $merged;
	}
}
