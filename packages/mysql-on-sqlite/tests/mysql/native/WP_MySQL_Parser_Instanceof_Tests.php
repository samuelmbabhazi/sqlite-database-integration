<?php

use PHPUnit\Framework\TestCase;

/**
 * `WP_MySQL_Parser instanceof WP_Parser` must hold in both modes.
 *
 * The native-mode `WP_MySQL_Parser` must not expose the Rust-registered
 * parser directly. Existing downstream code may rely on
 * `if ($parser instanceof WP_Parser)`, so this test pins the contract for
 * both modes.
 */
class WP_MySQL_Parser_Instanceof_Tests extends TestCase {

	public function test_parser_is_instance_of_wp_parser(): void {
		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1' );
		$tokens  = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		$parser  = new WP_MySQL_Parser( $grammar, $tokens );

		$this->assertInstanceOf( WP_Parser::class, $parser );
		$this->assertInstanceOf( WP_MySQL_Parser::class, $parser );
	}

	public function test_parser_returns_an_ast(): void {
		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1 + 2' );
		$tokens  = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		$parser  = new WP_MySQL_Parser( $grammar, $tokens );

		$ast = $parser->parse();
		$this->assertNotNull( $ast );
		$this->assertInstanceOf( WP_Parser_Node::class, $ast );
	}

	public function test_native_ast_node_identity_survives_mutation(): void {
		if ( ! class_exists( 'WP_MySQL_Native_Parser_Node', false ) ) {
			$this->markTestSkipped( 'Native parser extension is not active.' );
		}

		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1' );
		$parser  = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );

		$ast = $parser->parse();
		$this->assertInstanceOf( WP_MySQL_Native_Parser_Node::class, $ast );

		$first_child = $ast->get_first_child_node();
		$this->assertInstanceOf( WP_Parser_Node::class, $first_child );
		$this->assertSame( $first_child, $ast->get_first_child_node() );

		$synthetic = new WP_Parser_Node( 0, 'synthetic' );
		$first_child->append_child( $synthetic );

		$same_first_child = $ast->get_first_child_node();
		$this->assertSame( $first_child, $same_first_child );
		$this->assertTrue( in_array( $synthetic, $same_first_child->get_children(), true ) );
	}

	public function test_native_ast_descendant_id_rows_match_materialized_descendants(): void {
		if ( ! class_exists( 'WP_MySQL_Native_Parser_Node', false ) ) {
			$this->markTestSkipped( 'Native parser extension is not active.' );
		}

		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1 + 2' );
		$parser  = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );

		$ast = $parser->parse();
		$this->assertInstanceOf( WP_MySQL_Native_Parser_Node::class, $ast );

		$expected = array();
		foreach ( $ast->get_descendants() as $descendant ) {
			if ( $descendant instanceof WP_Parser_Node ) {
				$expected[] = 0;
				$expected[] = $descendant->rule_id;
			} else {
				$expected[] = 1;
				$expected[] = $descendant->id;
			}
		}

		$this->assertSame( $expected, $ast->get_native_descendant_id_rows() );
	}

	public function test_native_ast_descendant_scalar_rows_match_materialized_descendants(): void {
		if ( ! class_exists( 'WP_MySQL_Native_Parser_Node', false ) ) {
			$this->markTestSkipped( 'Native parser extension is not active.' );
		}

		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1 + 2' );
		$parser  = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );

		$ast = $parser->parse();
		$this->assertInstanceOf( WP_MySQL_Native_Parser_Node::class, $ast );

		$expected = array();
		foreach ( $ast->get_descendants() as $descendant ) {
			if ( $descendant instanceof WP_Parser_Node ) {
				$expected[] = 0;
				$expected[] = $descendant->rule_id;
				$expected[] = -1;
				$expected[] = 0;
			} else {
				$expected[] = 1;
				$expected[] = $descendant->id;
				$expected[] = $descendant->start;
				$expected[] = $descendant->length;
			}
		}

		$this->assertSame( $expected, $ast->get_native_descendant_scalar_rows() );
	}
}
