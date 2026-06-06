<?php

/**
 * Multi-shape regex fast-path for the MySQL parser.
 *
 * For a curated set of common query shapes (INSERT/SELECT/UPDATE/DELETE/DROP/
 * SHOW/USE/TRUNCATE/SET/EXPLAIN/BEGIN/COMMIT/ROLLBACK), this class detects the
 * shape via a single PCRE2 pass over a codepoint-encoded token stream and then
 * builds the AST directly. The produced WP_Parser_Node tree is byte-for-byte
 * equivalent to the recursive-descent parser's output.
 *
 * On a miss, try_parse() returns null so the caller can fall back to the
 * recursive parser. The fast-path is intentionally conservative: any shape
 * that risks producing a non-equivalent AST is left to the recursive parser.
 *
 * The shape regex is built once at construction. Token-id-to-codepoint strings
 * are also pre-computed.
 */
final class WP_MySQL_Fast_Parser {
	/**
	 * Codepoint offset added to each token id to produce its UTF-8 character
	 * in the encoded stream. Chosen to land in the BMP private-use range so
	 * the encoded stream cannot collide with any meaningful Unicode codepoint.
	 */
	const TOKEN_OFFSET = 0x4000;

	/**
	 * The compiled PCRE2 union pattern. Matches one of the supported shapes
	 * and reports which one via (*MARK).
	 *
	 * @var string
	 */
	private $union_pattern;

	/**
	 * Map of shape name => builder method name.
	 *
	 * @var array<string,string>
	 */
	private $builders = array(
		'insert'   => 'build_insert',
		'drop'     => 'build_drop',
		'show'     => 'build_show',
		'select'   => 'build_select',
		'update'   => 'build_update',
		'delete'   => 'build_delete',
		'set'      => 'build_set',
		'use'      => 'build_use',
		'begin'    => 'build_begin',
		'commit'   => 'build_commit',
		'rollback' => 'build_rollback',
		'truncate' => 'build_truncate',
		'explain'  => 'build_explain',
	);

	/**
	 * Map of rule name => rule id, populated lazily.
	 *
	 * @var array<string,int>
	 */
	private $rule_ids = array();

	/**
	 * Reference to the grammar so rule ids can be resolved.
	 *
	 * @var WP_Parser_Grammar
	 */
	private $grammar;

	public function __construct( WP_Parser_Grammar $grammar ) {
		$this->grammar       = $grammar;
		$this->union_pattern = $this->build_union_pattern();
	}

	/**
	 * Encode a token stream into the codepoint string used by the union
	 * pattern. One codepoint per token at offset TOKEN_OFFSET + token_id.
	 *
	 * @param  WP_Parser_Token[] $tokens The token stream.
	 * @param  int|null          $count  Optional. Number of tokens to encode
	 *                                   (from index 0). Defaults to count($tokens).
	 * @return string                    The encoded UTF-8 string.
	 */
	public static function encode_tokens( array $tokens, ?int $count = null ): string {
		if ( null === $count ) {
			$count = count( $tokens );
		}
		$out = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$cp = $tokens[ $i ]->id + self::TOKEN_OFFSET;
			// Inline mb_chr for BMP codepoints (faster on the hot path; all
			// token ids land below U+10000).
			if ( $cp < 0x80 ) {
				$out .= chr( $cp );
			} elseif ( $cp < 0x800 ) {
				$out .= chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
			} else {
				$out .= chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
			}
		}
		return $out;
	}

	/**
	 * Try to parse the given token stream via the regex fast-path.
	 *
	 * @param  WP_Parser_Token[]   $tokens  The token stream.
	 * @param  string              $encoded The codepoint-encoded token string.
	 * @return WP_Parser_Node|null          The AST on hit, null on miss/error.
	 */
	public function try_parse( array $tokens, string $encoded ): ?WP_Parser_Node {
		if ( ! preg_match( $this->union_pattern, $encoded, $m ) ) {
			return null;
		}
		if ( ! isset( $m['MARK'] ) ) {
			return null;
		}
		$kind = $m['MARK'];
		if ( ! isset( $this->builders[ $kind ] ) ) {
			return null;
		}
		$method = $this->builders[ $kind ];
		// Builder errors fall back to the recursive parser. They should not
		// happen in practice (the regex fully validates the shape) but we
		// must not let an internal slip break parsing.
		try {
			return $this->$method( $tokens );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Build the UNION pattern that detects all supported shapes in a single
	 * preg_match. Each branch ends with `\z(*MARK:<name>)(*ACCEPT)` so the
	 * marker survives JIT short-circuit and the match anchors the end of the
	 * input.
	 */
	private function build_union_pattern(): string {
		$tc = static function ( int $tid ): string {
			return self::cp_to_utf8( $tid + self::TOKEN_OFFSET );
		};

		// Token-class shorthands.
		$ident_re     = '[' . $tc( WP_MySQL_Lexer::IDENTIFIER ) . $tc( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID ) . ']';
		$ident_re_tbl = '[' . $tc( WP_MySQL_Lexer::IDENTIFIER ) . $tc( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID ) . $tc( WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT ) . ']';
		$lit_re       = '[' . $tc( WP_MySQL_Lexer::INT_NUMBER ) . $tc( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT ) . $tc( WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT ) . $tc( WP_MySQL_Lexer::NULL_SYMBOL ) . $tc( WP_MySQL_Lexer::PARAM_MARKER ) . ']';
		$idlit_re     = '[' . $tc( WP_MySQL_Lexer::INT_NUMBER ) . $tc( WP_MySQL_Lexer::SINGLE_QUOTED_TEXT ) . $tc( WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT ) . $tc( WP_MySQL_Lexer::NULL_SYMBOL ) . $tc( WP_MySQL_Lexer::PARAM_MARKER ) . $tc( WP_MySQL_Lexer::IDENTIFIER ) . $tc( WP_MySQL_Lexer::BACK_TICK_QUOTED_ID ) . ']';

		$tail       = '(?:' . $tc( WP_MySQL_Lexer::SEMICOLON_SYMBOL ) . ')?+(?:' . $tc( WP_MySQL_Lexer::EOF ) . ')?+';
		$col_simple = $ident_re . '(?:' . $tc( WP_MySQL_Lexer::DOT_SYMBOL ) . $ident_re . ')?+';
		$tbl_re     = $ident_re_tbl . '(?:' . $tc( WP_MySQL_Lexer::DOT_SYMBOL ) . $ident_re_tbl . ')?+';
		$assign_re  = $ident_re . $tc( WP_MySQL_Lexer::EQUAL_OPERATOR ) . $idlit_re;
		$one_eq_re  = $col_simple . $tc( WP_MySQL_Lexer::EQUAL_OPERATOR ) . $idlit_re;
		$where_re   = '(?:' . $tc( WP_MySQL_Lexer::WHERE_SYMBOL ) . $one_eq_re . '(?:' . $tc( WP_MySQL_Lexer::AND_SYMBOL ) . $one_eq_re . ')*+)?+';
		$row_re     = $tc( WP_MySQL_Lexer::OPEN_PAR_SYMBOL ) . $lit_re . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $lit_re . ')*+' . $tc( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL );

		// INSERT INTO t [(cols...)] VALUES (...) [, (...)]*.
		$pat_insert = $tc( WP_MySQL_Lexer::INSERT_SYMBOL ) . $tc( WP_MySQL_Lexer::INTO_SYMBOL ) . $tbl_re
			. '(?:' . $tc( WP_MySQL_Lexer::OPEN_PAR_SYMBOL ) . $ident_re . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $ident_re . ')*+' . $tc( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL ) . ')?+'
			. $tc( WP_MySQL_Lexer::VALUES_SYMBOL )
			. $row_re . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $row_re . ')*+';

		// DROP TABLE [IF EXISTS] t1 [, t2]*.
		$pat_drop = $tc( WP_MySQL_Lexer::DROP_SYMBOL ) . $tc( WP_MySQL_Lexer::TABLE_SYMBOL )
			. '(?:' . $tc( WP_MySQL_Lexer::IF_SYMBOL ) . $tc( WP_MySQL_Lexer::EXISTS_SYMBOL ) . ')?+'
			. $tbl_re . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $tbl_re . ')*+';

		// SHOW family.
		$show_bare_kw    = '[' . $tc( WP_MySQL_Lexer::TABLES_SYMBOL ) . $tc( WP_MySQL_Lexer::DATABASES_SYMBOL ) . $tc( WP_MySQL_Lexer::VARIABLES_SYMBOL )
			. $tc( WP_MySQL_Lexer::STATUS_SYMBOL ) . $tc( WP_MySQL_Lexer::WARNINGS_SYMBOL ) . $tc( WP_MySQL_Lexer::ERRORS_SYMBOL )
			. $tc( WP_MySQL_Lexer::EVENTS_SYMBOL ) . $tc( WP_MySQL_Lexer::TRIGGERS_SYMBOL ) . $tc( WP_MySQL_Lexer::PLUGINS_SYMBOL )
			. $tc( WP_MySQL_Lexer::GRANTS_SYMBOL ) . ']';
		$show_optype_kw  = '[' . $tc( WP_MySQL_Lexer::SESSION_SYMBOL ) . $tc( WP_MySQL_Lexer::GLOBAL_SYMBOL ) . ']';
		$show_optype_var = '[' . $tc( WP_MySQL_Lexer::VARIABLES_SYMBOL ) . $tc( WP_MySQL_Lexer::STATUS_SYMBOL ) . ']';
		$show_keys_kw    = '[' . $tc( WP_MySQL_Lexer::KEYS_SYMBOL ) . $tc( WP_MySQL_Lexer::INDEX_SYMBOL ) . $tc( WP_MySQL_Lexer::INDEXES_SYMBOL ) . ']';
		$show_pf_kw      = '[' . $tc( WP_MySQL_Lexer::PROCEDURE_SYMBOL ) . $tc( WP_MySQL_Lexer::FUNCTION_SYMBOL ) . ']';

		$pat_show = $tc( WP_MySQL_Lexer::SHOW_SYMBOL ) . '(?:'
			. '(?:' . $tc( WP_MySQL_Lexer::CREATE_SYMBOL ) . $tc( WP_MySQL_Lexer::TABLE_SYMBOL ) . $tbl_re . ')'
			. '|(?:' . $tc( WP_MySQL_Lexer::CREATE_SYMBOL ) . $tc( WP_MySQL_Lexer::DATABASE_SYMBOL ) . $ident_re . ')'
			. '|(?:' . $tc( WP_MySQL_Lexer::CREATE_SYMBOL ) . $show_pf_kw . $tbl_re . ')'
			. '|(?:' . $show_optype_kw . $show_optype_var . ')'
			. '|(?:' . $show_pf_kw . $tc( WP_MySQL_Lexer::STATUS_SYMBOL ) . ')'
			. '|(?:' . $tc( WP_MySQL_Lexer::COLUMNS_SYMBOL ) . $tc( WP_MySQL_Lexer::FROM_SYMBOL ) . $tbl_re . ')'
			. '|(?:' . $show_keys_kw . $tc( WP_MySQL_Lexer::FROM_SYMBOL ) . $tbl_re . ')'
			. '|(?:' . $show_bare_kw . ')'
			. ')';

		// SELECT (* | col [AS x] [, col [AS x]]*) FROM t [AS x] [, t [AS x]]*
		//        [WHERE ...] [ORDER BY ...] [LIMIT ...].
		$select_alias_re = '(?:' . $tc( WP_MySQL_Lexer::AS_SYMBOL ) . '?+' . $ident_re . ')?+';
		$one_select_item = $col_simple . $select_alias_re;
		$select_items    = '(?:' . $tc( WP_MySQL_Lexer::MULT_OPERATOR ) . '|' . $one_select_item
			. '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $one_select_item . ')*+)';
		$tbl_alias_re    = '(?:' . $tc( WP_MySQL_Lexer::AS_SYMBOL ) . '?+' . $ident_re . ')?+';
		$one_tbl         = $tbl_re . $tbl_alias_re;
		$tbl_list        = $one_tbl . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $one_tbl . ')*+';
		$one_order_item  = $col_simple . '(?:[' . $tc( WP_MySQL_Lexer::ASC_SYMBOL ) . $tc( WP_MySQL_Lexer::DESC_SYMBOL ) . '])?+';
		$order_re        = '(?:' . $tc( WP_MySQL_Lexer::ORDER_SYMBOL ) . $tc( WP_MySQL_Lexer::BY_SYMBOL ) . $one_order_item
			. '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $one_order_item . ')*+)?+';
		$limit_re        = '(?:' . $tc( WP_MySQL_Lexer::LIMIT_SYMBOL ) . $tc( WP_MySQL_Lexer::INT_NUMBER ) . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $tc( WP_MySQL_Lexer::INT_NUMBER ) . ')?+)?+';
		$pat_select      = $tc( WP_MySQL_Lexer::SELECT_SYMBOL ) . $select_items . $tc( WP_MySQL_Lexer::FROM_SYMBOL ) . $tbl_list
			. $where_re . $order_re . $limit_re;

		// UPDATE t SET c=v [, c=v]* [WHERE ...].
		$pat_update = $tc( WP_MySQL_Lexer::UPDATE_SYMBOL ) . $tbl_re . $tc( WP_MySQL_Lexer::SET_SYMBOL )
			. $assign_re . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $assign_re . ')*+'
			. $where_re;

		// DELETE FROM t [WHERE ...].
		$pat_delete = $tc( WP_MySQL_Lexer::DELETE_SYMBOL ) . $tc( WP_MySQL_Lexer::FROM_SYMBOL ) . $tbl_re . $where_re;

		// SET — three forms: optionType-prefixed, no-optionType (ident= / @x= / @@var=).
		$set_var_no_opt    = '(?:' . $ident_re . '|' . $tc( WP_MySQL_Lexer::AT_TEXT_SUFFIX )
			. '|' . $tc( WP_MySQL_Lexer::AT_AT_SIGN_SYMBOL )
				. '(?:[' . $tc( WP_MySQL_Lexer::GLOBAL_SYMBOL ) . $tc( WP_MySQL_Lexer::SESSION_SYMBOL ) . ']' . $tc( WP_MySQL_Lexer::DOT_SYMBOL ) . ')?+'
				. $ident_re . ')';
		$set_assign_no_opt = $set_var_no_opt . $tc( WP_MySQL_Lexer::EQUAL_OPERATOR ) . $idlit_re;
		$set_optype_kw     = '[' . $tc( WP_MySQL_Lexer::GLOBAL_SYMBOL ) . $tc( WP_MySQL_Lexer::SESSION_SYMBOL ) . $tc( WP_MySQL_Lexer::PERSIST_SYMBOL ) . $tc( WP_MySQL_Lexer::PERSIST_ONLY_SYMBOL ) . ']';
		$set_assign_optype = $ident_re . $tc( WP_MySQL_Lexer::EQUAL_OPERATOR ) . $idlit_re;
		$pat_set           = $tc( WP_MySQL_Lexer::SET_SYMBOL )
			. '(?:'
				. '(?:' . $set_optype_kw . $set_assign_optype . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $set_assign_optype . ')*+)'
				. '|(?:' . $set_assign_no_opt . '(?:' . $tc( WP_MySQL_Lexer::COMMA_SYMBOL ) . $set_assign_no_opt . ')*+)'
			. ')';

		$pat_use      = $tc( WP_MySQL_Lexer::USE_SYMBOL ) . $ident_re;
		$pat_begin    = $tc( WP_MySQL_Lexer::BEGIN_SYMBOL ) . '(?:' . $tc( WP_MySQL_Lexer::WORK_SYMBOL ) . ')?+';
		$pat_commit   = $tc( WP_MySQL_Lexer::COMMIT_SYMBOL );
		$pat_rollback = $tc( WP_MySQL_Lexer::ROLLBACK_SYMBOL );
		$pat_truncate = $tc( WP_MySQL_Lexer::TRUNCATE_SYMBOL ) . '(?:' . $tc( WP_MySQL_Lexer::TABLE_SYMBOL ) . ')?+' . $tbl_re;
		$pat_explain  = $tc( WP_MySQL_Lexer::EXPLAIN_SYMBOL ) . $pat_select;

		// More-specific shapes first. EXPLAIN before SELECT.
		$shapes = array(
			'explain'  => $pat_explain,
			'insert'   => $pat_insert,
			'select'   => $pat_select,
			'update'   => $pat_update,
			'delete'   => $pat_delete,
			'drop'     => $pat_drop,
			'show'     => $pat_show,
			'set'      => $pat_set,
			'use'      => $pat_use,
			'begin'    => $pat_begin,
			'commit'   => $pat_commit,
			'rollback' => $pat_rollback,
			'truncate' => $pat_truncate,
		);

		$alts = array();
		foreach ( $shapes as $name => $pat ) {
			// Each branch must end with \z BEFORE (*ACCEPT). Without \z, e.g.
			// `DELETE FROM t WHERE a=1 or a=5` would match the delete shape
			// up to `a=1` and accept, ignoring the remainder.
			$alts[] = '(?:' . $pat . $tail . '\z(*MARK:' . $name . ')(*ACCEPT))';
		}
		return '/\A(?:' . implode( '|', $alts ) . ')/u';
	}

	/**
	 * Encode a single Unicode codepoint as UTF-8 bytes (BMP only).
	 */
	private static function cp_to_utf8( int $cp ): string {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	// ============================================================
	// AST node construction.
	// ============================================================

	/**
	 * Build a WP_Parser_Node, resolving the rule id once and caching it.
	 */
	private function node( string $rule_name, array $children ): WP_Parser_Node {
		if ( ! isset( $this->rule_ids[ $rule_name ] ) ) {
			$this->rule_ids[ $rule_name ] = $this->grammar->get_rule_id( $rule_name );
		}
		return new WP_Parser_Node( $this->rule_ids[ $rule_name ], $rule_name, $children );
	}

	// ============================================================
	// AST building primitives shared across shapes.
	// ============================================================

	/**
	 * literal subtree from a single token.
	 */
	private function lit( WP_Parser_Token $tok ): WP_Parser_Node {
		switch ( $tok->id ) {
			case WP_MySQL_Lexer::INT_NUMBER:
				return $this->node( 'numLiteral', array( $tok ) );
			case WP_MySQL_Lexer::SINGLE_QUOTED_TEXT:
			case WP_MySQL_Lexer::DOUBLE_QUOTED_TEXT:
				return $this->node( 'textLiteral', array( $this->node( 'textStringLiteral', array( $tok ) ) ) );
			case WP_MySQL_Lexer::NULL_SYMBOL:
				return $this->node( 'nullLiteral', array( $tok ) );
		}
		throw new RuntimeException( 'fast-path: unsupported literal token id ' . $tok->id );
	}

	/**
	 * expr wrapping a literal — produces the identity-spine
	 * expr->boolPri->predicate->bitExpr->simpleExpr->simpleExprBody->literal.
	 */
	private function expr_wrap_lit( WP_Parser_Token $tok ): WP_Parser_Node {
		$body = $this->node( 'simpleExprBody', array( $this->node( 'literal', array( $this->lit( $tok ) ) ) ) );
		return $this->expr_identity_spine( $body );
	}

	/**
	 * expr wrapping a column reference.
	 */
	private function expr_wrap_col( WP_Parser_Token $a, ?WP_Parser_Token $dot = null, ?WP_Parser_Token $b = null ): WP_Parser_Node {
		$body = $this->node( 'simpleExprBody', array( $this->column_ref( $a, $dot, $b ) ) );
		return $this->expr_identity_spine( $body );
	}

	/**
	 * expr that may be a column ref, paramMarker, or literal — used for the
	 * RHS of c=v in UPDATE/SET.
	 */
	private function expr_for_rhs( WP_Parser_Token $tok ): WP_Parser_Node {
		if ( WP_MySQL_Lexer::IDENTIFIER === $tok->id || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $tok->id ) {
			return $this->expr_wrap_col( $tok );
		}
		if ( WP_MySQL_Lexer::PARAM_MARKER === $tok->id ) {
			$body = $this->node( 'simpleExprBody', array( $this->node( 'paramMarker', array( $tok ) ) ) );
			return $this->expr_identity_spine( $body );
		}
		return $this->expr_wrap_lit( $tok );
	}

	/**
	 * Wrap a simpleExprBody in the identity spine to produce an `expr` node.
	 */
	private function expr_identity_spine( WP_Parser_Node $body ): WP_Parser_Node {
		return $this->node(
			'expr',
			array(
				$this->node(
					'boolPri',
					array(
						$this->node(
							'predicate',
							array(
								$this->node(
									'bitExpr',
									array(
										$this->node( 'simpleExpr', array( $body ) ),
									)
								),
							)
						),
					)
				),
			)
		);
	}

	/**
	 * qualifiedIdentifier { identifier { pure } [dotIdentifier { . identifier { pure } }] }.
	 */
	private function qualified_ident( WP_Parser_Token $a, ?WP_Parser_Token $dot = null, ?WP_Parser_Token $b = null ): WP_Parser_Node {
		$kids = array( $this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $a ) ) ) ) );
		if ( null !== $dot ) {
			$kids[] = $this->node(
				'dotIdentifier',
				array(
					$dot,
					$this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $b ) ) ) ),
				)
			);
		}
		return $this->node( 'qualifiedIdentifier', $kids );
	}

	/**
	 * columnRef wrapping a (possibly qualified) identifier.
	 */
	private function column_ref( WP_Parser_Token $a, ?WP_Parser_Token $dot = null, ?WP_Parser_Token $b = null ): WP_Parser_Node {
		return $this->node(
			'columnRef',
			array(
				$this->node( 'fieldIdentifier', array( $this->qualified_ident( $a, $dot, $b ) ) ),
			)
		);
	}

	/**
	 * tableRef wrapping a (possibly qualified) identifier.
	 */
	private function table_ref( WP_Parser_Token $a, ?WP_Parser_Token $dot = null, ?WP_Parser_Token $b = null ): WP_Parser_Node {
		return $this->node( 'tableRef', array( $this->qualified_ident( $a, $dot, $b ) ) );
	}

	/**
	 * Read tokens[$i..]: returns [tableRef_node, next_i] handling optional db.t form.
	 *
	 * @return array{0:WP_Parser_Node,1:int}
	 */
	private function consume_table_ref( array $tokens, int $i ): array {
		$a = $tokens[ $i ];
		if ( WP_MySQL_Lexer::DOT_SYMBOL === ( $tokens[ $i + 1 ]->id ?? 0 ) ) {
			$dot = $tokens[ $i + 1 ];
			$b   = $tokens[ $i + 2 ];
			return array( $this->table_ref( $a, $dot, $b ), $i + 3 );
		}
		return array( $this->table_ref( $a ), $i + 1 );
	}

	/**
	 * Read a SELECT FROM-list item with optional [AS] alias.
	 *
	 * @return array{0:WP_Parser_Node,1:int}
	 */
	private function consume_table_reference( array $tokens, int $i ): array {
		list( $tref, $i ) = $this->consume_table_ref( $tokens, $i );
		$st_kids          = array( $tref );

		if ( $i < count( $tokens ) ) {
			$as_tok = null;
			$j      = $i;
			if ( WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $j ]->id ) {
				$as_tok = $tokens[ $j ];
				++$j;
			}
			if ( $j < count( $tokens ) && ( WP_MySQL_Lexer::IDENTIFIER === $tokens[ $j ]->id || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $tokens[ $j ]->id ) ) {
				$alias_kids = array();
				if ( null !== $as_tok ) {
					$alias_kids[] = $as_tok;
				}
				$alias_kids[] = $this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $tokens[ $j ] ) ) ) );
				$st_kids[]    = $this->node( 'tableAlias', $alias_kids );
				$i            = $j + 1;
			}
		}

		$single = $this->node( 'singleTable', $st_kids );
		$tref_n = $this->node( 'tableReference', array( $this->node( 'tableFactor', array( $single ) ) ) );
		return array( $tref_n, $i );
	}

	/**
	 * Comparison expr `lhs <eq> rhs` for WHERE/ON clauses.
	 *
	 * Produces:
	 *   expr → boolPri { predicate { ...lhs }, compOp { = }, predicate { ...rhs } }.
	 */
	private function expr_eq( WP_Parser_Node $colref, WP_Parser_Token $eq, WP_Parser_Token $rhs ): WP_Parser_Node {
		$lhs_pred = $this->node(
			'predicate',
			array(
				$this->node(
					'bitExpr',
					array(
						$this->node(
							'simpleExpr',
							array(
								$this->node( 'simpleExprBody', array( $colref ) ),
							)
						),
					)
				),
			)
		);

		if ( WP_MySQL_Lexer::IDENTIFIER === $rhs->id || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $rhs->id ) {
			$rhs_inner = $this->column_ref( $rhs );
		} elseif ( WP_MySQL_Lexer::PARAM_MARKER === $rhs->id ) {
			$rhs_inner = $this->node( 'paramMarker', array( $rhs ) );
		} else {
			$rhs_inner = $this->node( 'literal', array( $this->lit( $rhs ) ) );
		}

		$rhs_pred = $this->node(
			'predicate',
			array(
				$this->node(
					'bitExpr',
					array(
						$this->node(
							'simpleExpr',
							array(
								$this->node( 'simpleExprBody', array( $rhs_inner ) ),
							)
						),
					)
				),
			)
		);

		return $this->node(
			'expr',
			array(
				$this->node( 'boolPri', array( $lhs_pred, $this->node( 'compOp', array( $eq ) ), $rhs_pred ) ),
			)
		);
	}

	/**
	 * Consume `col[.col] = rhs`. Returns [expr_node, next_i].
	 *
	 * @return array{0:WP_Parser_Node,1:int}
	 */
	private function consume_one_eq( array $tokens, int $i ): array {
		$col_a = $tokens[ $i ];
		$dot   = null;
		$col_b = null;
		$j     = $i + 1;
		if ( WP_MySQL_Lexer::DOT_SYMBOL === ( $tokens[ $j ]->id ?? 0 ) ) {
			$dot   = $tokens[ $j ];
			$col_b = $tokens[ $j + 1 ];
			$j    += 2;
		}
		$colref = $this->column_ref( $col_a, $dot, $col_b );
		$eq     = $tokens[ $j ];
		$rhs    = $tokens[ $j + 1 ];
		return array( $this->expr_eq( $colref, $eq, $rhs ), $j + 2 );
	}

	/**
	 * Optional `WHERE col[.col]=rhs [AND col[.col]=rhs]*`.
	 *
	 * AND-chains fold right-associatively:
	 *   expr { boolPri c1, AND, expr { boolPri c2, AND, expr { boolPri c3 } } }.
	 *
	 * @return array{0:WP_Parser_Node|null,1:int}
	 */
	private function maybe_where( array $tokens, int $i ): array {
		if ( $i >= count( $tokens ) || WP_MySQL_Lexer::WHERE_SYMBOL !== $tokens[ $i ]->id ) {
			return array( null, $i );
		}
		$where_tok = $tokens[ $i ];
		$j         = $i + 1;

		list( $first, $j ) = $this->consume_one_eq( $tokens, $j );
		$comparisons       = array( $first );
		$ands              = array();
		while ( $j < count( $tokens ) && WP_MySQL_Lexer::AND_SYMBOL === $tokens[ $j ]->id ) {
			$ands[] = $tokens[ $j ];
			++$j;
			list( $next, $j ) = $this->consume_one_eq( $tokens, $j );
			$comparisons[]    = $next;
		}

		$expr = end( $comparisons );
		for ( $k = count( $comparisons ) - 2; $k >= 0; $k-- ) {
			// Unwrap expr->boolPri so we can recombine with the AND on the right.
			$boolpri_kids = $comparisons[ $k ]->get_children_ref();
			$boolpri      = $boolpri_kids[0];
			$expr         = $this->node( 'expr', array( $boolpri, $ands[ $k ], $expr ) );
		}
		return array( $this->node( 'whereClause', array( $where_tok, $expr ) ), $j );
	}

	/**
	 * Consume an ORDER BY item: `col[.col] [ASC|DESC]`.
	 *
	 * @return array{0:WP_Parser_Node,1:int}
	 */
	private function consume_order_item( array $tokens, int $i ): array {
		$col   = $tokens[ $i ];
		$dot   = null;
		$col_b = null;
		$j     = $i + 1;
		if ( WP_MySQL_Lexer::DOT_SYMBOL === ( $tokens[ $j ]->id ?? 0 ) ) {
			$dot   = $tokens[ $j ];
			$col_b = $tokens[ $j + 1 ];
			$j    += 2;
		}
		$kids = array( $this->expr_wrap_col( $col, $dot, $col_b ) );
		if ( $j < count( $tokens )
			&& ( WP_MySQL_Lexer::ASC_SYMBOL === $tokens[ $j ]->id || WP_MySQL_Lexer::DESC_SYMBOL === $tokens[ $j ]->id )
		) {
			$kids[] = $this->node( 'direction', array( $tokens[ $j ] ) );
			++$j;
		}
		return array( $this->node( 'orderExpression', $kids ), $j );
	}

	/**
	 * Wrap a `simpleStatement`/`utilityStatement`/etc. node and any trailing
	 * `;`/EOF tokens into a top-level `query` node.
	 */
	private function with_tail( WP_Parser_Node $simple_stmt, array $tokens, int $start_i ): WP_Parser_Node {
		$kids = array( $simple_stmt );
		for ( $j = $start_i, $n = count( $tokens ); $j < $n; $j++ ) {
			$kids[] = $tokens[ $j ];
		}
		return $this->node( 'query', $kids );
	}

	// ============================================================
	// Per-shape AST builders.
	// ============================================================

	private function build_insert( array $tokens ): WP_Parser_Node {
		$insert_tok      = $tokens[0];
		$into_tok        = $tokens[1];
		list( $tbl, $i ) = $this->consume_table_ref( $tokens, 2 );

		$ifc_kids    = array();
		$has_collist = WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $i ]->id;
		$i_values    = $i;
		if ( $has_collist ) {
			$open_i = $i;
			$j      = $open_i + 1;
			while ( WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[ $j ]->id ) {
				++$j;
			}
			$close_i    = $j;
			$ifc_kids[] = $tokens[ $open_i ];
			$ifc_kids[] = $this->insert_fields_node( $tokens, $open_i, $close_i );
			$ifc_kids[] = $tokens[ $close_i ];
			$i_values   = $close_i + 1;
		}

		// Find the end of the values clause (first SEMICOLON/EOF or end-of-array).
		$end = $i_values + 1;
		$n   = count( $tokens );
		while ( $end < $n && WP_MySQL_Lexer::SEMICOLON_SYMBOL !== $tokens[ $end ]->id && WP_MySQL_Lexer::EOF !== $tokens[ $end ]->id ) {
			++$end;
		}
		$ifc_kids[] = $this->insert_values_node( $tokens, $i_values, $end );
		$ifc        = $this->node( 'insertFromConstructor', $ifc_kids );

		$insert_stmt = $this->node( 'insertStatement', array( $insert_tok, $into_tok, $tbl, $ifc ) );
		return $this->with_tail( $this->node( 'simpleStatement', array( $insert_stmt ) ), $tokens, $end );
	}

	/**
	 * fields { insertIdentifier { columnRef { ... } } [, insertIdentifier { ... }]* }.
	 */
	private function insert_fields_node( array $tokens, int $i_open, int $i_close ): WP_Parser_Node {
		$kids = array();
		for ( $i = $i_open + 1; $i < $i_close; $i++ ) {
			if ( WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
				$kids[] = $tokens[ $i ];
				continue;
			}
			$kids[] = $this->node( 'insertIdentifier', array( $this->column_ref( $tokens[ $i ] ) ) );
		}
		return $this->node( 'fields', $kids );
	}

	/**
	 * insertValues { VALUES, valueList { ( values ) [, ( values )]* } }.
	 */
	private function insert_values_node( array $tokens, int $i_values, int $i_end_excl ): WP_Parser_Node {
		$values_tok = $tokens[ $i_values ];
		$vl_kids    = array();
		$i          = $i_values + 1;
		while ( $i < $i_end_excl && WP_MySQL_Lexer::OPEN_PAR_SYMBOL === $tokens[ $i ]->id ) {
			$open_i = $i;
			++$i;
			while ( $i < $i_end_excl && WP_MySQL_Lexer::CLOSE_PAR_SYMBOL !== $tokens[ $i ]->id ) {
				++$i;
			}
			$close_i   = $i;
			$vl_kids[] = $tokens[ $open_i ];
			$vl_kids[] = $this->values_node( $tokens, $open_i, $close_i );
			$vl_kids[] = $tokens[ $close_i ];
			++$i;
			if ( $i < $i_end_excl && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
				$vl_kids[] = $tokens[ $i ];
				++$i;
			}
		}
		return $this->node( 'insertValues', array( $values_tok, $this->node( 'valueList', $vl_kids ) ) );
	}

	/**
	 * values { expr [, expr]* } — for one (lit, lit, ...) row.
	 */
	private function values_node( array $tokens, int $i_open, int $i_close ): WP_Parser_Node {
		$kids = array();
		for ( $i = $i_open + 1; $i < $i_close; $i++ ) {
			if ( WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
				$kids[] = $tokens[ $i ];
				continue;
			}
			$kids[] = $this->expr_wrap_lit( $tokens[ $i ] );
		}
		return $this->node( 'values', $kids );
	}

	private function build_drop( array $tokens ): WP_Parser_Node {
		$drop_tok  = $tokens[0];
		$table_tok = $tokens[1];
		$dt_kids   = array( $table_tok );
		$i         = 2;
		if ( WP_MySQL_Lexer::IF_SYMBOL === $tokens[ $i ]->id ) {
			$dt_kids[] = $this->node( 'ifExists', array( $tokens[ $i ], $tokens[ $i + 1 ] ) );
			$i        += 2;
		}
		list( $tref, $i ) = $this->consume_table_ref( $tokens, $i );
		$tref_kids        = array( $tref );
		while ( $i < count( $tokens ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
			$tref_kids[] = $tokens[ $i ];
			++$i;
			list( $tref2, $i ) = $this->consume_table_ref( $tokens, $i );
			$tref_kids[]       = $tref2;
		}
		$dt_kids[] = $this->node( 'tableRefList', $tref_kids );
		$drop_stmt = $this->node( 'dropStatement', array( $drop_tok, $this->node( 'dropTable', $dt_kids ) ) );
		return $this->with_tail( $this->node( 'simpleStatement', array( $drop_stmt ) ), $tokens, $i );
	}

	private function build_show( array $tokens ): WP_Parser_Node {
		$show_tok = $tokens[0];
		$t1       = $tokens[1]->id;

		if ( WP_MySQL_Lexer::CREATE_SYMBOL === $t1 ) {
			$t2 = $tokens[2]->id;
			if ( WP_MySQL_Lexer::TABLE_SYMBOL === $t2 ) {
				list( $tref, $end ) = $this->consume_table_ref( $tokens, 3 );
				$ss                 = $this->node( 'showStatement', array( $show_tok, $tokens[1], $tokens[2], $tref ) );
			} elseif ( WP_MySQL_Lexer::DATABASE_SYMBOL === $t2 ) {
				$schema_ref = $this->node(
					'schemaRef',
					array(
						$this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $tokens[3] ) ) ) ),
					)
				);
				$ss         = $this->node( 'showStatement', array( $show_tok, $tokens[1], $tokens[2], $schema_ref ) );
				$end        = 4;
			} else {
				// PROCEDURE | FUNCTION — produce procedureRef/functionRef wrapping
				// the same qualifiedIdentifier as a tableRef would.
				list( $tref, $end ) = $this->consume_table_ref( $tokens, 3 );
				$tref_kids          = $tref->get_children_ref();
				$ref_name           = ( WP_MySQL_Lexer::PROCEDURE_SYMBOL === $t2 ) ? 'procedureRef' : 'functionRef';
				$ref                = $this->node( $ref_name, $tref_kids );
				$ss                 = $this->node( 'showStatement', array( $show_tok, $tokens[1], $tokens[2], $ref ) );
			}
		} elseif ( WP_MySQL_Lexer::SESSION_SYMBOL === $t1 || WP_MySQL_Lexer::GLOBAL_SYMBOL === $t1 ) {
			$opt = $this->node( 'optionType', array( $tokens[1] ) );
			$ss  = $this->node( 'showStatement', array( $show_tok, $opt, $tokens[2] ) );
			$end = 3;
		} elseif ( WP_MySQL_Lexer::PROCEDURE_SYMBOL === $t1 || WP_MySQL_Lexer::FUNCTION_SYMBOL === $t1 ) {
			$ss  = $this->node( 'showStatement', array( $show_tok, $tokens[1], $tokens[2] ) );
			$end = 3;
		} elseif ( WP_MySQL_Lexer::COLUMNS_SYMBOL === $t1 ) {
			list( $tref, $end ) = $this->consume_table_ref( $tokens, 3 );
			$ss                 = $this->node( 'showStatement', array( $show_tok, $tokens[1], $tokens[2], $tref ) );
		} elseif ( WP_MySQL_Lexer::KEYS_SYMBOL === $t1 || WP_MySQL_Lexer::INDEX_SYMBOL === $t1 || WP_MySQL_Lexer::INDEXES_SYMBOL === $t1 ) {
			$from_or_in         = $this->node( 'fromOrIn', array( $tokens[2] ) );
			list( $tref, $end ) = $this->consume_table_ref( $tokens, 3 );
			$ss                 = $this->node( 'showStatement', array( $show_tok, $tokens[1], $from_or_in, $tref ) );
		} else {
			$ss  = $this->node( 'showStatement', array( $show_tok, $tokens[1] ) );
			$end = 2;
		}

		return $this->with_tail( $this->node( 'simpleStatement', array( $ss ) ), $tokens, $end );
	}

	private function build_use( array $tokens ): WP_Parser_Node {
		$use_cmd = $this->node(
			'useCommand',
			array(
				$tokens[0],
				$this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $tokens[1] ) ) ) ),
			)
		);
		$util    = $this->node( 'utilityStatement', array( $use_cmd ) );
		return $this->with_tail( $this->node( 'simpleStatement', array( $util ) ), $tokens, 2 );
	}

	private function build_begin( array $tokens ): WP_Parser_Node {
		$bw_kids = array( $tokens[0] );
		$i       = 1;
		if ( WP_MySQL_Lexer::WORK_SYMBOL === ( $tokens[1]->id ?? 0 ) ) {
			$bw_kids[] = $tokens[1];
			$i         = 2;
		}
		// BEGIN is the second alternative of `query`: `query → ... | beginWork tail`.
		$kids = array( $this->node( 'beginWork', $bw_kids ) );
		for ( $j = $i, $n = count( $tokens ); $j < $n; $j++ ) {
			$kids[] = $tokens[ $j ];
		}
		return $this->node( 'query', $kids );
	}

	private function build_commit( array $tokens ): WP_Parser_Node {
		$tx  = $this->node( 'transactionStatement', array( $tokens[0] ) );
		$txl = $this->node( 'transactionOrLockingStatement', array( $tx ) );
		return $this->with_tail( $this->node( 'simpleStatement', array( $txl ) ), $tokens, 1 );
	}

	private function build_rollback( array $tokens ): WP_Parser_Node {
		$sp  = $this->node( 'savepointStatement', array( $tokens[0] ) );
		$txl = $this->node( 'transactionOrLockingStatement', array( $sp ) );
		return $this->with_tail( $this->node( 'simpleStatement', array( $txl ) ), $tokens, 1 );
	}

	private function build_truncate( array $tokens ): WP_Parser_Node {
		$kids = array( $tokens[0] );
		$i    = 1;
		if ( WP_MySQL_Lexer::TABLE_SYMBOL === $tokens[1]->id ) {
			$kids[] = $tokens[1];
			$i      = 2;
		}
		list( $tref, $i ) = $this->consume_table_ref( $tokens, $i );
		$kids[]           = $tref;
		$tt               = $this->node( 'truncateTableStatement', $kids );
		return $this->with_tail( $this->node( 'simpleStatement', array( $tt ) ), $tokens, $i );
	}

	/**
	 * SET — three forms (optionType-prefixed, no-optionType, plus combinations).
	 */
	private function build_set( array $tokens ): WP_Parser_Node {
		$set_tok = $tokens[0];
		$i       = 1;
		$t1      = $tokens[ $i ]->id;

		if ( WP_MySQL_Lexer::GLOBAL_SYMBOL === $t1 || WP_MySQL_Lexer::SESSION_SYMBOL === $t1
			|| WP_MySQL_Lexer::PERSIST_SYMBOL === $t1 || WP_MySQL_Lexer::PERSIST_ONLY_SYMBOL === $t1
		) {
			// optionType form: first assignment is optionValueFollowingOptionType,
			// subsequent assignments use optionValueListContinued (with
			// optionValueNoOptionType inner).
			$opt_type = $this->node( 'optionType', array( $tokens[ $i ] ) );
			++$i;
			list( $first, $i ) = $this->build_set_option_following( $tokens, $i );
			$following_kids    = array( $first );
			if ( $i < count( $tokens ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
				$cont_kids = array();
				while ( $i < count( $tokens ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
					$cont_kids[] = $tokens[ $i ];
					++$i;
					list( $opt, $i ) = $this->build_set_option_no_type( $tokens, $i );
					$cont_kids[]     = $this->node( 'optionValue', array( $opt ) );
				}
				$following_kids[] = $this->node( 'optionValueListContinued', $cont_kids );
			}
			$following = $this->node( 'startOptionValueListFollowingOptionType', $following_kids );
			$start     = $this->node( 'startOptionValueList', array( $opt_type, $following ) );
		} else {
			list( $first, $i ) = $this->build_set_option_no_type( $tokens, $i );
			$start_kids        = array( $first );
			if ( $i < count( $tokens ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
				$cont_kids = array();
				while ( $i < count( $tokens ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
					$cont_kids[] = $tokens[ $i ];
					++$i;
					list( $opt, $i ) = $this->build_set_option_no_type( $tokens, $i );
					$cont_kids[]     = $this->node( 'optionValue', array( $opt ) );
				}
				$start_kids[] = $this->node( 'optionValueListContinued', $cont_kids );
			}
			$start = $this->node( 'startOptionValueList', $start_kids );
		}

		$set_stmt = $this->node( 'setStatement', array( $set_tok, $start ) );
		return $this->with_tail( $this->node( 'simpleStatement', array( $set_stmt ) ), $tokens, $i );
	}

	/**
	 * @return array{0:WP_Parser_Node,1:int}
	 */
	private function build_set_option_no_type( array $tokens, int $i ): array {
		$var_tok = $tokens[ $i ];

		if ( WP_MySQL_Lexer::AT_AT_SIGN_SYMBOL === $var_tok->id ) {
			// setSystemVariable: AT_AT [GLOBAL|SESSION DOT]? internalVariableName.
			$kids = array( $var_tok );
			++$i;
			$next_id = $tokens[ $i ]->id ?? 0;
			if ( WP_MySQL_Lexer::GLOBAL_SYMBOL === $next_id || WP_MySQL_Lexer::SESSION_SYMBOL === $next_id ) {
				$kids[] = $this->node( 'setVarIdentType', array( $tokens[ $i ], $tokens[ $i + 1 ] ) );
				$i     += 2;
			}
			$kids[] = $this->node(
				'internalVariableName',
				array(
					$this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $tokens[ $i ] ) ) ) ),
				)
			);
			++$i;
			$ssv     = $this->node( 'setSystemVariable', $kids );
			$eq_tok  = $tokens[ $i ];
			$rhs_tok = $tokens[ $i + 1 ];
			$opt     = $this->node(
				'optionValueNoOptionType',
				array(
					$ssv,
					$this->node( 'equal', array( $eq_tok ) ),
					$this->node( 'setExprOrDefault', array( $this->expr_for_rhs( $rhs_tok ) ) ),
				)
			);
			return array( $opt, $i + 2 );
		}

		$eq_tok  = $tokens[ $i + 1 ];
		$rhs_tok = $tokens[ $i + 2 ];
		if ( WP_MySQL_Lexer::AT_TEXT_SUFFIX === $var_tok->id ) {
			// userVariable form (does NOT use setExprOrDefault — expr directly).
			$var = $this->node( 'userVariable', array( $var_tok ) );
			$opt = $this->node(
				'optionValueNoOptionType',
				array(
					$var,
					$this->node( 'equal', array( $eq_tok ) ),
					$this->expr_for_rhs( $rhs_tok ),
				)
			);
		} else {
			$ivn = $this->node(
				'internalVariableName',
				array(
					$this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $var_tok ) ) ) ),
				)
			);
			$opt = $this->node(
				'optionValueNoOptionType',
				array(
					$ivn,
					$this->node( 'equal', array( $eq_tok ) ),
					$this->node( 'setExprOrDefault', array( $this->expr_for_rhs( $rhs_tok ) ) ),
				)
			);
		}
		return array( $opt, $i + 3 );
	}

	/**
	 * optionValueFollowingOptionType — only `ident = expr`.
	 *
	 * @return array{0:WP_Parser_Node,1:int}
	 */
	private function build_set_option_following( array $tokens, int $i ): array {
		$var_tok = $tokens[ $i ];
		$eq_tok  = $tokens[ $i + 1 ];
		$rhs_tok = $tokens[ $i + 2 ];
		$ivn     = $this->node(
			'internalVariableName',
			array(
				$this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $var_tok ) ) ) ),
			)
		);
		$opt     = $this->node(
			'optionValueFollowingOptionType',
			array(
				$ivn,
				$this->node( 'equal', array( $eq_tok ) ),
				$this->node( 'setExprOrDefault', array( $this->expr_for_rhs( $rhs_tok ) ) ),
			)
		);
		return array( $opt, $i + 3 );
	}

	/**
	 * SELECT entrypoint.
	 */
	private function build_select( array $tokens ): WP_Parser_Node {
		$ss = $this->build_select_inner( $tokens, 0 );

		$i = 0;
		for ( $j = count( $tokens ) - 1; $j >= 0; $j-- ) {
			if ( WP_MySQL_Lexer::EOF !== $tokens[ $j ]->id && WP_MySQL_Lexer::SEMICOLON_SYMBOL !== $tokens[ $j ]->id ) {
				$i = $j + 1;
				break;
			}
		}
		return $this->with_tail( $ss, $tokens, $i );
	}

	/**
	 * Inner SELECT body — returns a `simpleStatement{ selectStatement{...} }`
	 * node without the trailing `;`/EOF tokens. Reused by EXPLAIN.
	 */
	private function build_select_inner( array $tokens, int $start ): WP_Parser_Node {
		$select_tok = $tokens[ $start ];

		// Find FROM.
		$i_from = $start + 1;
		while ( WP_MySQL_Lexer::FROM_SYMBOL !== $tokens[ $i_from ]->id ) {
			++$i_from;
		}
		$select_items = $this->select_item_list( $tokens, $start + 1, $i_from );

		// FROM list.
		$i                    = $i_from + 1;
		list( $tr_first, $i ) = $this->consume_table_reference( $tokens, $i );
		$trl_kids             = array( $tr_first );
		while ( $i < count( $tokens ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
			$trl_kids[] = $tokens[ $i ];
			++$i;
			list( $tr, $i ) = $this->consume_table_reference( $tokens, $i );
			$trl_kids[]     = $tr;
		}
		$from_clause = $this->node(
			'fromClause',
			array(
				$tokens[ $i_from ],
				$this->node( 'tableReferenceList', $trl_kids ),
			)
		);

		$qs_kids           = array( $select_tok, $select_items, $from_clause );
		list( $where, $i ) = $this->maybe_where_for_select( $tokens, $i );
		if ( null !== $where ) {
			$qs_kids[] = $where;
		}
		$qs  = $this->node( 'querySpecification', $qs_kids );
		$qp  = $this->node( 'queryPrimary', array( $qs ) );
		$qt  = $this->node( 'queryTerm', array( $qp ) );
		$qeb = $this->node( 'queryExpressionBody', array( $qt ) );

		$qe_kids = array( $qeb );

		// ORDER BY (multi-column, lives at queryExpression level).
		if ( $i < count( $tokens ) && WP_MySQL_Lexer::ORDER_SYMBOL === $tokens[ $i ]->id ) {
			$order_tok = $tokens[ $i ];
			$by_tok    = $tokens[ $i + 1 ];
			$i        += 2;
			$ol_kids   = array();
			while ( true ) {
				list( $oe, $i ) = $this->consume_order_item( $tokens, $i );
				$ol_kids[]      = $oe;
				if ( $i >= count( $tokens ) || WP_MySQL_Lexer::COMMA_SYMBOL !== $tokens[ $i ]->id ) {
					break;
				}
				$ol_kids[] = $tokens[ $i ];
				++$i;
			}
			$qe_kids[] = $this->node( 'orderClause', array( $order_tok, $by_tok, $this->node( 'orderList', $ol_kids ) ) );
		}

		// LIMIT n [, n].
		if ( $i < count( $tokens ) && WP_MySQL_Lexer::LIMIT_SYMBOL === $tokens[ $i ]->id ) {
			$lim_tok = $tokens[ $i ];
			$n_tok   = $tokens[ $i + 1 ];
			$lo_kids = array( $this->node( 'limitOption', array( $n_tok ) ) );
			$i      += 2;
			if ( $i < count( $tokens ) && WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
				$lo_kids[] = $tokens[ $i ];
				$lo_kids[] = $this->node( 'limitOption', array( $tokens[ $i + 1 ] ) );
				$i        += 2;
			}
			$qe_kids[] = $this->node( 'limitClause', array( $lim_tok, $this->node( 'limitOptions', $lo_kids ) ) );
		}

		$qe       = $this->node( 'queryExpression', $qe_kids );
		$sel_stmt = $this->node( 'selectStatement', array( $qe ) );
		return $this->node( 'simpleStatement', array( $sel_stmt ) );
	}

	/**
	 * Variant of maybe_where for SELECT, where the parser handles `false`
	 * outcomes by stopping before ORDER/LIMIT.
	 *
	 * @return array{0:WP_Parser_Node|null,1:int}
	 */
	private function maybe_where_for_select( array $tokens, int $i ): array {
		return $this->maybe_where( $tokens, $i );
	}

	/**
	 * `selectItemList` covering both `*` and identifier lists with optional
	 * aliases.
	 */
	private function select_item_list( array $tokens, int $i_start, int $i_end_excl ): WP_Parser_Node {
		$kids = array();
		if ( 1 === $i_end_excl - $i_start && WP_MySQL_Lexer::MULT_OPERATOR === $tokens[ $i_start ]->id ) {
			$kids[] = $tokens[ $i_start ];
			return $this->node( 'selectItemList', $kids );
		}

		$i = $i_start;
		while ( $i < $i_end_excl ) {
			if ( WP_MySQL_Lexer::COMMA_SYMBOL === $tokens[ $i ]->id ) {
				$kids[] = $tokens[ $i ];
				++$i;
				continue;
			}
			$col   = $tokens[ $i ];
			$dot   = null;
			$col_b = null;
			if ( ( $i + 1 < $i_end_excl ) && WP_MySQL_Lexer::DOT_SYMBOL === $tokens[ $i + 1 ]->id ) {
				$dot   = $tokens[ $i + 1 ];
				$col_b = $tokens[ $i + 2 ];
				$i    += 3;
			} else {
				++$i;
			}
			$si_kids = array( $this->expr_wrap_col( $col, $dot, $col_b ) );
			// Optional alias: [AS] ident.
			if ( $i < $i_end_excl ) {
				$as_tok = null;
				if ( WP_MySQL_Lexer::AS_SYMBOL === $tokens[ $i ]->id ) {
					$as_tok = $tokens[ $i ];
					++$i;
				}
				if ( $i < $i_end_excl && ( WP_MySQL_Lexer::IDENTIFIER === $tokens[ $i ]->id || WP_MySQL_Lexer::BACK_TICK_QUOTED_ID === $tokens[ $i ]->id ) ) {
					$alias_kids = array();
					if ( null !== $as_tok ) {
						$alias_kids[] = $as_tok;
					}
					$alias_kids[] = $this->node( 'identifier', array( $this->node( 'pureIdentifier', array( $tokens[ $i ] ) ) ) );
					$si_kids[]    = $this->node( 'selectAlias', $alias_kids );
					++$i;
				} elseif ( null !== $as_tok ) {
					// AS without identifier — give back the AS_SYMBOL.
					--$i;
				}
			}
			$kids[] = $this->node( 'selectItem', $si_kids );
		}
		return $this->node( 'selectItemList', $kids );
	}

	private function build_explain( array $tokens ): WP_Parser_Node {
		$expl_tok     = $tokens[0];
		$inner_simple = $this->build_select_inner( $tokens, 1 );
		// Inner returns simpleStatement{ selectStatement{...} }; unwrap selectStatement.
		$inner_kids        = $inner_simple->get_children_ref();
		$inner_select_stmt = $inner_kids[0];
		$exp_able          = $this->node( 'explainableStatement', array( $inner_select_stmt ) );
		$expl              = $this->node( 'explainStatement', array( $expl_tok, $exp_able ) );
		$util              = $this->node( 'utilityStatement', array( $expl ) );

		$i = 0;
		for ( $j = count( $tokens ) - 1; $j >= 0; $j-- ) {
			if ( WP_MySQL_Lexer::EOF !== $tokens[ $j ]->id && WP_MySQL_Lexer::SEMICOLON_SYMBOL !== $tokens[ $j ]->id ) {
				$i = $j + 1;
				break;
			}
		}
		return $this->with_tail( $this->node( 'simpleStatement', array( $util ) ), $tokens, $i );
	}

	private function build_update( array $tokens ): WP_Parser_Node {
		$upd_tok          = $tokens[0];
		list( $tref, $i ) = $this->consume_table_ref( $tokens, 1 );
		$tbl              = $this->node(
			'tableReference',
			array(
				$this->node(
					'tableFactor',
					array(
						$this->node( 'singleTable', array( $tref ) ),
					)
				),
			)
		);
		$trl              = $this->node( 'tableReferenceList', array( $tbl ) );

		$set_tok = $tokens[ $i ];
		++$i;
		$ul_kids = array();
		$first   = true;
		while ( $i < count( $tokens ) && WP_MySQL_Lexer::WHERE_SYMBOL !== $tokens[ $i ]->id
			&& WP_MySQL_Lexer::SEMICOLON_SYMBOL !== $tokens[ $i ]->id
			&& WP_MySQL_Lexer::EOF !== $tokens[ $i ]->id
		) {
			if ( ! $first ) {
				$ul_kids[] = $tokens[ $i ]; // COMMA.
				++$i;
			}
			$col_tok   = $tokens[ $i ];
			$eq_tok    = $tokens[ $i + 1 ];
			$rhs_tok   = $tokens[ $i + 2 ];
			$ul_kids[] = $this->node(
				'updateElement',
				array(
					$this->column_ref( $col_tok ),
					$this->node( 'equal', array( $eq_tok ) ),
					$this->expr_for_rhs( $rhs_tok ),
				)
			);
			$i        += 3;
			$first     = false;
		}
		$ul = $this->node( 'updateList', $ul_kids );

		$up_kids           = array( $upd_tok, $trl, $set_tok, $ul );
		list( $where, $i ) = $this->maybe_where( $tokens, $i );
		if ( null !== $where ) {
			$up_kids[] = $where;
		}
		$up_stmt = $this->node( 'updateStatement', $up_kids );
		return $this->with_tail( $this->node( 'simpleStatement', array( $up_stmt ) ), $tokens, $i );
	}

	private function build_delete( array $tokens ): WP_Parser_Node {
		$del_tok          = $tokens[0];
		$from_tok         = $tokens[1];
		list( $tref, $i ) = $this->consume_table_ref( $tokens, 2 );
		$kids             = array( $del_tok, $from_tok, $tref );

		list( $where, $i ) = $this->maybe_where( $tokens, $i );
		if ( null !== $where ) {
			$kids[] = $where;
		}
		$del_stmt = $this->node( 'deleteStatement', $kids );
		return $this->with_tail( $this->node( 'simpleStatement', array( $del_stmt ) ), $tokens, $i );
	}
}
