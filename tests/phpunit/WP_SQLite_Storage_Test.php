<?php

require_once WP_CONTENT_DIR . '/plugins/sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-storage.php';

class WP_SQLite_Storage_Test extends WP_UnitTestCase {

	/**
	 * @var string[]
	 */
	private $temporary_directories = array();

	public function tear_down() {
		foreach ( $this->temporary_directories as $directory ) {
			$this->remove_directory( $directory );
		}

		parent::tear_down();
	}

	public function test_creates_randomized_database_storage() {
		$storage_root  = $this->create_temporary_directory_path();
		$database_root = $storage_root . '/nested/database';
		$umask         = umask( 0777 );

		try {
			$database_path = $this->initialize_managed_storage( $database_root );
		} finally {
			umask( $umask );
		}

		$database_path_file   = $database_root . '/db-path.php';
		$stored_database_path = require $database_path_file;

		$this->assertSame( $database_path, $stored_database_path );
		$this->assertSame( 1, preg_match( '/\A\.ht\.[0-9a-f]{32}\z/', basename( dirname( $stored_database_path ) ) ) );
		$this->assertFileExists( $database_path );
		$this->assertSame( 0, filesize( $database_path ) );
		$this->assertSame( 0600, fileperms( $database_path ) & 0777 );
		$this->assertSame( 0600, fileperms( $database_path_file ) & 0777 );
		$this->assertSame( 0600, fileperms( $database_root . '/.ht.sqlite.lock' ) & 0777 );
		$this->assertStringContainsString(
			'IMPORTANT: Keep this path secret. When possible, point it outside the document root.',
			file_get_contents( $database_path_file )
		);
		$this->assertSame( 0700, fileperms( $storage_root ) & 0777 );
		$this->assertSame( 0700, fileperms( $storage_root . '/nested' ) & 0777 );
		$this->assert_protected_directory( $database_root );
		$this->assert_protected_directory( dirname( $database_path ) );
	}

	public function test_reuses_initialized_storage_without_repairing_protection_files() {
		$database_root = $this->create_temporary_directory_path();
		$first_path    = $this->initialize_managed_storage( $database_root );
		$this->assertTrue( unlink( $database_root . '/.htaccess' ) );
		$this->assertTrue( unlink( dirname( $first_path ) . '/index.php' ) );

		$second_path = $this->initialize_managed_storage( $database_root );

		$this->assertSame( $first_path, $second_path );
		$this->assertFileDoesNotExist( $database_root . '/.htaccess' );
		$this->assertFileDoesNotExist( dirname( $second_path ) . '/index.php' );
	}

	public function test_reuses_initialized_storage_with_read_only_database_root() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $this->initialize_managed_storage( $database_root );
		$this->assertTrue( chmod( $database_root, 0500 ) );

		try {
			$this->assertSame( $database_path, $this->initialize_managed_storage( $database_root ) );
		} finally {
			$this->assertTrue( chmod( $database_root, 0700 ) );
		}
	}

	public function test_recovers_an_interrupted_database_path_write() {
		$database_root = $this->create_temporary_directory_path();
		$this->create_directory( $database_root );
		file_put_contents( $database_root . '/.ht.db-path.php', 'interrupted write' );

		$database_path = $this->initialize_managed_storage( $database_root );

		$this->assertFileExists( $database_path );
		$this->assertFileExists( $database_root . '/db-path.php' );
		$this->assertFileDoesNotExist( $database_root . '/.ht.db-path.php' );
	}

	public function test_database_path_file_returns_path_without_direct_output() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $this->initialize_managed_storage( $database_root );

		ob_start();
		$stored_database_path = require $database_root . '/db-path.php';
		$output               = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertSame( $database_path, $stored_database_path );
	}

	public function test_rejects_a_database_path_file_that_does_not_return_a_path() {
		$database_root = $this->create_temporary_directory_path();
		$this->create_directory( $database_root );
		file_put_contents( $database_root . '/db-path.php', "<?php\nreturn array();\n" );

		try {
			$this->initialize_managed_storage( $database_root );
			$this->fail( 'An invalid database path file was accepted.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'database path file is invalid', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_root, $exception->getMessage() );
		}
	}

	public function test_handles_a_database_path_file_that_cannot_be_loaded() {
		$database_path_file = $this->create_temporary_directory_path() . '/missing-db-path.php';
		$storage            = new WP_SQLite_Storage( dirname( $database_path_file ) );
		$read_database_path = Closure::bind(
			function () use ( $database_path_file ) {
				return $this->read_database_path( $database_path_file );
			},
			$storage,
			WP_SQLite_Storage::class
		);

		try {
			$read_database_path();
			$this->fail( 'A missing database path file was loaded.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to read the SQLite database path file.', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_path_file, $exception->getMessage() );
		}
	}

	public function test_recovers_a_missing_database_referenced_by_the_path_file() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $database_root . '/.ht.0123456789abcdef0123456789abcdef/.ht.sqlite';
		$this->create_directory( $database_root );
		file_put_contents(
			$database_root . '/db-path.php',
			"<?php\nreturn __DIR__ . '/.ht.0123456789abcdef0123456789abcdef/.ht.sqlite';\n"
		);

		$this->assertSame( $database_path, $this->initialize_managed_storage( $database_root ) );
		$this->assertFileExists( $database_path );
		$this->assertSame( 0600, fileperms( $database_path ) & 0777 );
		$this->assert_protected_directory( $database_root );
		$this->assert_protected_directory( dirname( $database_path ) );
	}

	public function test_initializes_an_explicit_database_file() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $database_root . '/custom/database.sqlite';
		$storage       = new WP_SQLite_Storage( $database_root );

		$this->assertSame( $database_path, $storage->initialize( $database_path ) );
		$this->assertFileExists( $database_path );
		$this->assertSame( 0, filesize( $database_path ) );
		$this->assertSame( 0600, fileperms( $database_path ) & 0777 );
		$this->assertFileDoesNotExist( $database_path . '.lock' );
		$this->assertFileDoesNotExist( $database_root . '/db-path.php' );
		$this->assert_protected_directory( dirname( $database_path ) );
	}

	public function test_initializes_an_in_memory_database_without_creating_files() {
		$working_directory = $this->create_temporary_directory_path();
		$this->create_directory( $working_directory );
		$previous_working_directory = getcwd();
		$this->assertNotFalse( $previous_working_directory );
		$this->assertTrue( chdir( $working_directory ) );

		try {
			$storage = new WP_SQLite_Storage( $this->create_temporary_directory_path() );
			$this->assertSame( ':memory:', $storage->initialize( ':memory:' ) );
		} finally {
			$this->assertTrue( chdir( $previous_working_directory ) );
		}

		$this->assertSame( array( '.', '..' ), scandir( $working_directory ) );
	}

	public function test_rejects_an_empty_database_path_without_creating_files() {
		$working_directory = $this->create_temporary_directory_path();
		$this->create_directory( $working_directory );
		$previous_working_directory = getcwd();
		$this->assertNotFalse( $previous_working_directory );
		$this->assertTrue( chdir( $working_directory ) );

		try {
			$storage = new WP_SQLite_Storage( $this->create_temporary_directory_path() );
			$storage->initialize( '' );
			$this->fail( 'An empty database path was accepted.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'The SQLite database path is invalid.', $exception->getMessage() );
		} finally {
			$this->assertTrue( chdir( $previous_working_directory ) );
		}

		$this->assertSame( array( '.', '..' ), scandir( $working_directory ) );
	}

	public function test_preserves_an_existing_explicit_database_file() {
		$database_root = $this->create_temporary_directory_path();
		$database_path = $database_root . '/custom.sqlite';
		$storage       = new WP_SQLite_Storage( $database_root );
		$this->create_sqlite_database( $database_path );
		chmod( $database_path, 0640 );

		$this->assertSame( $database_path, $storage->initialize( $database_path ) );
		$this->assertSame( 'preserved', $this->read_sqlite_value( $database_path ) );
		$this->assertSame( 0640, fileperms( $database_path ) & 0777 );
		$this->assert_protected_directory( $database_root );
	}

	public function test_does_not_expose_the_database_path_when_initialization_fails() {
		$database_root = $this->create_temporary_directory_path();
		$blocking_path = $database_root . '/blocking-file';
		$database_path = $blocking_path . '/.ht.secret/.ht.sqlite';
		$this->create_directory( $database_root );
		file_put_contents( $blocking_path, '' );

		try {
			$storage = new WP_SQLite_Storage( $database_root );
			$storage->initialize( $database_path );
			$this->fail( 'An inaccessible database path was initialized.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Failed to create the SQLite database directory.', $exception->getMessage() );
			$this->assertStringNotContainsString( $database_path, $exception->getMessage() );
		}
	}

	public function test_preserves_the_current_database_filename() {
		$this->assert_legacy_database_is_preserved( '.ht.sqlite' );
	}

	public function test_preserves_the_older_database_filename() {
		$this->assert_legacy_database_is_preserved( '.ht.sqlite.php' );
	}

	private function assert_legacy_database_is_preserved( $filename ) {
		$database_root = $this->create_temporary_directory_path();
		$this->create_directory( $database_root );
		touch( $database_root . '/' . $filename );

		$this->assertSame( $database_root . '/' . $filename, $this->initialize_managed_storage( $database_root ) );
		$this->assertFileDoesNotExist( $database_root . '/db-path.php' );
	}

	private function initialize_managed_storage( $database_root ) {
		$storage = new WP_SQLite_Storage( $database_root );

		return $storage->initialize();
	}

	private function create_temporary_directory_path() {
		$path = tempnam( sys_get_temp_dir(), 'wp-sqlite-storage-' );
		$this->assertNotFalse( $path );
		$this->assertTrue( unlink( $path ) );
		$this->temporary_directories[] = $path;

		return $path;
	}

	private function create_sqlite_database( $database_path ) {
		$this->create_directory( dirname( $database_path ) );
		$connection = new PDO( 'sqlite:' . $database_path );
		$connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$connection->exec( 'CREATE TABLE storage_test (value TEXT NOT NULL)' );
		$connection->exec( "INSERT INTO storage_test VALUES ('preserved')" );

		return $connection;
	}

	private function read_sqlite_value( $database_path ) {
		$connection = new PDO( 'sqlite:' . $database_path );

		return $connection->query( 'SELECT value FROM storage_test' )->fetchColumn();
	}

	private function create_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			$this->assertTrue( mkdir( $directory, 0700, true ) );
		}
	}

	private function assert_protected_directory( $directory ) {
		clearstatcache( true, $directory );
		$this->assertSame( 0700, fileperms( $directory ) & 0777 );
		$this->assertSame( 'DENY FROM ALL', file_get_contents( $directory . '/.htaccess' ) );
		$this->assertSame( 0600, fileperms( $directory . '/.htaccess' ) & 0777 );
		$this->assertSame( '<?php // Silence is golden.', file_get_contents( $directory . '/index.php' ) );
		$this->assertSame( 0600, fileperms( $directory . '/index.php' ) & 0777 );
	}

	private function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $directory );
	}
}
