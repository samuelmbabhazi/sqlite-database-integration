<?php

class WP_SQLite_Ambiguous_Order_By_Tests {
	/** @var WP_SQLite_Driver */
	private $engine;

	/** @var PDO */
	private $sqlite;

	// Before each test, we create a new database
	public function setUp(): void {
		$this->sqlite = new PDO( 'sqlite::memory:' );

		$this->engine = new WP_SQLite_Driver(
			new WP_SQLite_Connection( array( 'pdo' => $this->sqlite ) ),
			'wp'
		);
		
		// Create test tables
		$this->engine->query(
			"CREATE TABLE t1 (id INT, name TEXT);"
		);
		$this->engine->query(
			"CREATE TABLE t2 (t1_id INT, name TEXT);"
		);
		
		// Insert test data
		$this->engine->query( 'INSERT INTO t1 (id, name) VALUES (1, "T1 A");' );
		$this->engine->query( 'INSERT INTO t1 (id, name) VALUES (2, "T1 B");' );
		$this->engine->query( 'INSERT INTO t2 (t1_id, name) VALUES (1, "T2 B");' );
		$this->engine->query( 'INSERT INTO t2 (t1_id, name) VALUES (2, "T2 A");' );
	}

	private function assertQuery( $sql, $error_substring = null ) {
		$retval = $this->engine->query( $sql );
		if ( null === $error_substring ) {
			if ( false === $retval ) {
				throw new Exception( "Query failed: $sql" );
			}
			return $this->engine->get_query_results();
		} else {
			if ( false !== $retval ) {
				throw new Exception( "Query should have failed but didn't: $sql" );
			}
			// For error tests, we just need to confirm it failed as expected
			return null;
		}
	}

	private function assertEquals( $expected, $actual, $message = '' ) {
		if ( $expected !== $actual ) {
			throw new Exception( "Assertion failed: expected $expected, got $actual. $message" );
		}
	}

	private function assertCount( $expected, $array ) {
		$actual = count( $array );
		if ( $expected !== $actual ) {
			throw new Exception( "Count assertion failed: expected $expected items, got $actual" );
		}
	}

	public function testOrderByUnqualifiedColumnResolvedFromSelect() {
		// Test case 1: SELECT t1.name should resolve ORDER BY name to t1.name
		$result = $this->assertQuery( 'SELECT t1.name FROM t1 JOIN t2 ON t2.t1_id = t1.id ORDER BY name;' );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'T1 A', $result[0]->name );
		$this->assertEquals( 'T1 B', $result[1]->name );
	}

	public function testOrderByUnqualifiedColumnResolvedFromSelectDifferentTable() {
		// Test case 2: SELECT t2.name should resolve ORDER BY name to t2.name
		$result = $this->assertQuery( 'SELECT t2.name FROM t1 JOIN t2 ON t2.t1_id = t1.id ORDER BY name;' );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'T2 A', $result[0]->name );
		$this->assertEquals( 'T2 B', $result[1]->name );
	}

	public function testOrderByAmbiguousColumnStillFails() {
		// Test case 3: SELECT t1.name, t2.name should still fail for ORDER BY name (truly ambiguous)
		$this->assertQuery( 
			'SELECT t1.name, t2.name FROM t1 JOIN t2 ON t2.t1_id = t1.id ORDER BY name;',
			'ambiguous column name'
		);
	}

	public function testOrderByColumnNotInSelectStillFails() {
		// Test case 4: SELECT t1.id should still fail for ORDER BY name (name not in SELECT)
		$this->assertQuery( 
			'SELECT t1.id FROM t1 JOIN t2 ON t2.t1_id = t1.id ORDER BY name;',
			'ambiguous column name'
		);
	}

	public function testOrderByQualifiedColumnStillWorks() {
		// Existing qualified ORDER BY should continue to work
		$result = $this->assertQuery( 'SELECT t1.name FROM t1 JOIN t2 ON t2.t1_id = t1.id ORDER BY t1.name;' );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'T1 A', $result[0]->name );
		$this->assertEquals( 'T1 B', $result[1]->name );
	}

	public function testOrderByWithoutJoinStillWorks() {
		// Simple ORDER BY without JOINs should continue to work
		$result = $this->assertQuery( 'SELECT name FROM t1 ORDER BY name;' );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'T1 A', $result[0]->name );
		$this->assertEquals( 'T1 B', $result[1]->name );
	}

	public function testOrderByMultipleColumnsWithQualification() {
		// Test ORDER BY with multiple columns where some can be qualified
		$result = $this->assertQuery( 'SELECT t1.name, t1.id FROM t1 JOIN t2 ON t2.t1_id = t1.id ORDER BY name, id;' );
		$this->assertCount( 2, $result );
		// Should order by t1.name, t1.id
		$this->assertEquals( 'T1 A', $result[0]->name );
		$this->assertEquals( 'T1 B', $result[1]->name );
	}
}