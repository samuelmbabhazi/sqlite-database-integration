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
	private function packed_id_stats( array $rows ): array {
		return array( count( $rows ), array_sum( $rows ) );
	}

	private function packed_scalar_stats( array $rows ): array {
		$descendants = intdiv( count( $rows ), 2 );
		$checksum    = 0;
		for ( $i = 0; $i < count( $rows ); $i += 2 ) {
			$kind_id = $rows[ $i ];
			$span    = $rows[ $i + 1 ];
			if ( $span < 0 ) {
				$checksum += $kind_id - 1;
			} else {
				$checksum += $kind_id + intdiv( $span, 4294967296 ) + ( $span & 0xffffffff );
			}
		}
		return array( $descendants, $checksum );
	}

	private function checksum_bytes( string $bytes ): int {
		$length   = strlen( $bytes );
		$checksum = $length;
		for ( $i = 0; $i < $length; $i++ ) {
			$checksum += ord( $bytes[ $i ] );
		}
		return $checksum;
	}

	private function packed_scalar_token_bytes_stats( array $rows, string $sql ): array {
		$descendants = intdiv( count( $rows ), 2 );
		$checksum    = 0;
		for ( $i = 0; $i < count( $rows ); $i += 2 ) {
			$kind_id = $rows[ $i ];
			$span    = $rows[ $i + 1 ];
			if ( $span < 0 ) {
				$checksum += $kind_id - 1;
			} else {
				$start     = intdiv( $span, 4294967296 );
				$length    = $span & 0xffffffff;
				$checksum += $kind_id + $start + $length;
				$checksum += $this->checksum_bytes( substr( $sql, $start, $length ) );
			}
		}
		return array( $descendants, $checksum );
	}

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

	public function test_native_ast_descendant_packed_rows_match_materialized_descendants(): void {
		if ( ! class_exists( 'WP_MySQL_Native_Parser_Node', false ) ) {
			$this->markTestSkipped( 'Native parser extension is not active.' );
		}

		$grammar = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$lexer   = new WP_MySQL_Lexer( 'SELECT 1 + 2' );
		$parser  = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );

		$ast = $parser->parse();
		$this->assertInstanceOf( WP_MySQL_Native_Parser_Node::class, $ast );

		$expected_id_rows     = array();
		$expected_scalar_rows = array();
		foreach ( $ast->get_descendants() as $descendant ) {
			if ( $descendant instanceof WP_Parser_Node ) {
				$expected_id_rows[]     = $descendant->rule_id * 2;
				$expected_scalar_rows[] = $descendant->rule_id * 2;
				$expected_scalar_rows[] = -1;
			} else {
				$expected_id_rows[]     = $descendant->id * 2 + 1;
				$expected_scalar_rows[] = $descendant->id * 2 + 1;
				$expected_scalar_rows[] = $descendant->start * 4294967296 + $descendant->length;
			}
		}

		$this->assertSame( $expected_id_rows, $ast->get_native_descendant_packed_id_rows() );
		$this->assertSame( $expected_scalar_rows, $ast->get_native_descendant_packed_scalar_rows() );
	}

	public function test_native_parser_direct_rows_match_ast_rows(): void {
		if ( ! class_exists( 'WP_MySQL_Native_Parser_Node', false ) ) {
			$this->markTestSkipped( 'Native parser extension is not active.' );
		}

		$grammar                    = new WP_Parser_Grammar( include __DIR__ . '/../../../src/mysql/mysql-grammar.php' );
		$queries                    = array(
			'SELECT 1 + 2',
			"INSERT INTO wp_posts (ID, post_title) VALUES (1, 'Hello')",
			'CREATE TABLE t (id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))',
			'SELECT * FROM t WHERE id IN (SELECT id FROM u)',
		);
		$expected_batch_id          = array( count( $queries ), 0, 0, 0 );
		$expected_batch_scalar      = array( count( $queries ), 0, 0, 0 );
		$expected_batch_token_bytes = array( count( $queries ), 0, 0, 0 );
		foreach ( $queries as $sql ) {
			$lexer      = new WP_MySQL_Lexer( $sql );
			$ast_parser = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );
			$ast        = $ast_parser->parse();
			$this->assertInstanceOf( WP_MySQL_Native_Parser_Node::class, $ast );
			$packed_scalar_rows = $ast->get_native_descendant_packed_scalar_rows();
			$id_stats           = $this->packed_id_stats( $ast->get_native_descendant_packed_id_rows() );
			$scalar_stats       = $this->packed_scalar_stats( $packed_scalar_rows );
			$token_bytes_stats  = $this->packed_scalar_token_bytes_stats( $packed_scalar_rows, $sql );

			$expected_batch_id[2]          += $id_stats[0];
			$expected_batch_id[3]          += $id_stats[1];
			$expected_batch_scalar[2]      += $scalar_stats[0];
			$expected_batch_scalar[3]      += $scalar_stats[1];
			$expected_batch_token_bytes[2] += $token_bytes_stats[0];
			$expected_batch_token_bytes[3] += $token_bytes_stats[1];

			$lexer         = new WP_MySQL_Lexer( $sql );
			$direct_parser = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );
			$this->assertSame( $ast->get_native_descendant_id_rows(), $direct_parser->parse_native_descendant_id_rows() );

			$lexer         = new WP_MySQL_Lexer( $sql );
			$direct_parser = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );
			$this->assertSame( $ast->get_native_descendant_packed_id_rows(), $direct_parser->parse_native_descendant_packed_id_rows() );

			$lexer         = new WP_MySQL_Lexer( $sql );
			$direct_parser = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );
			$this->assertSame( $ast->get_native_descendant_scalar_rows(), $direct_parser->parse_native_descendant_scalar_rows() );

			$lexer         = new WP_MySQL_Lexer( $sql );
			$direct_parser = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );
			$this->assertSame( $ast->get_native_descendant_packed_scalar_rows(), $direct_parser->parse_native_descendant_packed_scalar_rows() );

			$lexer         = new WP_MySQL_Lexer( $sql );
			$direct_parser = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );
			$this->assertSame(
				$id_stats,
				$direct_parser->parse_native_descendant_packed_id_stats()
			);

			$lexer         = new WP_MySQL_Lexer( $sql );
			$direct_parser = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );
			$this->assertSame(
				$scalar_stats,
				$direct_parser->parse_native_descendant_packed_scalar_stats()
			);

			$lexer         = new WP_MySQL_Lexer( $sql );
			$direct_parser = new WP_MySQL_Parser( $grammar, $lexer->native_token_stream() );
			$this->assertSame(
				$token_bytes_stats,
				$direct_parser->parse_native_descendant_packed_scalar_stats( true )
			);

			$sql_parser = new WP_MySQL_Parser( $grammar, array() );
			$this->assertSame(
				$id_stats,
				$sql_parser->parse_sql_native_descendant_packed_id_stats( $sql )
			);

			$sql_parser = new WP_MySQL_Parser( $grammar, array() );
			$this->assertSame(
				$scalar_stats,
				$sql_parser->parse_sql_native_descendant_packed_scalar_stats( $sql )
			);

			$sql_parser = new WP_MySQL_Parser( $grammar, array() );
			$this->assertSame(
				$token_bytes_stats,
				$sql_parser->parse_sql_native_descendant_packed_scalar_stats( $sql, true )
			);
		}

		$sql_parser = new WP_MySQL_Parser( $grammar, array() );
		$this->assertSame(
			$expected_batch_id,
			$sql_parser->parse_sql_batch_native_descendant_packed_id_stats( $queries )
		);

		$sql_parser = new WP_MySQL_Parser( $grammar, array() );
		$this->assertSame(
			$expected_batch_scalar,
			$sql_parser->parse_sql_batch_native_descendant_packed_scalar_stats( $queries )
		);

		$sql_parser = new WP_MySQL_Parser( $grammar, array() );
		$this->assertSame(
			$expected_batch_token_bytes,
			$sql_parser->parse_sql_batch_native_descendant_packed_scalar_stats( $queries, true )
		);
	}

	public function test_native_sqlite_plan_translates_common_wordpress_queries(): void {
		if (
			! class_exists( 'WP_MySQL_Native_Lexer', false )
			|| ! method_exists( 'WP_MySQL_Native_Lexer', 'translate_sqlite_plan' )
			|| ! method_exists( 'WP_MySQL_Native_Lexer', 'translate_sqlite_plan_code' )
		) {
			$this->markTestSkipped( 'Native SQLite plan translator is not active.' );
		}

		$this->assertSame(
			1,
			WP_MySQL_Native_Lexer::translate_sqlite_plan_code(
				"SELECT ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT 0, 2"
			)
		);

		$this->assertSame(
			array(
				'select_passthrough',
				"SELECT ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT 0, 2",
				"SELECT ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID",
			),
			WP_MySQL_Native_Lexer::translate_sqlite_plan(
				"SELECT SQL_CALC_FOUND_ROWS ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT 0, 2"
			)
		);
		$this->assertSame(
			0,
			WP_MySQL_Native_Lexer::translate_sqlite_plan_code(
				"SELECT SQL_CALC_FOUND_ROWS ID FROM wp_posts WHERE post_status = 'publish' ORDER BY ID LIMIT 0, 2"
			)
		);

		$this->assertSame(
			array(
				'update_passthrough',
				"UPDATE `wp_options` SET `option_value` = '1' WHERE `option_name` = 'x'",
			),
			WP_MySQL_Native_Lexer::translate_sqlite_plan(
				"UPDATE `wp_options` SET `option_value` = '1' WHERE `option_name` = 'x'"
			)
		);
		$this->assertSame(
			2,
			WP_MySQL_Native_Lexer::translate_sqlite_plan_code(
				"UPDATE `wp_options` SET `option_value` = '1' WHERE `option_name` = 'x'"
			)
		);

		$this->assertSame(
			array(
				'select_session_sql_mode',
				'@@session.SQL_mode',
			),
			WP_MySQL_Native_Lexer::translate_sqlite_plan( 'SELECT @@session.SQL_mode' )
		);
	}

	public function test_native_sqlite_plan_rejects_queries_that_need_mysql_translation(): void {
		if (
			! class_exists( 'WP_MySQL_Native_Lexer', false )
			|| ! method_exists( 'WP_MySQL_Native_Lexer', 'translate_sqlite_plan' )
			|| ! method_exists( 'WP_MySQL_Native_Lexer', 'translate_sqlite_plan_code' )
		) {
			$this->markTestSkipped( 'Native SQLite plan translator is not active.' );
		}

		$this->assertNull(
			WP_MySQL_Native_Lexer::translate_sqlite_plan( 'SELECT * FROM information_schema.tables' )
		);
		$this->assertSame(
			0,
			WP_MySQL_Native_Lexer::translate_sqlite_plan_code( 'SELECT * FROM information_schema.tables' )
		);
		$this->assertNull(
			WP_MySQL_Native_Lexer::translate_sqlite_plan( 'SELECT CAST (meta_value AS UNSIGNED) FROM wp_postmeta' )
		);
	}

	public function test_native_sqlite_connection_fetches_rows_from_file_database(): void {
		if ( ! class_exists( 'WP_SQLite_Native_Connection', false ) ) {
			$this->markTestSkipped( 'Native SQLite connection is not active.' );
		}

		$db_path = tempnam( sys_get_temp_dir(), 'wp-sqlite-native-' );
		$this->assertIsString( $db_path );

		try {
			$pdo = new PDO( 'sqlite:' . $db_path );
			$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT)' );
			$pdo->exec( "INSERT INTO wp_posts VALUES (1, 'Hello'), (2, 'World')" );

			$connection = new WP_SQLite_Native_Connection( $db_path );
			$stmt       = $connection->query( 'SELECT ID, post_title FROM wp_posts ORDER BY ID' );
			$this->assertSame( 2, $stmt->columnCount() );
			$this->assertSame(
				array(
					array(
						'ID'         => '1',
						'post_title' => 'Hello',
					),
					array(
						'ID'         => '2',
						'post_title' => 'World',
					),
				),
				$stmt->fetchAll( PDO::FETCH_ASSOC )
			);

			$mysql_stmt = $connection->queryMysql( 'SELECT SQL_CALC_FOUND_ROWS ID FROM wp_posts ORDER BY ID LIMIT 1' );
			$this->assertNotNull( $mysql_stmt );
			$this->assertSame( 2, $mysql_stmt->foundRows() );
			$this->assertCount( 2, $mysql_stmt->sqliteQueries() );
			$this->assertSame(
				array(
					array(
						'ID' => '1',
					),
				),
				$mysql_stmt->fetchAll( PDO::FETCH_ASSOC )
			);

			$column_stmt = $connection->queryMysql( 'SELECT SQL_CALC_FOUND_ROWS ID, post_title FROM wp_posts ORDER BY ID LIMIT 1' );
			$this->assertSame( array( 'Hello' ), $column_stmt->fetchAll( PDO::FETCH_COLUMN, 1 ) );

			$packed_result = $connection->queryMysqlPackedRows( 'SELECT SQL_CALC_FOUND_ROWS ID, post_title FROM wp_posts ORDER BY ID LIMIT 1' );
			$this->assertNotNull( $packed_result );
			$this->assertSame( 2, $packed_result->foundRows() );
			$this->assertSame( 1, $packed_result->rowCount() );
			$this->assertSame( 2, $packed_result->columnCount() );
			$this->assertSame( array( 'ID', 'post_title' ), $packed_result->columns() );
			$this->assertSame( pack( 'V', 1 ) . '1' . pack( 'V', 5 ) . 'Hello', $packed_result->packedRows() );

			$update = $connection->executeStatement( "UPDATE wp_posts SET post_title = 'Changed' WHERE ID = 1" );
			$this->assertSame( 1, $update->rowCount() );
		} finally {
			unlink( $db_path );
		}
	}
}
