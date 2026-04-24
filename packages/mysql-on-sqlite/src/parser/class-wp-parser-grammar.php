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

	/**
	 * Per-rule branch selector keyed by the next token id.
	 *
	 * When set, `$branches_for_token[$rule_id][$token_id]` is the ordered list
	 * of branch indexes in `$rules[$rule_id]` that can possibly match when the
	 * current token has the given id. Nullable branches appear in every entry.
	 *
	 * If an entry does not exist for the current token, `$nullable_branches`
	 * is consulted. If both are empty, the rule cannot match and the parser
	 * returns immediately.
	 *
	 * Rules whose FIRST set could not be computed do not appear in the map;
	 * for those the parser falls back to trying every branch.
	 *
	 * @var array<int,array<int,int[]>>
	 */
	public $branches_for_token = array();

	/**
	 * Per-rule list of nullable branch indexes.
	 *
	 * @var array<int,int[]>
	 */
	public $nullable_branches = array();

	public $lowest_non_terminal_id;
	public $highest_terminal_id;

	/**
	 * Cached id of the grammar's start rule, populated lazily on first parse.
	 *
	 * @var int|null
	 */
	public $start_rule_id;

	public function __construct( array $rules ) {
		$this->inflate( $rules );
	}

	public function get_rule_name( $rule_id ) {
		return $this->rule_names[ $rule_id ];
	}

	public function get_rule_id( $rule_name ) {
		return array_search( $rule_name, $this->rule_names, true );
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
			$rule_id                      = $rule_index + $grammar['rules_offset'];
			$this->rule_names[ $rule_id ] = $rule_name;

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
				$this->fragment_ids[ $rule_id ] = true;
			}
		}

		$this->rules = array();
		foreach ( $grammar['grammar'] as $rule_index => $branches ) {
			$rule_id                 = $rule_index + $grammar['rules_offset'];
			$this->rules[ $rule_id ] = $branches;
		}

		$this->inline_single_branch_fragments();
		$this->strip_epsilon_markers();
		$this->build_branch_selectors();
	}

	/**
	 * Remove explicit `EMPTY_RULE_ID` markers from branches.
	 *
	 * The epsilon marker is a zero-width, always-matching symbol used in the
	 * grammar to express optional productions. At parse time it would still
	 * be walked and "continued" over for no effect, so stripping it ahead of
	 * time removes a per-symbol branch in the hot loop.
	 *
	 * A pure-epsilon branch (`[EMPTY_RULE_ID]`) becomes an empty branch (`[]`)
	 * which the parser already handles: the inner symbol loop does nothing and
	 * the rule returns a successful empty match.
	 */
	private function strip_epsilon_markers() {
		foreach ( $this->rules as $rule_id => $branches ) {
			foreach ( $branches as $i => $branch ) {
				if ( in_array( self::EMPTY_RULE_ID, $branch, true ) ) {
					$this->rules[ $rule_id ][ $i ] = array_values(
						array_filter(
							$branch,
							static function ( $s ) {
								return self::EMPTY_RULE_ID !== $s;
							}
						)
					);
				}
			}
		}
	}

	/**
	 * Inline single-branch fragment rules into their call sites.
	 *
	 * The grammar contains many single-branch fragment rules that exist only
	 * to factor shared sub-sequences out of larger productions. At runtime
	 * the parser would descend into each such fragment via a recursive call
	 * just to walk the same symbol sequence and splice the results back into
	 * the parent. Expanding them in-place at build time eliminates that call
	 * chain without changing the resulting AST because fragment children are
	 * already flattened into the parent node.
	 *
	 * Fragments with two or more alternatives (e.g., `%EOF_zero_or_one`) are
	 * left intact because they represent real choices that must be evaluated
	 * against the current token.
	 */
	private function inline_single_branch_fragments() {
		$rules        = $this->rules;
		$fragment_ids = $this->fragment_ids ?? array();
		$low_nt       = $this->lowest_non_terminal_id;

		// Precompute the set of single-branch fragments that are candidates
		// for inlining.
		$inlinable = array();
		foreach ( $fragment_ids as $rule_id => $_ ) {
			if ( isset( $rules[ $rule_id ] ) && 1 === count( $rules[ $rule_id ] ) ) {
				$inlinable[ $rule_id ] = true;
			}
		}

		// Depth-first expansion memoized per rule, with cycle detection.
		$expanded = array();
		$visiting = array();
		$expand_branch = function ( array $branch ) use ( &$expand_branch, &$expanded, &$visiting, $rules, $low_nt, $inlinable ) {
			$out = array();
			foreach ( $branch as $sym ) {
				if ( $sym < $low_nt ) {
					$out[] = $sym;
					continue;
				}
				if ( ! isset( $inlinable[ $sym ] ) ) {
					$out[] = $sym;
					continue;
				}
				if ( isset( $visiting[ $sym ] ) ) {
					// Cycle: leave the reference in place.
					$out[] = $sym;
					continue;
				}
				if ( ! isset( $expanded[ $sym ] ) ) {
					$visiting[ $sym ]    = true;
					$expanded[ $sym ]    = $expand_branch( $rules[ $sym ][0] );
					unset( $visiting[ $sym ] );
				}
				foreach ( $expanded[ $sym ] as $s ) {
					$out[] = $s;
				}
			}
			return $out;
		};

		// Rewrite every rule's branches with fragments inlined.
		foreach ( $this->rules as $rule_id => $branches ) {
			$new_branches = array();
			foreach ( $branches as $branch ) {
				$new_branches[] = $expand_branch( $branch );
			}
			$this->rules[ $rule_id ] = $new_branches;
		}
	}

	/**
	 * Compute FIRST and NULLABLE sets for every non-terminal, then denormalize
	 * them into a per-rule map of `token_id => branch_index[]` so the parser
	 * can jump straight to the branches that can possibly match the current
	 * token.
	 *
	 * This replaces the previous coarse "can any branch match this token?"
	 * lookahead. On the MySQL corpus the fine-grained selector skips ~60%
	 * of the branch attempts that the parser used to try and fail.
	 */
	private function build_branch_selectors() {
		$rules        = $this->rules;
		$low_nt       = $this->lowest_non_terminal_id;
		$empty_rule   = self::EMPTY_RULE_ID;
		$rule_ids     = array_keys( $rules );
		$nullable     = array();
		$first_sets   = array();

		foreach ( $rule_ids as $rule_id ) {
			$nullable[ $rule_id ]   = false;
			$first_sets[ $rule_id ] = array();
		}

		// Iterate to fixpoint. FIRST and NULLABLE set monotonically grow.
		do {
			$changed = false;
			foreach ( $rule_ids as $rule_id ) {
				$branches = $rules[ $rule_id ];
				foreach ( $branches as $branch ) {
					$branch_nullable = true;
					foreach ( $branch as $symbol ) {
						if ( $empty_rule === $symbol ) {
							// ε: contributes nothing to FIRST, stays nullable.
							continue;
						}
						if ( $symbol < $low_nt ) {
							// Terminal.
							if ( ! isset( $first_sets[ $rule_id ][ $symbol ] ) ) {
								$first_sets[ $rule_id ][ $symbol ] = true;
								$changed                           = true;
							}
							$branch_nullable = false;
							break;
						}
						// Non-terminal.
						foreach ( $first_sets[ $symbol ] as $tid => $_ ) {
							if ( ! isset( $first_sets[ $rule_id ][ $tid ] ) ) {
								$first_sets[ $rule_id ][ $tid ] = true;
								$changed                        = true;
							}
						}
						if ( ! $nullable[ $symbol ] ) {
							$branch_nullable = false;
							break;
						}
					}
					if ( $branch_nullable && ! $nullable[ $rule_id ] ) {
						$nullable[ $rule_id ] = true;
						$changed              = true;
					}
				}
			}
		} while ( $changed );

		// Build per-(rule, token) branch indices.
		foreach ( $rule_ids as $rule_id ) {
			$branches            = $rules[ $rule_id ];
			$selector            = array();
			$nullable_branch_ids = array();
			foreach ( $branches as $idx => $branch ) {
				$branch_first    = array();
				$branch_nullable = true;
				foreach ( $branch as $symbol ) {
					if ( $empty_rule === $symbol ) {
						continue;
					}
					if ( $symbol < $low_nt ) {
						$branch_first[ $symbol ] = true;
						$branch_nullable         = false;
						break;
					}
					foreach ( $first_sets[ $symbol ] as $tid => $_ ) {
						$branch_first[ $tid ] = true;
					}
					if ( ! $nullable[ $symbol ] ) {
						$branch_nullable = false;
						break;
					}
				}
				foreach ( $branch_first as $tid => $_ ) {
					$selector[ $tid ][] = $idx;
				}
				if ( $branch_nullable ) {
					$nullable_branch_ids[] = $idx;
				}
			}

			// Nullable branches also match when the current token is not in
			// any branch's FIRST set. Fold them into every populated entry
			// so the runtime lookup is a single array access.
			if ( $nullable_branch_ids ) {
				$merged = array();
				foreach ( $selector as $tid => $idx_list ) {
					$merged[ $tid ] = self::merge_sorted( $idx_list, $nullable_branch_ids );
				}
				$selector                            = $merged;
				$this->nullable_branches[ $rule_id ] = true;
			}
			if ( $selector ) {
				// Store the candidate branch sequences directly so the parser
				// can foreach over them without an extra $branches[$idx]
				// indirection on every branch attempt.
				foreach ( $selector as $tid => $idx_list ) {
					$seqs = array();
					foreach ( $idx_list as $idx ) {
						$seqs[] = $branches[ $idx ];
					}
					$selector[ $tid ] = $seqs;
				}
				$this->branches_for_token[ $rule_id ] = $selector;
			}
		}
	}

	/**
	 * Merge two ascending int arrays into one ascending int array without
	 * duplicates. Preserves original branch order as required by the parser.
	 *
	 * @param int[] $a
	 * @param int[] $b
	 * @return int[]
	 */
	private static function merge_sorted( array $a, array $b ): array {
		$i   = 0;
		$j   = 0;
		$na  = count( $a );
		$nb  = count( $b );
		$out = array();
		while ( $i < $na && $j < $nb ) {
			if ( $a[ $i ] < $b[ $j ] ) {
				$out[] = $a[ $i++ ];
			} elseif ( $a[ $i ] > $b[ $j ] ) {
				$out[] = $b[ $j++ ];
			} else {
				$out[] = $a[ $i ];
				++$i;
				++$j;
			}
		}
		while ( $i < $na ) {
			$out[] = $a[ $i++ ];
		}
		while ( $j < $nb ) {
			$out[] = $b[ $j++ ];
		}
		return $out;
	}
}
