<?php
/**
 * Four parse_recursive variants exploring AST representations.
 *
 * Each variant copies the performance-branch WP_Parser hot path verbatim and
 * changes ONLY the success-result construction. Shared scaffolding (token
 * state, lazy selector build, single-candidate fast path, INTO lookahead) is
 * identical across all four so the benchmark isolates AST construction cost.
 *
 * NOTE on fidelity: the production parser splices fragment children into the
 * parent (returning a raw children array for fragments). V_Array and V_Tape
 * preserve that same splicing so node/child counts match V_Object exactly.
 */

/**
 * V_Object: the current parser, copied verbatim (the verified baseline).
 */
class WP_Variant_Parser_Object {
	protected $grammar;
	protected $tokens;
	protected $token_count;
	protected $position;
	private $rule_names;
	private $fragment_ids;
	private $branches_for_token;
	private $nullable_branches;
	private $highest_terminal_id;
	private $select_statement_rule_id;
	private $single_candidate_rules;
	private $built_rules = array();
	private $current_ast;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar                  = $grammar;
		$this->rule_names               = $grammar->rule_names;
		$this->fragment_ids             = $grammar->fragment_ids;
		$this->branches_for_token       = array();
		$this->nullable_branches        = $grammar->nullable_branches;
		$this->highest_terminal_id      = $grammar->highest_terminal_id;
		$this->single_candidate_rules   = array();
		$this->select_statement_rule_id = $grammar->get_or_cache_rule_id( 'selectStatement' );
		$this->set_tokens( $tokens );
	}

	public function reset_tokens( array $tokens ): void {
		$this->set_tokens( $tokens );
		$this->current_ast = null;
	}

	protected function set_tokens( array $tokens ): void {
		$this->token_count = count( $tokens );
		$tokens[]          = new WP_Parser_Token( WP_Parser_Grammar::EMPTY_RULE_ID, 0, 0, '' );
		$this->tokens      = $tokens;
		$this->position    = 0;
	}

	public function parse() {
		$ast = $this->parse_recursive( $this->grammar->get_or_cache_rule_id( 'query' ) );
		return false === $ast ? null : $ast;
	}

	private function parse_recursive( $rule_id ) {
		$tokens   = $this->tokens;
		$position = $this->position;

		$tid = $tokens[ $position ]->id;
		if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
			$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
		} elseif ( isset( $this->built_rules[ $rule_id ] ) ) {
			return isset( $this->nullable_branches[ $rule_id ] );
		} else {
			$this->built_rules[ $rule_id ] = true;
			$this->grammar->ensure_rule_selector( $rule_id );
			if ( isset( $this->grammar->branches_for_token[ $rule_id ] ) ) {
				$this->branches_for_token[ $rule_id ] = $this->grammar->branches_for_token[ $rule_id ];
				if ( isset( $this->grammar->single_candidate_rules[ $rule_id ] ) ) {
					$this->single_candidate_rules[ $rule_id ] = true;
				}
			}
			if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
				$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
			} else {
				return isset( $this->nullable_branches[ $rule_id ] );
			}
		}

		$highest_terminal_id = $this->highest_terminal_id;
		$is_fragment         = isset( $this->fragment_ids[ $rule_id ] );
		$is_select_statement = $rule_id === $this->select_statement_rule_id;

		if ( isset( $this->single_candidate_rules[ $rule_id ] ) ) {
			$branch   = $candidate_branches[0];
			$children = array();
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					if ( $tokens[ $this->position ]->id === $subrule_id ) {
						$children[] = $tokens[ $this->position ];
						++$this->position;
						continue;
					}
					$this->position = $position;
					return false;
				}
				$subnode = $this->parse_recursive( $subrule_id );
				if ( false === $subnode ) {
					$this->position = $position;
					return false;
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
			if ( $is_select_statement && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $this->position ]->id ) {
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
		if ( $is_fragment ) {
			return $children;
		}
		return new WP_Parser_Node( $rule_id, $this->rule_names[ $rule_id ], $children );
	}
}

/**
 * V_NoAST: pure recognition. parse_recursive never accumulates children and
 * returns true on success / false on failure, only advancing $position.
 * This is the validation-only (recognition) ceiling.
 */
class WP_Variant_Parser_NoAst {
	protected $grammar;
	protected $tokens;
	protected $token_count;
	protected $position;
	private $fragment_ids;
	private $branches_for_token;
	private $nullable_branches;
	private $highest_terminal_id;
	private $select_statement_rule_id;
	private $single_candidate_rules;
	private $built_rules = array();

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar                  = $grammar;
		$this->fragment_ids             = $grammar->fragment_ids;
		$this->branches_for_token       = array();
		$this->nullable_branches        = $grammar->nullable_branches;
		$this->highest_terminal_id      = $grammar->highest_terminal_id;
		$this->single_candidate_rules   = array();
		$this->select_statement_rule_id = $grammar->get_or_cache_rule_id( 'selectStatement' );
		$this->set_tokens( $tokens );
	}

	public function reset_tokens( array $tokens ): void {
		$this->set_tokens( $tokens );
	}

	protected function set_tokens( array $tokens ): void {
		$this->token_count = count( $tokens );
		$tokens[]          = new WP_Parser_Token( WP_Parser_Grammar::EMPTY_RULE_ID, 0, 0, '' );
		$this->tokens      = $tokens;
		$this->position    = 0;
	}

	public function parse() {
		// Returns true/false; the harness counts false as a failure.
		return $this->parse_recursive( $this->grammar->get_or_cache_rule_id( 'query' ) );
	}

	private function parse_recursive( $rule_id ) {
		$tokens   = $this->tokens;
		$position = $this->position;

		$tid = $tokens[ $position ]->id;
		if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
			$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
		} elseif ( isset( $this->built_rules[ $rule_id ] ) ) {
			return isset( $this->nullable_branches[ $rule_id ] );
		} else {
			$this->built_rules[ $rule_id ] = true;
			$this->grammar->ensure_rule_selector( $rule_id );
			if ( isset( $this->grammar->branches_for_token[ $rule_id ] ) ) {
				$this->branches_for_token[ $rule_id ] = $this->grammar->branches_for_token[ $rule_id ];
				if ( isset( $this->grammar->single_candidate_rules[ $rule_id ] ) ) {
					$this->single_candidate_rules[ $rule_id ] = true;
				}
			}
			if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
				$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
			} else {
				return isset( $this->nullable_branches[ $rule_id ] );
			}
		}

		$highest_terminal_id = $this->highest_terminal_id;
		$is_select_statement = $rule_id === $this->select_statement_rule_id;

		if ( isset( $this->single_candidate_rules[ $rule_id ] ) ) {
			$branch = $candidate_branches[0];
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					if ( $tokens[ $this->position ]->id === $subrule_id ) {
						++$this->position;
						continue;
					}
					$this->position = $position;
					return false;
				}
				$ok = $this->parse_recursive( $subrule_id );
				if ( false === $ok ) {
					$this->position = $position;
					return false;
				}
			}
			if ( $is_select_statement && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $this->position ]->id ) {
				$this->position = $position;
				return false;
			}
			return true;
		}

		$branch_matches = false;
		foreach ( $candidate_branches as $branch ) {
			$this->position = $position;
			$branch_matches = true;
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					if ( $tokens[ $this->position ]->id === $subrule_id ) {
						++$this->position;
						continue;
					}
					$branch_matches = false;
					break;
				}
				$ok = $this->parse_recursive( $subrule_id );
				if ( false === $ok ) {
					$branch_matches = false;
					break;
				}
			}
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
		return true;
	}
}

/**
 * V_Array: return array($rule_id, $children) instead of a WP_Parser_Node.
 * Children accumulation is unchanged; fragments still splice (return raw array
 * of children, distinguishable from a node by shape: node is [int, array]).
 */
class WP_Variant_Parser_Array {
	protected $grammar;
	protected $tokens;
	protected $token_count;
	protected $position;
	private $fragment_ids;
	private $branches_for_token;
	private $nullable_branches;
	private $highest_terminal_id;
	private $select_statement_rule_id;
	private $single_candidate_rules;
	private $built_rules = array();
	private $current_ast;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar                  = $grammar;
		$this->fragment_ids             = $grammar->fragment_ids;
		$this->branches_for_token       = array();
		$this->nullable_branches        = $grammar->nullable_branches;
		$this->highest_terminal_id      = $grammar->highest_terminal_id;
		$this->single_candidate_rules   = array();
		$this->select_statement_rule_id = $grammar->get_or_cache_rule_id( 'selectStatement' );
		$this->set_tokens( $tokens );
	}

	public function reset_tokens( array $tokens ): void {
		$this->set_tokens( $tokens );
		$this->current_ast = null;
	}

	protected function set_tokens( array $tokens ): void {
		$this->token_count = count( $tokens );
		$tokens[]          = new WP_Parser_Token( WP_Parser_Grammar::EMPTY_RULE_ID, 0, 0, '' );
		$this->tokens      = $tokens;
		$this->position    = 0;
	}

	public function parse() {
		$ast = $this->parse_recursive( $this->grammar->get_or_cache_rule_id( 'query' ) );
		return false === $ast ? null : $ast;
	}

	private function parse_recursive( $rule_id ) {
		$tokens   = $this->tokens;
		$position = $this->position;

		$tid = $tokens[ $position ]->id;
		if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
			$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
		} elseif ( isset( $this->built_rules[ $rule_id ] ) ) {
			return isset( $this->nullable_branches[ $rule_id ] );
		} else {
			$this->built_rules[ $rule_id ] = true;
			$this->grammar->ensure_rule_selector( $rule_id );
			if ( isset( $this->grammar->branches_for_token[ $rule_id ] ) ) {
				$this->branches_for_token[ $rule_id ] = $this->grammar->branches_for_token[ $rule_id ];
				if ( isset( $this->grammar->single_candidate_rules[ $rule_id ] ) ) {
					$this->single_candidate_rules[ $rule_id ] = true;
				}
			}
			if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
				$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
			} else {
				return isset( $this->nullable_branches[ $rule_id ] );
			}
		}

		$highest_terminal_id = $this->highest_terminal_id;
		$is_fragment         = isset( $this->fragment_ids[ $rule_id ] );
		$is_select_statement = $rule_id === $this->select_statement_rule_id;

		if ( isset( $this->single_candidate_rules[ $rule_id ] ) ) {
			$branch   = $candidate_branches[0];
			$children = array();
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					if ( $tokens[ $this->position ]->id === $subrule_id ) {
						$children[] = $tokens[ $this->position ];
						++$this->position;
						continue;
					}
					$this->position = $position;
					return false;
				}
				$subnode = $this->parse_recursive( $subrule_id );
				if ( false === $subnode ) {
					$this->position = $position;
					return false;
				}
				if ( true === $subnode ) {
					continue;
				}
				// A fragment returns a raw children array (list); a node returns
				// the [rule_id, children] tuple. Distinguish by key 0 being int.
				if ( is_array( $subnode ) && ! ( isset( $subnode[0] ) && is_int( $subnode[0] ) && isset( $subnode[1] ) && is_array( $subnode[1] ) ) ) {
					foreach ( $subnode as $c ) {
						$children[] = $c;
					}
				} else {
					$children[] = $subnode;
				}
			}
			if ( $is_select_statement && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $this->position ]->id ) {
				$this->position = $position;
				return false;
			}
			if ( ! $children ) {
				return true;
			}
			if ( $is_fragment ) {
				return $children;
			}
			return array( $rule_id, $children );
		}

		$branch_matches = false;
		$children       = array();
		foreach ( $candidate_branches as $branch ) {
			$this->position = $position;
			$children       = array();
			$branch_matches = true;
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
				if ( is_array( $subnode ) && ! ( isset( $subnode[0] ) && is_int( $subnode[0] ) && isset( $subnode[1] ) && is_array( $subnode[1] ) ) ) {
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
		if ( $is_fragment ) {
			return $children;
		}
		return array( $rule_id, $children );
	}
}

/**
 * V_Tape: a flat, append-only int tape. Each matched node appends two ints:
 *   [ rule_id, child_count ]
 * Terminals append [ -1, token_index ] (negative rule_id marks a token leaf).
 * On branch failure the tape is rolled back by truncating to the saved length
 * (array_splice), exercising the rollback cost on multi-candidate
 * rollback. parse_recursive returns the tape length consumed (an int) on
 * success so callers can roll back, true for empty matches, false on failure.
 *
 * The tape is built faithfully (every node + terminal recorded) so that the
 * rollback / truncation cost is exercised on real backtracking.
 */
class WP_Variant_Parser_Tape {
	protected $grammar;
	protected $tokens;
	protected $token_count;
	protected $position;
	private $fragment_ids;
	private $branches_for_token;
	private $nullable_branches;
	private $highest_terminal_id;
	private $select_statement_rule_id;
	private $single_candidate_rules;
	private $built_rules = array();
	private $tape;
	private $tape_len;

	public function __construct( WP_Parser_Grammar $grammar, array $tokens ) {
		$this->grammar                  = $grammar;
		$this->fragment_ids             = $grammar->fragment_ids;
		$this->branches_for_token       = array();
		$this->nullable_branches        = $grammar->nullable_branches;
		$this->highest_terminal_id      = $grammar->highest_terminal_id;
		$this->single_candidate_rules   = array();
		$this->select_statement_rule_id = $grammar->get_or_cache_rule_id( 'selectStatement' );
		$this->set_tokens( $tokens );
	}

	public function reset_tokens( array $tokens ): void {
		$this->set_tokens( $tokens );
	}

	protected function set_tokens( array $tokens ): void {
		$this->token_count = count( $tokens );
		$tokens[]          = new WP_Parser_Token( WP_Parser_Grammar::EMPTY_RULE_ID, 0, 0, '' );
		$this->tokens      = $tokens;
		$this->position    = 0;
		$this->tape        = array();
		$this->tape_len    = 0;
	}

	public function parse() {
		$this->tape     = array();
		$this->tape_len = 0;
		$res            = $this->parse_recursive( $this->grammar->get_or_cache_rule_id( 'query' ) );
		// false => failure (harness counts as failure via false return).
		return false === $res ? false : $this->tape;
	}

	/**
	 * Returns false on failure, true on a matched-but-emitted-nothing rule,
	 * or an int (the count of tape entries appended) on a successful emit.
	 */
	private function parse_recursive( $rule_id ) {
		$tokens   = $this->tokens;
		$position = $this->position;

		$tid = $tokens[ $position ]->id;
		if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
			$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
		} elseif ( isset( $this->built_rules[ $rule_id ] ) ) {
			return isset( $this->nullable_branches[ $rule_id ] );
		} else {
			$this->built_rules[ $rule_id ] = true;
			$this->grammar->ensure_rule_selector( $rule_id );
			if ( isset( $this->grammar->branches_for_token[ $rule_id ] ) ) {
				$this->branches_for_token[ $rule_id ] = $this->grammar->branches_for_token[ $rule_id ];
				if ( isset( $this->grammar->single_candidate_rules[ $rule_id ] ) ) {
					$this->single_candidate_rules[ $rule_id ] = true;
				}
			}
			if ( isset( $this->branches_for_token[ $rule_id ][ $tid ] ) ) {
				$candidate_branches = $this->branches_for_token[ $rule_id ][ $tid ];
			} else {
				return isset( $this->nullable_branches[ $rule_id ] );
			}
		}

		$highest_terminal_id = $this->highest_terminal_id;
		$is_fragment         = isset( $this->fragment_ids[ $rule_id ] );
		$is_select_statement = $rule_id === $this->select_statement_rule_id;

		if ( isset( $this->single_candidate_rules[ $rule_id ] ) ) {
			$branch          = $candidate_branches[0];
			$tape_mark       = $this->tape_len;
			$child_count     = 0;
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					if ( $tokens[ $this->position ]->id === $subrule_id ) {
						$this->tape[ $this->tape_len++ ] = -1;
						$this->tape[ $this->tape_len++ ] = $this->position;
						++$this->position;
						++$child_count;
						continue;
					}
					$this->position = $position;
					$this->rollback( $tape_mark );
					return false;
				}
				$sub = $this->parse_recursive( $subrule_id );
				if ( false === $sub ) {
					$this->position = $position;
					$this->rollback( $tape_mark );
					return false;
				}
				if ( true === $sub ) {
					continue;
				}
				// Fragment splice: a fragment's entries are already on the tape;
				// they count toward this node's children. We approximate the
				// child count by entries appended (each child = 2 ints).
				$child_count += $sub;
			}
			if ( $is_select_statement && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $this->position ]->id ) {
				$this->position = $position;
				$this->rollback( $tape_mark );
				return false;
			}
			if ( 0 === $child_count ) {
				return true;
			}
			if ( $is_fragment ) {
				// Splice: children already on tape; report the count for parent.
				return $child_count;
			}
			$this->tape[ $this->tape_len++ ] = $rule_id;
			$this->tape[ $this->tape_len++ ] = $child_count;
			return $child_count;
		}

		$branch_matches = false;
		$child_count    = 0;
		$tape_mark      = $this->tape_len;
		foreach ( $candidate_branches as $branch ) {
			$this->position       = $position;
			$this->rollback( $tape_mark );
			$child_count          = 0;
			$branch_matches       = true;
			foreach ( $branch as $subrule_id ) {
				if ( $subrule_id <= $highest_terminal_id ) {
					if ( $tokens[ $this->position ]->id === $subrule_id ) {
						$this->tape[ $this->tape_len++ ] = -1;
						$this->tape[ $this->tape_len++ ] = $this->position;
						++$this->position;
						++$child_count;
						continue;
					}
					$branch_matches = false;
					break;
				}
				$sub = $this->parse_recursive( $subrule_id );
				if ( false === $sub ) {
					$branch_matches = false;
					break;
				}
				if ( true === $sub ) {
					continue;
				}
				$child_count += $sub;
			}
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
			$this->rollback( $tape_mark );
			return false;
		}
		if ( 0 === $child_count ) {
			return true;
		}
		if ( $is_fragment ) {
			return $child_count;
		}
		$this->tape[ $this->tape_len++ ] = $rule_id;
		$this->tape[ $this->tape_len++ ] = $child_count;
		return $child_count;
	}

	private function rollback( $mark ) {
		if ( $this->tape_len > $mark ) {
			array_splice( $this->tape, $mark );
			$this->tape_len = $mark;
		}
	}
}
