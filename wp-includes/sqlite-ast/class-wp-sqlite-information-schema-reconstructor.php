<?php

/*
 * The SQLite driver uses PDO. Enable PDO function calls:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * SQLite information schema recconstructor for MySQL.
 *
 * This class checks and reconstructs the MySQL INFORMATION_SCHEMA data in SQLite
 * when it becomes out of sync with the actual SQLite database schema.
 *
 * Currently, it reconstructs schema infromation for missing tables, and removes
 * stale data for tables that no longer exist. When used with WordPress, it uses
 * the "wp_get_db_schema()" function to reconstruct WordPress table information.
 */
class WP_SQLite_Information_Schema_Reconstructor {
	/**
	 * The SQLite driver instance.
	 *
	 * @var WP_SQLite_Driver
	 */
	private $driver;

	/**
	 * A service for managing MySQL INFORMATION_SCHEMA tables in SQLite.
	 *
	 * @var WP_SQLite_Information_Schema_Builder
	 */
	private $information_schema_builder;

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_Driver                     $driver                     The SQLite driver instance.
	 * @param WP_SQLite_Information_Schema_Builder $information_schema_builder The information schema builder instance.
	 */
	public function __construct(
		WP_SQLite_Driver $driver,
		WP_SQLite_Information_Schema_Builder $information_schema_builder
	) {
		$this->driver                     = $driver;
		$this->information_schema_builder = $information_schema_builder;
	}

	/**
	 * Ensure that the MySQL INFORMATION_SCHEMA data in SQLite is correct.
	 *
	 * This method checks if the MySQL INFORMATION_SCHEMA data in SQLite is correct,
	 * and if it is not, it will reconstruct the data.
	 */
	public function ensure_correct_information_schema(): void {
		$tables                    = $this->get_existing_table_names();
		$information_schema_tables = $this->get_information_schema_table_names();

		// In WordPress, use "wp_get_db_schema()" to reconstruct WordPress tables.
		$wp_tables = array();
		if ( defined( 'ABSPATH' ) ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/schema.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/schema.php';
			}
			if ( ! function_exists( 'wp_get_db_schema' ) ) {
				throw new Exception( 'The "wp_get_db_schema()" function was not defined.' );
			}
			$schema = wp_get_db_schema();
			foreach ( $this->driver->parse_query( $schema ) as $query ) {
				$create_node = $query->get_first_descendant_node( 'createStatement' );
				if ( $create_node && $create_node->has_child_node( 'createTable' ) ) {
					$name_node = $create_node->get_first_descendant_node( 'tableName' );
					$name      = $this->unquote_mysql_identifier(
						substr( $schema, $name_node->get_start(), $name_node->get_length() )
					);

					$wp_tables[ $name ] = $create_node;
				}
			}
		}

		// Reconstruct information schema records for tables that don't have them.
		foreach ( $tables as $table ) {
			if ( ! in_array( $table, $information_schema_tables, true ) ) {
				if ( isset( $wp_tables[ $table ] ) ) {
					// WordPress core table (as returned by "wp_get_db_schema()").
					$ast = $wp_tables[ $table ];
				} else {
					// Other table (a WordPress plugin or unrelated to WordPress).
					$sql = $this->generate_create_table_statement( $table );
					$ast = $this->driver->parse_query( $sql )->current();
				}
				$this->information_schema_builder->record_create_table( $ast );
			}
		}

		// Remove information schema records for tables that don't exist.
		foreach ( $information_schema_tables as $table ) {
			if ( ! in_array( $table, $tables, true ) ) {
				$sql = sprintf( 'DROP %s', $this->quote_sqlite_identifier( $table ) );
				$ast = $this->driver->parse_query( $sql )->current();
				$this->information_schema_builder->record_drop_table( $ast );
			}
		}
	}

	/**
	 * Get the names of all existing tables in the SQLite database.
	 *
	 * @return string[] The names of tables in the SQLite database.
	 */
	private function get_existing_table_names(): array {
		return $this->driver->execute_sqlite_query(
			"
				SELECT name
				FROM sqlite_schema
				WHERE type = 'table'
				AND name NOT LIKE ? ESCAPE '\'
				AND name NOT LIKE ? ESCAPE '\'
				ORDER BY name
			",
			array(
				'sqlite\_%',
				str_replace( '_', '\_', WP_SQLite_Driver::RESERVED_PREFIX ) . '%',
			)
		)->fetchAll( PDO::FETCH_COLUMN );
	}

	/**
	 * Get the names of all tables recorded in the information schema.
	 *
	 * @return string[] The names of tables in the information schema.
	 */
	private function get_information_schema_table_names(): array {
		$tables_table = $this->information_schema_builder->get_table_name( false, 'tables' );
		return $this->driver->execute_sqlite_query(
			"SELECT table_name FROM $tables_table ORDER BY table_name"
		)->fetchAll( PDO::FETCH_COLUMN );
	}

	/**
	 * Generate a MySQL CREATE TABLE statement from an SQLite table definition.
	 *
	 * @param  string $table_name The name of the table.
	 * @return string             The CREATE TABLE statement.
	 */
	private function generate_create_table_statement( string $table_name ): string {
		// Columns.
		$columns = $this->driver->execute_sqlite_query(
			sprintf( 'PRAGMA table_xinfo("%s")', $table_name )
		)->fetchAll( PDO::FETCH_ASSOC );

		$definitions = array();
		$data_types  = array();
		foreach ( $columns as $column ) {
			$mysql_type = $this->get_cached_mysql_data_type( $table_name, $column['name'] );
			if ( null === $mysql_type ) {
				$mysql_type = $this->get_mysql_data_type( $column['type'] );
			}
			$definitions[]                 = $this->get_column_definition( $table_name, $column );
			$data_types[ $column['name'] ] = $mysql_type;
		}

		// Primary key.
		$pk_columns = array();
		foreach ( $columns as $column ) {
			// A position of the column in the primary key, starting from index 1.
			// A value of 0 means that the column is not part of the primary key.
			$pk_position = (int) $column['pk'];
			if ( 0 !== $pk_position ) {
				$pk_columns[ $pk_position ] = $column['name'];
			}
		}

		// Sort the columns by their position in the primary key.
		ksort( $pk_columns );

		if ( count( $pk_columns ) > 0 ) {
			$quoted_pk_columns = array();
			foreach ( $pk_columns as $pk_column ) {
				$quoted_pk_columns[] = $this->quote_sqlite_identifier( $pk_column );
			}
			$definitions[] = sprintf( 'PRIMARY KEY (%s)', implode( ', ', $quoted_pk_columns ) );
		}

		// Indexes and keys.
		$keys = $this->driver->execute_sqlite_query(
			'SELECT * FROM pragma_index_list("' . $table_name . '")'
		)->fetchAll( PDO::FETCH_ASSOC );

		foreach ( $keys as $key ) {
			$key_columns = $this->driver->execute_sqlite_query(
				'SELECT * FROM pragma_index_info("' . $key['name'] . '")'
			)->fetchAll( PDO::FETCH_ASSOC );

			// If the PK columns are the same as the UK columns, skip the key.
			// This is because a primary key is already unique in MySQL.
			$key_equals_pk = ! array_diff( $pk_columns, array_column( $key_columns, 'name' ) );
			$is_auto_index = strpos( $key['name'], 'sqlite_autoindex_' ) === 0;
			if ( $is_auto_index && $key['unique'] && $key_equals_pk ) {
				continue;
			}
			$definitions[] = $this->get_key_definition( $key, $key_columns, $data_types );
		}

		return sprintf(
			"CREATE TABLE %s (\n  %s\n)",
			$this->quote_sqlite_identifier( $table_name ),
			implode( ",\n  ", $definitions )
		);
	}

	/**
	 * Generate a MySQL column definition from an SQLite column information.
	 *
	 * This method generates a MySQL column definition from SQLite column data.
	 *
	 * @param  string $table_name  The name of the table.
	 * @param  array  $column_info The SQLite column information.
	 * @return string              The MySQL column definition.
	 */
	private function get_column_definition( string $table_name, array $column_info ): string {
		$definition   = array();
		$definition[] = $this->quote_sqlite_identifier( $column_info['name'] );

		// Data type.
		$mysql_type = $this->get_cached_mysql_data_type( $table_name, $column_info['name'] );
		if ( null === $mysql_type ) {
			$mysql_type = $this->get_mysql_data_type( $column_info['type'] );
		}
		$definition[] = $mysql_type;

		// NULL/NOT NULL.
		if ( '1' === $column_info['notnull'] ) {
			$definition[] = 'NOT NULL';
		}

		// Auto increment.
		$is_auto_increment = false;
		if ( '0' !== $column_info['pk'] ) {
			$is_auto_increment = $this->driver->execute_sqlite_query(
				'SELECT 1 FROM sqlite_schema WHERE tbl_name = ? AND sql LIKE ?',
				array( $table_name, '%AUTOINCREMENT%' )
			)->fetchColumn();

			if ( $is_auto_increment ) {
				$definition[] = 'AUTO_INCREMENT';
			}
		}

		// Default value.
		if ( $this->column_has_default( $mysql_type, $column_info['dflt_value'] ) && ! $is_auto_increment ) {
			$definition[] = 'DEFAULT ' . $column_info['dflt_value'];
		}

		return implode( ' ', $definition );
	}

	/**
	 * Generate a MySQL key definition from an SQLite key information.
	 *
	 * This method generates a MySQL key definition from SQLite key data.
	 *
	 * @param  array  $key         The SQLite key information.
	 * @param  array  $key_columns The SQLite key column information.
	 * @param  array  $data_types  The MySQL data types of the columns.
	 * @return string              The MySQL key definition.
	 */
	private function get_key_definition( array $key, array $key_columns, array $data_types ): string {
		$key_length_limit = 100;

		// Key definition.
		$definition = array();
		if ( $key['unique'] ) {
			$definition[] = 'UNIQUE';
		}
		$definition[] = 'KEY';

		// Remove the prefix from the index name if there is any. We use __ as a separator.
		$index_name   = explode( '__', $key['name'], 2 )[1] ?? $key['name'];
		$definition[] = $this->quote_sqlite_identifier( $index_name );

		// Key columns.
		$cols = array();
		foreach ( $key_columns as $column ) {
			// Get data type and length.
			$data_type = strtolower( $data_types[ $column['name'] ] );
			if ( 1 === preg_match( '/^(\w+)\s*\(\s*(\d+)\s*\)/', $data_type, $matches ) ) {
				$data_type   = $matches[1]; // "varchar"
				$data_length = min( $matches[2], $key_length_limit ); // "255"
			}

			// Apply max length if needed.
			if (
				str_contains( $data_type, 'char' )
				|| str_starts_with( $data_type, 'var' )
				|| str_ends_with( $data_type, 'text' )
				|| str_ends_with( $data_type, 'blob' )
			) {
				$cols[] = sprintf(
					'%s(%d)',
					$this->quote_sqlite_identifier( $column['name'] ),
					$data_length ?? $key_length_limit
				);
			} else {
				$cols[] = $this->quote_sqlite_identifier( $column['name'] );
			}
		}

		$definition[] = '(' . implode( ', ', $cols ) . ')';
		return implode( ' ', $definition );
	}

	/**
	 * Determine if a column has a default value.
	 *
	 * @param  string      $mysql_type    The MySQL data type of the column.
	 * @param  string|null $default_value The default value of the SQLite column.
	 * @return bool                       True if the column has a default value, false otherwise.
	 */
	private function column_has_default( string $mysql_type, ?string $default_value ): bool {
		if ( null === $default_value || '' === $default_value ) {
			return false;
		}
		if (
			"''" === $default_value
			&& in_array( strtolower( $mysql_type ), array( 'datetime', 'date', 'time', 'timestamp', 'year' ), true )
		) {
			return false;
		}
		return true;
	}

	/**
	 * Get a MySQL column or index data type from legacy data types cache table.
	 *
	 * This method retrieves MySQL column or index data types from a special table
	 * that was used by an old version of the SQLite driver and that is otherwise
	 * no longer needed. This is more precise than direct inference from SQLite.
	 *
	 * @param  string      $table_name           The table name.
	 * @param  string      $column_or_index_name The column or index name.
	 * @return string|null                       The MySQL definition, or null when not found.
	 */
	private function get_cached_mysql_data_type( string $table_name, string $column_or_index_name ): ?string {
		try {
			$mysql_type = $this->driver->execute_sqlite_query(
				'SELECT mysql_type FROM _mysql_data_types_cache WHERE `table` = ? AND column_or_index = ?',
				array( $table_name, $column_or_index_name )
			)->fetchColumn();
		} catch ( PDOException $e ) {
			if ( str_contains( $e->getMessage(), 'no such table' ) ) {
				return null;
			}
			throw $e;
		}
		if ( str_ends_with( $mysql_type, ' KEY' ) ) {
			$mysql_type = substr( $mysql_type, 0, strlen( $mysql_type ) - strlen( ' KEY' ) );
		}
		return $mysql_type;
	}

	/**
	 * Get a MySQL column type from an SQLite column type.
	 *
	 * This method converts an SQLite column type to a MySQL column type as per
	 * the SQLite column type affinity rules:
	 *   https://sqlite.org/datatype3.html#determination_of_column_affinity
	 *
	 * @param  string $column_type The SQLite column type.
	 * @return string              The MySQL column type.
	 */
	private function get_mysql_data_type( string $column_type ): string {
		$type = strtoupper( $column_type );

		/*
		 * Following the rules of column affinity:
		 *   https://sqlite.org/datatype3.html#determination_of_column_affinity
		 */

		// 1. If the declared type contains the string "INT" then it is assigned
		//    INTEGER affinity.
		if ( str_contains( $type, 'INT' ) ) {
			return 'int';
		}

		// 2. If the declared type of the column contains any of the strings
		//    "CHAR", "CLOB", or "TEXT" then that column has TEXT affinity.
		if ( str_contains( $type, 'TEXT' ) || str_contains( $type, 'CHAR' ) || str_contains( $type, 'CLOB' ) ) {
			return 'text';
		}

		// 3. If the declared type for a column contains the string "BLOB" or
		//    if no type is specified then the column has affinity BLOB.
		if ( str_contains( $type, 'BLOB' ) || '' === $type ) {
			return 'blob';
		}

		// 4. If the declared type for a column contains any of the strings
		//    "REAL", "FLOA", or "DOUB" then the column has REAL affinity.
		if ( str_contains( $type, 'REAL' ) || str_contains( $type, 'FLOA' ) ) {
			return 'float';
		}
		if ( str_contains( $type, 'DOUB' ) ) {
			return 'double';
		}

		/**
		 * 5. Otherwise, the affinity is NUMERIC.
		 *
		 * While SQLite defaults to a NUMERIC column affinity, it's better to use
		 * TEXT in this case, because numeric SQLite columns in non-strict tables
		 * can contain any text data as well, when it is not a well-formed number.
		 *
		 * See: https://sqlite.org/datatype3.html#type_affinity
		 */
		return 'text';
	}

	/**
	 * Quote an SQLite identifier.
	 *
	 * Wrap the identifier in backticks and escape backtick values within.
	 *
	 * @param  string $unquoted_identifier The unquoted identifier value.
	 * @return string                      The quoted identifier value.
	 */
	private function quote_sqlite_identifier( string $unquoted_identifier ): string {
		return '`' . str_replace( '`', '``', $unquoted_identifier ) . '`';
	}

	/**
	 * Unquote a quoted MySQL identifier.
	 *
	 * Remove bounding quotes and replace escaped quotes with their values.
	 *
	 * @param  string $quoted_identifier The quoted identifier value.
	 * @return string                    The unquoted identifier value.
	 */
	private function unquote_mysql_identifier( string $quoted_identifier ): string {
		$first_byte = $quoted_identifier[0] ?? null;
		if ( '"' === $first_byte || '`' === $first_byte ) {
			$unquoted = substr( $quoted_identifier, 1, -1 );
			return str_replace( $first_byte . $first_byte, $first_byte, $unquoted );
		}
		return $quoted_identifier;
	}
}
