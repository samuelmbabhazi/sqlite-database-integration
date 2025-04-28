<?php declare(strict_types = 1);

/*
 * The SQLite connection uses PDO. Enable PDO function calls:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * SQLite connection.
 *
 * This class configures and encapsulates the connection to an SQLite database.
 * It requires PDO with the SQLite driver, and currently, it is only a simple
 * wrapper that leaks some of the PDO APIs (returns PDOStatement values, etc.).
 * In the future, we may abstract it away from PDO and support SQLite3 as well.
 */
class WP_SQLite_Connection {
	/**
	 * The default timeout in seconds for SQLite to wait for a writable lock.
	 */
	const DEFAULT_SQLITE_TIMEOUT = 10;

	/**
	 * The supported SQLite journal modes.
	 *
	 * See: https://www.sqlite.org/pragma.html#pragma_journal_mode
	 */
	const SQLITE_JOURNAL_MODES = array(
		'DELETE',
		'TRUNCATE',
		'PERSIST',
		'MEMORY',
		'WAL',
		'OFF',
	);

	/**
	 * The PDO connection for SQLite.
	 *
	 * @var PDO
	 */
	private $pdo;

	/**
	 * A query logger callback.
	 *
	 * @var callable(string, array): void
	 */
	private $query_logger;

	/**
	 * Constructor.
	 *
	 * Set up an SQLite connection.
	 *
	 * @param array $options {
	 *     An array of options.
	 *
	 *     @type string|null $path         Optional. SQLite database path.
	 *                                     For in-memory database, use ':memory:'.
	 *                                     Must be set when PDO instance is not provided.
	 *     @type PDO|null    $pdo          Optional. PDO instance with SQLite connection.
	 *                                     If not provided, a new PDO instance will be created.
	 *     @type int|null    $timeout      Optional. SQLite timeout in seconds.
	 *                                     The time to wait for a writable lock.
	 *     @type string|null $journal_mode Optional. SQLite journal mode.
	 * }
	 *
	 * @throws InvalidArgumentException When some connection options are invalid.
	 * @throws PDOException             When the driver initialization fails.
	 */
	public function __construct( array $options ) {
		// Setup PDO connection.
		if ( isset( $options['pdo'] ) && $options['pdo'] instanceof PDO ) {
			$this->pdo = $options['pdo'];
		} else {
			if ( ! isset( $options['path'] ) || ! is_string( $options['path'] ) ) {
				throw new InvalidArgumentException( 'Option "path" is required when "connection" is not provided.' );
			}
			$this->pdo = new PDO( 'sqlite:' . $options['path'] );
		}

		// Throw exceptions on error.
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		// Configure SQLite timeout.
		if ( isset( $options['timeout'] ) && is_int( $options['timeout'] ) ) {
			$timeout = $options['timeout'];
		} else {
			$timeout = self::DEFAULT_SQLITE_TIMEOUT;
		}
		$this->pdo->setAttribute( PDO::ATTR_TIMEOUT, $timeout );

		// Return all values (except null) as strings.
		$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );

		// Configure SQLite journal mode.
		$journal_mode = $options['journal_mode'] ?? null;
		if ( $journal_mode && in_array( $journal_mode, self::SQLITE_JOURNAL_MODES, true ) ) {
			$this->query( 'PRAGMA journal_mode = ' . $journal_mode );
		}
	}

	/**
	 * Execute a query in SQLite.
	 *
	 * @param  string $sql   The query to execute.
	 * @param  array $params The query parameters.
	 * @throws PDOException  When the query execution fails.
	 * @return PDOStatement  The PDO statement object.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, $params );
		}
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( $params );
		return $stmt;
	}

	/**
	 * Returns the ID of the last inserted row.
	 *
	 * @return string The ID of the last inserted row.
	 */
	public function get_last_insert_id(): string {
		return $this->pdo->lastInsertId();
	}

	/**
	 * Quote a value for use in a query.
	 *
	 * @param  mixed  $value The value to quote.
	 * @param  int    $type  The type of the value.
	 * @return string        The quoted value.
	 */
	public function quote( $value, int $type = PDO::PARAM_STR ): string {
		return $this->pdo->quote( $value, $type );
	}

	/**
	 * Get the PDO object.
	 *
	 * @return PDO
	 */
	public function get_pdo(): PDO {
		return $this->pdo;
	}

	/**
	 * Set a logger for the queries.
	 *
	 * @param callable(string, array): void $logger A query logger callback.
	 */
	public function set_query_logger( callable $logger ): void {
		$this->query_logger = $logger;
	}
}
