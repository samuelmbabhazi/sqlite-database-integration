<?php

use PHPUnit\Framework\TestCase;

class WP_SQLite_Information_Schema_Reconstructor_Tests extends TestCase {
	/** @var WP_SQLite_Driver */
	private $engine;

	/** @var WP_SQLite_Information_Schema_Reconstructor */
	private $reconstructor;

	/** @var PDO */
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

		$builder = new WP_SQLite_Information_Schema_Builder(
			'wp',
			WP_SQLite_Driver::RESERVED_PREFIX,
			array( $this->engine, 'execute_sqlite_query' )
		);

		$this->reconstructor = new WP_SQLite_Information_Schema_Reconstructor(
			$this->engine,
			$builder
		);
	}

	public function testReconstructInformationSchemaTable(): void {
		$this->engine->get_pdo()->exec(
			'
			CREATE TABLE t (
			  id INTEGER PRIMARY KEY AUTOINCREMENT,
			  email TEXT NOT NULL UNIQUE,
			  name TEXT NOT NULL,
			  role TEXT,
			  score REAL,
			  priority INTEGER DEFAULT 0,
			  data BLOB,
			  UNIQUE (name)
			)
		'
		);
		$this->engine->get_pdo()->exec( 'CREATE INDEX idx_score ON t (score)' );
		$this->engine->get_pdo()->exec( 'CREATE INDEX idx_role_score ON t (role, priority)' );
		$result = $this->assertQuery( 'SELECT * FROM information_schema.tables WHERE table_name = "t"' );
		$this->assertEquals( 0, count( $result ) );

		$this->reconstructor->ensure_correct_information_schema();
		$result = $this->assertQuery( 'SELECT * FROM information_schema.tables WHERE table_name = "t"' );
		$this->assertEquals( 1, count( $result ) );

		$result = $this->assertQuery( 'SHOW CREATE TABLE t' );
		$this->assertSame(
			implode(
				"\n",
				array(
					'CREATE TABLE `t` (',
					'  `id` int NOT NULL AUTO_INCREMENT,',
					'  `email` text NOT NULL,',
					'  `name` text NOT NULL,',
					'  `role` text DEFAULT NULL,',
					'  `score` float DEFAULT NULL,',
					"  `priority` int DEFAULT '0',",
					'  `data` blob DEFAULT NULL,',
					'  PRIMARY KEY (`id`),',
					'  KEY `idx_role_score` (`role`(100), `priority`),',
					'  KEY `idx_score` (`score`),',
					'  UNIQUE KEY `sqlite_autoindex_t_2` (`name`(100)),',
					'  UNIQUE KEY `sqlite_autoindex_t_1` (`email`(100))',
					') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
				)
			),
			$result[0]->{'Create Table'}
		);
	}

	private function assertQuery( $sql ) {
		$retval = $this->engine->query( $sql );
		$this->assertNotFalse( $retval );
		return $retval;
	}
}
