<?php

require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-configurator.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-driver.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-driver-exception.php';
require_once __DIR__ . '/../wp-includes/sqlite-ast/class-wp-sqlite-information-schema-builder.php';

use PHPUnit\Framework\TestCase;

class WP_SQLite_Driver_Metadata_Tests extends TestCase {

	private $engine;
	private $sqlite;

	public static function setUpBeforeClass(): void {
		// if ( ! defined( 'PDO_DEBUG' )) {
		// define( 'PDO_DEBUG', true );
		// }
		if ( ! defined( 'FQDB' ) ) {
			define( 'FQDB', ':memory:' );
			define( 'FQDBDIR', __DIR__ . '/../testdb' );
		}
		error_reporting( E_ALL & ~E_DEPRECATED );
		if ( ! isset( $GLOBALS['table_prefix'] ) ) {
			$GLOBALS['table_prefix'] = 'wptests_';
		}
		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']                  = new stdClass();
			$GLOBALS['wpdb']->suppress_errors = false;
			$GLOBALS['wpdb']->show_errors     = true;
		}
		return;
	}

	// Before each test, we create a new database
	public function setUp(): void {
		$this->sqlite = new PDO( 'sqlite::memory:' );
		$this->engine = new WP_SQLite_Driver(
			array(
				'connection' => $this->sqlite,
				'database'   => 'wp',
			)
		);
	}

	public function testCountTables() {
		$this->assertQuery( 'CREATE TABLE t1 (id INT)' );
		$this->assertQuery( 'CREATE TABLE t2 (id INT)' );

		$result = $this->assertQuery( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'wp'" );
		$this->assertEquals( array( (object) array( 'COUNT ( * )' => '2' ) ), $result );

		$result = $this->assertQuery( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'other'" );
		$this->assertEquals( array( (object) array( 'COUNT ( * )' => '0' ) ), $result );

		// @TODO: The result key should be "COUNT(*)" instead of "COUNT ( * )".
		//        The spacing was probably inserted by the translator.
	}

	public function testInformationSchemaTables() {
		$this->assertQuery(
			'
			CREATE TABLE t (id INT PRIMARY KEY, name TEXT, age INT)
		'
		);

		$result = $this->assertQuery( "SELECT * FROM information_schema.tables WHERE TABLE_NAME = 't'" );
		$this->assertCount( 1, $result );
		$this->assertRegExp( '/\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d/', $result[0]->CREATE_TIME );
		$this->assertEquals(
			array(
				'TABLE_CATALOG'   => 'def',
				'TABLE_SCHEMA'    => 'wp',
				'TABLE_NAME'      => 't',
				'TABLE_TYPE'      => 'BASE TABLE',
				'ENGINE'          => 'InnoDB',
				'VERSION'         => '10',
				'ROW_FORMAT'      => 'Dynamic',
				'TABLE_ROWS'      => '0',
				'AVG_ROW_LENGTH'  => '0',
				'DATA_LENGTH'     => '0',
				'MAX_DATA_LENGTH' => '0',
				'INDEX_LENGTH'    => '0',
				'DATA_FREE'       => '0',
				'AUTO_INCREMENT'  => null,
				'CREATE_TIME'     => $result[0]->CREATE_TIME,
				'UPDATE_TIME'     => null,
				'CHECK_TIME'      => null,
				'TABLE_COLLATION' => 'utf8mb4_general_ci',
				'CHECKSUM'        => null,
				'CREATE_OPTIONS'  => '',
				'TABLE_COMMENT'   => '',
			),
			(array) $result[0]
		);

		$result = $this->assertQuery(
			"SELECT
				table_name as 'name',
				engine AS 'engine',
				FLOOR( data_length / 1024 / 1024 ) 'data'
			FROM INFORMATION_SCHEMA.TABLES
			WHERE TABLE_NAME = 't'
			ORDER BY name ASC"
		);

		$this->assertEquals(
			array(
				'name'   => 't',
				'engine' => 'InnoDB',
				'data'   => '0',
			),
			(array) $result[0]
		);
	}

	public function testInformationSchemaQueryHidesSqliteSystemTables() {
		/**
		 * By default, system tables are not returned.
		 */
		$result = $this->assertQuery( "SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'sqlite_sequence'" );
		$this->assertEquals( 0, count( $result ) );
	}

	public function testUseStatement() {
		$this->assertQuery( 'CREATE TABLE tables (ENGINE TEXT)' );
		$this->assertQuery( "INSERT INTO tables (ENGINE) VALUES ('test')" );

		$this->assertQuery( 'USE wp' );
		$result = $this->assertQuery( 'SELECT * FROM tables' );
		$this->assertSame( 'test', $result[0]->ENGINE );

		$this->assertQuery( 'USE information_schema' );
		$result = $this->assertQuery( 'SELECT * FROM tables' );
		$this->assertSame( 'InnoDB', $result[0]->ENGINE );
	}

	private function assertQuery( $sql ) {
		$retval = $this->engine->query( $sql );
		$this->assertNotFalse( $retval );
		return $retval;
	}

	public function testCheckTable() {
		$this->assertQuery( 'CREATE TABLE t1 (id INT, name TEXT)' );
		$this->assertQuery( 'CREATE TABLE t2 (id INT, name TEXT)' );

		// A good table.
		$result = $this->assertQuery( 'CHECK TABLE t1' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$result
		);

		// Multiple tables.
		$result = $this->assertQuery( 'CHECK TABLE t1, t2' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				(object) array(
					'Table'    => 'wp.t2',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$result
		);

		// A missing table.
		$result = $this->assertQuery( 'CHECK TABLE missing' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'check',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'missing' doesn't exist",
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$result
		);

		// One good and one missing table.
		$result = $this->assertQuery( 'CHECK TABLE t1, missing' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'check',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'missing' doesn't exist",
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'check',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$result
		);
	}

	public function testOptimizeTable() {
		$this->assertQuery( 'CREATE TABLE t1 (id INT, name TEXT)' );
		$this->assertQuery( 'CREATE TABLE t2 (id INT, name TEXT)' );

		// A good table.
		$result = $this->assertQuery( 'OPTIMIZE TABLE t1' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'optimize',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$result
		);

		// Multiple tables.
		$result = $this->assertQuery( 'OPTIMIZE TABLE t1, t2' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'optimize',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				(object) array(
					'Table'    => 'wp.t2',
					'Op'       => 'optimize',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$result
		);

		// A missing table.
		$this->assertQuery( 'OPTIMIZE TABLE missing' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'optimize',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'missing' doesn't exist",
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'optimize',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$this->engine->get_query_results()
		);

		// One good and one missing table.
		$result = $this->assertQuery( 'OPTIMIZE TABLE t1, missing' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'optimize',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'optimize',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'missing' doesn't exist",
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'optimize',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$result
		);
	}

	public function testRepairTable() {
		$this->assertQuery( 'CREATE TABLE t1 (id INT, name TEXT)' );
		$this->assertQuery( 'CREATE TABLE t2 (id INT, name TEXT)' );

		// A good table.
		$result = $this->assertQuery( 'REPAIR TABLE t1' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'repair',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$result
		);

		// Multiple tables.
		$result = $this->assertQuery( 'REPAIR TABLE t1, t2' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'repair',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				(object) array(
					'Table'    => 'wp.t2',
					'Op'       => 'repair',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
			),
			$result
		);

		// A missing table.
		$result = $this->assertQuery( 'REPAIR TABLE missing' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'repair',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'missing' doesn't exist",
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'repair',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$result
		);

		// One good and one missing table.
		$result = $this->assertQuery( 'REPAIR TABLE t1, missing' );
		$this->assertEquals(
			array(
				(object) array(
					'Table'    => 'wp.t1',
					'Op'       => 'repair',
					'Msg_type' => 'status',
					'Msg_text' => 'OK',
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'repair',
					'Msg_type' => 'Error',
					'Msg_text' => "Table 'missing' doesn't exist",
				),
				(object) array(
					'Table'    => 'wp.missing',
					'Op'       => 'repair',
					'Msg_type' => 'status',
					'Msg_text' => 'Operation failed',
				),
			),
			$result
		);
	}

	public function testShowTableStatus() {
		$this->assertQuery( 'CREATE TABLE t ( comment_author TEXT, comment_content TEXT )' );

		$this->assertQuery(
			"INSERT INTO t ( comment_author, comment_content ) VALUES ( 'PhpUnit', 'Testing' )"
		);

		$this->assertQuery(
			"INSERT INTO t ( comment_author, comment_content ) VALUES  ( 'PhpUnit0', 'Testing0' ), ( 'PhpUnit1', 'Testing1' ), ( 'PhpUnit2', 'Testing2' )"
		);

		$this->assertTableEmpty( 't', false );
		$results = $this->assertQuery( 'SHOW TABLE STATUS FROM wp' );

		$this->assertEquals(
			array(
				(object) array(
					'Name'            => 't',
					'Engine'          => 'InnoDB',
					'Version'         => '10',
					'Row_format'      => 'Dynamic',
					'Rows'            => '0',
					'Avg_row_length'  => '0',
					'Data_length'     => '0',
					'Max_data_length' => '0',
					'Index_length'    => '0',
					'Data_free'       => '0',
					'Auto_increment'  => null,
					'Create_time'     => $results[0]->Create_time,
					'Update_time'     => null,
					'Check_time'      => null,
					'Collation'       => 'utf8mb4_general_ci',
					'Checksum'        => null,
					'Create_options'  => '',
					'Comment'         => '',
				),
			),
			$results
		);
	}

	private function assertTableEmpty( $table_name, $empty_var ) {

		$this->assertQuery(
			"SELECT COUNT(*) num FROM $table_name"
		);

		$actual = $this->engine->get_query_results();
		if ( $empty_var ) {
			$this->assertEquals( 0, $actual[0]->num, "$table_name is not empty" );
		} else {
			$this->assertGreaterThan( 0, $actual[0]->num, "$table_name is empty" );
		}
	}

	public function testTruncateTable() {
		$this->assertQuery(
			'CREATE TABLE wp_comments ( comment_author TEXT, comment_content TEXT )'
		);

		$this->assertQuery(
			"INSERT INTO wp_comments ( comment_author, comment_content ) VALUES ( 'PhpUnit', 'Testing' )"
		);

		$this->assertQuery(
			"INSERT INTO wp_comments ( comment_author, comment_content ) VALUES  ( 'PhpUnit0', 'Testing0' ), ( 'PhpUnit1', 'Testing1' ), ( 'PhpUnit2', 'Testing2' )"
		);

		$this->assertTableEmpty( 'wp_comments', false );

		$this->assertQuery(
			'TRUNCATE TABLE wp_comments;'
		);
		$actual = $this->engine->get_query_results();
		$this->assertEquals(
			true,
			$actual
		);
		$this->assertTableEmpty( 'wp_comments', true );
	}

	public function testBogusQuery() {
		$this->expectExceptionMessage( 'no such table: bogus' );
		$this->assertQuery(
			'SELECT 1, BOGUS(1) FROM bogus;'
		);
	}
}
