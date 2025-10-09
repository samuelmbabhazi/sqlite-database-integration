<?php

use PHPUnit\Framework\TestCase;

class WP_MySQL_Naive_Query_Stream_Tests extends TestCase {

	public function test_next_query_returns_a_single_delimited_query(): void {
		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( 'SELECT id FROM users;' );
		$this->assertTrue( $stream->next_query() );
		$this->assertSame( 'SELECT id FROM users;', $stream->get_query() );
	}

	public function test_next_query_returns_false_if_the_input_is_incomplete(): void {
		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( 'SELECT id FROM users' );
		$this->assertFalse( $stream->next_query() );
	}

	public function test_next_query_returns_true_if_the_input_is_complete_but_undelimited(): void {
		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( 'SELECT id FROM users' );
		$stream->mark_input_complete();
		$this->assertTrue( $stream->next_query() );
		$this->assertSame( 'SELECT id FROM users', $stream->get_query() );
	}

	public function test_next_query_parses_multiple_queries_with_even_appends(): void {
		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( 'SELECT id FROM users; SELECT name FROM users2;' );

		$this->assertTrue( $stream->next_query() );
		$this->assertSame( 'SELECT id FROM users;', $stream->get_query() );

		$this->assertTrue( $stream->next_query() );
		$this->assertSame( ' SELECT name FROM users2;', $stream->get_query() );

		$this->assertFalse( $stream->next_query() );

		$stream->append_sql( 'SELECT name FROM users3;' );
		$this->assertTrue( $stream->next_query() );
		$this->assertSame( 'SELECT name FROM users3;', $stream->get_query() );

		$this->assertFalse( $stream->next_query() );
	}

	public function test_next_query_parses_multiple_queries_with_uneven_appends(): void {
		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( 'SELECT id FROM ' );

		$this->assertFalse( $stream->next_query() );
		
		$stream->append_sql( 'users; SELECT name ' );
		$this->assertTrue( $stream->next_query() );
		$this->assertSame( 'SELECT id FROM users;', $stream->get_query() );
		
		$this->assertFalse( $stream->next_query() );
		$stream->append_sql( ', id FROM users2; INSERT' );
		$this->assertTrue( $stream->next_query() );
		$this->assertSame( ' SELECT name , id FROM users2;', $stream->get_query() );

		$this->assertFalse( $stream->next_query() );

		$stream->append_sql( ' INTO users3 VALUES (1, 2)' );
		$stream->mark_input_complete();
		$this->assertTrue( $stream->next_query() );
		$this->assertSame( ' INSERT INTO users3 VALUES (1, 2)', $stream->get_query() );
	}

	public function test_next_query_parses_queries_with_trailing_block_comments_included(): void {
		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( 'SELECT id FROM users /* foo */' );
		$stream->mark_input_complete();

		$this->assertTrue( $stream->next_query() );
		$this->assertSame( 'SELECT id FROM users /* foo */', $stream->get_query() );

		$this->assertFalse( $stream->next_query() );
	}

	public function test_next_query_parses_queries_with_trailing_block_comments_excluded(): void {
		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( 'SELECT id FROM users; /* foo */' );
		$stream->mark_input_complete();

		$this->assertTrue( $stream->next_query() );
		$this->assertSame( 'SELECT id FROM users;', $stream->get_query() );

		$this->assertFalse( $stream->next_query() );
		$this->assertEquals(WP_MySQL_Naive_Query_Stream::STATE_FINISHED, $stream->get_state());
	}

	public function test_treats_too_large_input_as_a_syntax_error(): void {
		$five_megabytes = str_repeat( 'lorem ', 1024 * 1024 );

		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( $five_megabytes );
		$this->assertFalse( $stream->next_query() );
		$this->assertEquals(WP_MySQL_Naive_Query_Stream::STATE_SYNTAX_ERROR, $stream->get_state());
	}

	public function test_next_query_returns_false_if_the_input_has_a_syntax_error(): void {
		$this->markTestSkipped('This test is expected to fail because the naive query stream doesn\'t understand what a valid query is. It\'s just a heuristic that works for most cases.');
		
		$stream = new WP_MySQL_Naive_Query_Stream();
		$stream->append_sql( 'SELECT id FROM users WHERE id = ihj' );
		$stream->mark_input_complete();
		$this->assertFalse( $stream->next_query() );
	}
}
