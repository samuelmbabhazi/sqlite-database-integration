<?php

/**
 * Experimental LALR(1) shift-reduce parser for the MySQL grammar.
 *
 * Table-driven bottom-up parser consuming the comb-vector (displacement-packed)
 * ACTION/GOTO tables produced by grammar-tools/build-lalr-table.php. It is an
 * experiment to compare a bottom-up parser against the backtracking LL parser
 * in WP_Parser.
 *
 * Action codes (int): 0 = error; 1..NS-1 = shift to that state; NS = accept;
 * > NS = conflict (index code-NS-1 into the conflicts table); < 0 = reduce by
 * production -code.
 *
 * The grammar is not LALR(1) (e.g. INSERT ... VALUES vs a VALUES table-value-
 * constructor needs two tokens of lookahead), so conflicted cells keep all
 * candidate actions and the parser forks GLR-style, with failed-configuration
 * memoization to keep the backtracking tractable. It builds a WP_Parser_Node
 * AST with fragment inlining.
 */
class WP_MySQL_LALR_Parser {
	// ACTION comb vector.
	private $a_col;       // token id => dense column
	private $a_row;       // state => row id
	private $a_default;   // state => default reduce code (<=0)
	private $a_base;      // row id => displacement base
	private $a_check;     // slot => owning row id (or -1)
	private $a_value;     // slot => action code
	private $a_len;
	private $conflicts;   // conflict index => list of action codes

	// GOTO comb vector.
	private $g_col;       // nonterminal id => dense column
	private $g_row;       // state => row id (or -1)
	private $g_base;
	private $g_check;
	private $g_value;

	// Productions.
	private $plhs;
	private $plen;
	private $pnameidx;
	private $names;

	private $ns;
	private $start;
	private $dollar;

	/** Upper bound on parser steps per query; guards against pathological forks. */
	private $budget = 2000000;
	private $steps  = 0;

	/** Accept the longest leading statement instead of requiring full consumption. */
	private $prefix_mode = false;

	/** Memoizes failed (state stack, position) configurations across forks. */
	private $fail_memo = array();

	public function __construct( array $table ) {
		$dec = function ( $s ) {
			return '' === $s ? array() : array_values( unpack( 'l*', gzinflate( base64_decode( $s ) ) ) );
		};
		$this->ns        = $table['ns'];
		$this->start     = $table['start'];
		$this->dollar    = $table['dollar'];
		// Column maps: ordered symbol-id list -> (symbol id => column index).
		$this->a_col     = array_flip( $dec( $table['a_col'] ) );
		$this->a_row     = $dec( $table['a_row'] );
		$this->a_default = $dec( $table['a_default'] );
		$this->a_base    = $dec( $table['a_base'] );
		$this->a_check   = $dec( $table['a_check'] );
		$this->a_value   = $dec( $table['a_value'] );
		$this->a_len     = count( $this->a_check );
		// Conflict lists: length-prefixed integer stream -> list of code lists.
		$cf              = $dec( $table['conflicts'] );
		$this->conflicts = array();
		for ( $j = 0, $m = count( $cf ); $j < $m; ) {
			$len  = $cf[ $j++ ];
			$list = array();
			for ( $k = 0; $k < $len; $k++ ) {
				$list[] = $cf[ $j++ ];
			}
			$this->conflicts[] = $list;
		}
		$this->g_col     = array_flip( $dec( $table['g_col'] ) );
		$this->g_row     = $dec( $table['g_row'] );
		$this->g_base    = $dec( $table['g_base'] );
		$this->g_check   = $dec( $table['g_check'] );
		$this->g_value   = $dec( $table['g_value'] );
		$this->plhs      = $dec( $table['plhs'] );
		$this->plen      = $dec( $table['plen'] );
		$this->pnameidx  = $dec( $table['pnameidx'] );
		$this->names     = $table['names'];
	}

	public function set_prefix_mode( bool $on ): void {
		$this->prefix_mode = $on;
	}

	/**
	 * @param array<WP_Parser_Token> $tokens Tokens from the lexer (ending with EOF).
	 * @return WP_Parser_Node|null The AST, or null on a syntax error.
	 */
	public function parse( array $tokens ) {
		$this->steps     = 0;
		$this->fail_memo = array();
		$res             = $this->run( array( $this->start ), array(), 0, $tokens, count( $tokens ) );
		return false === $res ? null : $res;
	}

	/** Look up the action code for (state, token), honouring the per-state default. */
	private function action( $state, $token ) {
		if ( isset( $this->a_col[ $token ] ) ) {
			$rid = $this->a_row[ $state ];
			$idx = $this->a_base[ $rid ] + $this->a_col[ $token ];
			if ( $idx >= 0 && $idx < $this->a_len && $this->a_check[ $idx ] === $rid ) {
				return $this->a_value[ $idx ];
			}
		}
		return $this->a_default[ $state ];
	}

	/**
	 * @return WP_Parser_Node|false Node on accept, false on syntax error.
	 */
	private function run( array $sstack, array $nstack, $i, array $tokens, $n ) {
		while ( true ) {
			if ( ++$this->steps > $this->budget ) {
				return false;
			}
			$state = $sstack[ count( $sstack ) - 1 ];
			$la    = $i < $n ? $tokens[ $i ]->id : $this->dollar;
			$code  = $this->action( $state, $la );

			if ( 0 === $code ) {
				// Prefix mode: treat the current position as end-of-input.
				if ( $this->prefix_mode && $i < $n ) {
					$i    = $n;
					$code = $this->action( $state, $this->dollar );
				}
				if ( 0 === $code ) {
					return false;
				}
			}

			if ( $code > $this->ns ) {
				// Conflicted cell: try each candidate; memoize failed configurations.
				$mkey = implode( ',', $sstack ) . '#' . $i;
				if ( isset( $this->fail_memo[ $mkey ] ) ) {
					return false;
				}
				foreach ( $this->conflicts[ $code - $this->ns - 1 ] as $c ) {
					$res = $this->apply( $c, $sstack, $nstack, $i, $tokens, $n );
					if ( false !== $res ) {
						return $res;
					}
				}
				if ( count( $this->fail_memo ) < 2000000 ) {
					$this->fail_memo[ $mkey ] = true;
				}
				return false;
			}

			if ( $code > 0 ) {
				if ( $code < $this->ns ) {
					$nstack[] = $tokens[ $i ];   // shift
					$sstack[] = $code;
					++$i;
					continue;
				}
				return $nstack ? $nstack[ count( $nstack ) - 1 ] : false;   // accept
			}

			$this->reduce( -$code, $sstack, $nstack );
		}
	}

	/**
	 * Apply a single (non-conflict) action to copies of the stacks, then continue.
	 *
	 * @return WP_Parser_Node|false
	 */
	private function apply( $code, array $sstack, array $nstack, $i, array $tokens, $n ) {
		if ( $code > 0 ) {
			if ( $code < $this->ns ) {
				$nstack[] = $tokens[ $i ];
				$sstack[] = $code;
				return $this->run( $sstack, $nstack, $i + 1, $tokens, $n );
			}
			return $nstack ? $nstack[ count( $nstack ) - 1 ] : false;   // accept
		}
		$this->reduce( -$code, $sstack, $nstack );
		return $this->run( $sstack, $nstack, $i, $tokens, $n );
	}

	/** Reduce by production $p, building the AST node and pushing the GOTO state. */
	private function reduce( $p, array &$sstack, array &$nstack ) {
		$len = $this->plen[ $p ];
		if ( $len > 0 ) {
			$children = array_splice( $nstack, -$len );
			array_splice( $sstack, -$len );
		} else {
			$children = array();
		}

		$name = $this->names[ $this->pnameidx[ $p ] ];
		if ( '%' === $name[0] ) {
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
			$node = new WP_Parser_Node( $this->plhs[ $p ], $name );
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

		// GOTO on the production's lhs from the new stack top.
		$top      = $sstack[ count( $sstack ) - 1 ];
		$gid      = $this->g_row[ $top ];
		$sstack[] = $this->g_value[ $this->g_base[ $gid ] + $this->g_col[ $this->plhs[ $p ] ] ];
	}
}
