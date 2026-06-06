<?php

/**
 * Experimental LALR(1) shift-reduce parser for the MySQL grammar.
 *
 * This is a table-driven bottom-up parser that consumes the ACTION/GOTO tables
 * produced by grammar-tools/build-lalr-table.php. It is an experiment to compare
 * a bottom-up parser against the backtracking LL parser in WP_Parser.
 *
 * The MySQL grammar is not LALR(1): a number of decisions (e.g. INSERT ...
 * VALUES vs. a VALUES table-value-constructor) need more than one token of
 * lookahead. The generator therefore keeps every candidate action in a cell;
 * deterministic cells hold a single integer action, while conflicted cells hold
 * a preference-ordered list. This parser follows the deterministic fast path and
 * only forks (GLR/Tomita-style, here as bounded depth-first backtracking) at
 * conflicted cells, which guarantees full grammar coverage.
 *
 * Action encoding: > 0 shift to that state; < 0 reduce by production (-id);
 * 0 accept. A cell that is an array lists alternative actions in preference
 * order.
 *
 * The produced AST mirrors WP_Parser_Node: intermediate "%" fragment rules are
 * inlined into their parent, so only original grammar rules appear as nodes.
 */
class WP_MySQL_LALR_Parser {
	/** @var array<int,array<int,int|int[]>> state => token id => action or action list */
	private $action;
	/** @var array<int,array<int,int>> state => nonterminal id => goto state */
	private $goto;
	private $plhs;
	private $plen;
	private $pname;
	private $pfrag;
	private $start;
	private $dollar;

	/** Upper bound on parser steps per query; guards against pathological forks. */
	private $budget = 2000000;
	private $steps  = 0;

	/**
	 * When true, accept the longest input prefix that forms a complete query
	 * (like the LL parser's single parse(), and the MySQL "next statement"
	 * semantics), instead of requiring the whole token stream to be consumed.
	 */
	private $prefix_mode = false;

	/**
	 * Memoizes parser configurations (state stack + input position) already
	 * proven to fail, so different fork orderings that reach the same
	 * configuration are not re-explored. This shares work the way a
	 * graph-structured stack would, turning the backtracking GLR fallback from
	 * exponential into tractable on highly ambiguous queries.
	 *
	 * @var array<string,true>
	 */
	private $fail_memo = array();

	public function __construct( array $table ) {
		$this->action = $table['action'];
		$this->goto   = $table['goto'];
		$this->plhs   = $table['plhs'];
		$this->plen   = $table['plen'];
		$this->pname  = $table['pname'];
		$this->pfrag  = $table['pfrag'];
		$this->start  = $table['start'];
		$this->dollar = $table['dollar'];
	}

	/**
	 * Parse a token stream into an AST.
	 *
	 * @param array<WP_Parser_Token> $tokens Tokens from the lexer (ending with EOF).
	 * @return WP_Parser_Node|null The AST, or null on a syntax error.
	 */
	public function set_prefix_mode( bool $on ): void {
		$this->prefix_mode = $on;
	}

	public function parse( array $tokens ) {
		$this->steps     = 0;
		$this->fail_memo = array();
		$res             = $this->run( array( $this->start ), array(), 0, $tokens, count( $tokens ) );
		return false === $res ? null : $res;
	}

	/**
	 * Drive the automaton from a given configuration. Runs deterministically
	 * until a conflicted cell is reached, then tries each candidate in order,
	 * recursing on copies of the stacks.
	 *
	 * The failure sentinel is `false`; a successful parse returns a
	 * WP_Parser_Node.
	 *
	 * @return WP_Parser_Node|false Node on accept, false on syntax error.
	 */
	private function run( array $sstack, array $nstack, $i, array $tokens, $n ) {
		while ( true ) {
			if ( ++$this->steps > $this->budget ) {
				return false;
			}
			$state = $sstack[ count( $sstack ) - 1 ];
			$la    = $i < $n ? $tokens[ $i ]->id : $this->dollar;
			$cell  = $this->action[ $state ][ $la ] ?? null;
			if ( null === $cell ) {
				// Prefix mode: treat the current position as end-of-input and try
				// to complete a query, ignoring any trailing tokens (matches the
				// LL parser's single-statement parse() and "next statement").
				if ( $this->prefix_mode && $i < $n ) {
					$i    = $n;
					$la   = $this->dollar;
					$cell = $this->action[ $state ][ $la ] ?? null;
				}
				if ( null === $cell ) {
					return false;   // syntax error
				}
			}

			if ( is_array( $cell ) ) {
				// Conflicted cell: try each candidate on a copy of the stacks.
				// Memoize failed configurations to avoid re-exploring the same
				// (state stack, position) reached via a different fork ordering.
				$key = implode( ',', $sstack ) . '#' . $i;
				if ( isset( $this->fail_memo[ $key ] ) ) {
					return false;
				}
				foreach ( $cell as $act ) {
					$res = $this->apply( $act, $sstack, $nstack, $i, $tokens, $n );
					if ( false !== $res ) {
						return $res;
					}
				}
				if ( count( $this->fail_memo ) < 2000000 ) {
					$this->fail_memo[ $key ] = true;
				}
				return false;
			}

			// Deterministic cell.
			if ( $cell > 0 ) {
				$nstack[] = $tokens[ $i ];
				$sstack[] = $cell;
				++$i;
				continue;
			}
			if ( 0 === $cell ) {
				return $nstack ? $nstack[ count( $nstack ) - 1 ] : false;
			}
			$this->reduce( -$cell, $sstack, $nstack );
		}
	}

	/**
	 * Apply a single action to (copies of) the stacks, then continue parsing.
	 * Stacks are passed by value, so each branch explores an isolated copy.
	 *
	 * @return WP_Parser_Node|false
	 */
	private function apply( $act, array $sstack, array $nstack, $i, array $tokens, $n ) {
		if ( $act > 0 ) {
			$nstack[] = $tokens[ $i ];
			$sstack[] = $act;
			return $this->run( $sstack, $nstack, $i + 1, $tokens, $n );
		}
		if ( 0 === $act ) {
			return $nstack ? $nstack[ count( $nstack ) - 1 ] : false;
		}
		$this->reduce( -$act, $sstack, $nstack );
		return $this->run( $sstack, $nstack, $i, $tokens, $n );
	}

	/**
	 * Perform a reduction by production $p, building the AST node and pushing the
	 * GOTO state.
	 */
	private function reduce( $p, array &$sstack, array &$nstack ) {
		$len = $this->plen[ $p ];
		if ( $len > 0 ) {
			$children = array_splice( $nstack, -$len );
			array_splice( $sstack, -$len );
		} else {
			$children = array();
		}

		if ( $this->pfrag[ $p ] ) {
			$payload = array();
			foreach ( $children as $c ) {
				if ( is_array( $c ) ) {
					foreach ( $c as $cc ) {
						$payload[] = $cc;
					}
				} else {
					$payload[] = $c;
				}
			}
			$nstack[] = $payload;
		} else {
			$node = new WP_Parser_Node( $this->plhs[ $p ], $this->pname[ $p ] );
			foreach ( $children as $c ) {
				if ( is_array( $c ) ) {
					foreach ( $c as $cc ) {
						$node->append_child( $cc );
					}
				} else {
					$node->append_child( $c );
				}
			}
			$nstack[] = $node;
		}

		$sstack[] = $this->goto[ $sstack[ count( $sstack ) - 1 ] ][ $this->plhs[ $p ] ];
	}
}
