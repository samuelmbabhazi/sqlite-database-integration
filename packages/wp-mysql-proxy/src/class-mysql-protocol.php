<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy;

class MySQL_Protocol {
	// MySQL client/server capability flags (partial list)
	const CLIENT_LONG_FLAG                      = 0x00000004;  // Supports longer flags
	const CLIENT_CONNECT_WITH_DB                = 0x00000008;
	const CLIENT_PROTOCOL_41                    = 0x00000200;
	const CLIENT_SECURE_CONNECTION              = 0x00008000;
	const CLIENT_MULTI_STATEMENTS               = 0x00010000;
	const CLIENT_MULTI_RESULTS                  = 0x00020000;
	const CLIENT_PS_MULTI_RESULTS               = 0x00040000;
	const CLIENT_PLUGIN_AUTH                    = 0x00080000;
	const CLIENT_CONNECT_ATTRS                  = 0x00100000;
	const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;
	const CLIENT_DEPRECATE_EOF                  = 0x01000000;

	// MySQL status flags
	const SERVER_STATUS_AUTOCOMMIT = 0x0002;

	/**
	 * MySQL command types
	 *
	 * @see https://dev.mysql.com/doc/dev/mysql-server/8.4.3/page_protocol_command_phase.html
	 */
	const COM_SLEEP               = 0x00; /** Tells the server to sleep for the given number of seconds. */
	const COM_QUIT                = 0x01; /** Tells the server that the client wants it to close the connection. */
	const COM_INIT_DB             = 0x02; /** Change the default schema of the connection. */
	const COM_QUERY               = 0x03; /** Tells the server to execute a query. */
	const COM_FIELD_LIST          = 0x04; /** Deprecated. Returns the list of fields for the given table. */
	const COM_CREATE_DB           = 0x05; /** Currently refused by the server. */
	const COM_DROP_DB             = 0x06; /** Currently refused by the server. */
	const COM_UNUSED_2            = 0x07; /** Unused. Used to be COM_REFRESH. */
	const COM_UNUSED_1            = 0x08; /** Unused. Used to be COM_SHUTDOWN. */
	const COM_STATISTICS          = 0x09; /** Get a human readable string of some internal status vars. */
	const COM_UNUSED_4            = 0x0A; /** Unused. Used to be COM_PROCESS_INFO. */
	const COM_CONNECT             = 0x0B; /** Currently refused by the server. */
	const COM_UNUSED_5            = 0x0C; /** Unused. Used to be COM_PROCESS_KILL. */
	const COM_DEBUG               = 0x0D; /** Dump debug info to server's stdout. */
	const COM_PING                = 0x0E; /** Check if the server is alive. */
	const COM_TIME                = 0x0F; /** Currently refused by the server. */
	const COM_DELAYED_INSERT      = 0x10; /** Functionality removed. */
	const COM_CHANGE_USER         = 0x11; /** Change the user of the connection. */
	const COM_BINLOG_DUMP         = 0x12; /** Tells the server to send the binlog dump. */
	const COM_TABLE_DUMP          = 0x13; /** Tells the server to send the table dump. */
	const COM_CONNECT_OUT         = 0x14; /** Currently refused by the server. */
	const COM_REGISTER_SLAVE      = 0x15; /** Tells the server to register a slave. */
	const COM_STMT_PREPARE        = 0x16; /** Tells the server to prepare a statement. */
	const COM_STMT_EXECUTE        = 0x17; /** Tells the server to execute a prepared statement. */
	const COM_STMT_SEND_LONG_DATA = 0x18; /** Tells the server to send long data for a prepared statement. */
	const COM_STMT_CLOSE          = 0x19; /** Tells the server to close a prepared statement. */
	const COM_STMT_RESET          = 0x1A; /** Tells the server to reset a prepared statement. */
	const COM_SET_OPTION          = 0x1B; /** Tells the server to set an option. */
	const COM_STMT_FETCH          = 0x1C; /** Tells the server to fetch a result from a prepared statement. */
	const COM_DAEMON              = 0x1D; /** Currently refused by the server. */
	const COM_BINLOG_DUMP_GTID    = 0x1E; /** Tells the server to send the binlog dump in GTID mode. */
	const COM_RESET_CONNECTION    = 0x1F; /** Tells the server to reset the connection. */
	const COM_CLONE               = 0x20; /** Tells the server to clone a server. */

	// Special packet markers
	const OK_PACKET      = 0x00;
	const EOF_PACKET     = 0xfe;
	const ERR_PACKET     = 0xff;
	const AUTH_MORE_DATA = 0x01;  // followed by 1 byte (caching_sha2_password specific)

	// Auth specific markers for caching_sha2_password
	const CACHING_SHA2_FAST_AUTH = 3;
	const CACHING_SHA2_FULL_AUTH = 4;
	const AUTH_PLUGIN_NAME       = 'caching_sha2_password';

	// Field types
	const FIELD_TYPE_DECIMAL     = 0x00;
	const FIELD_TYPE_TINY        = 0x01;
	const FIELD_TYPE_SHORT       = 0x02;
	const FIELD_TYPE_LONG        = 0x03;
	const FIELD_TYPE_FLOAT       = 0x04;
	const FIELD_TYPE_DOUBLE      = 0x05;
	const FIELD_TYPE_NULL        = 0x06;
	const FIELD_TYPE_TIMESTAMP   = 0x07;
	const FIELD_TYPE_LONGLONG    = 0x08;
	const FIELD_TYPE_INT24       = 0x09;
	const FIELD_TYPE_DATE        = 0x0a;
	const FIELD_TYPE_TIME        = 0x0b;
	const FIELD_TYPE_DATETIME    = 0x0c;
	const FIELD_TYPE_YEAR        = 0x0d;
	const FIELD_TYPE_NEWDATE     = 0x0e;
	const FIELD_TYPE_VARCHAR     = 0x0f;
	const FIELD_TYPE_BIT         = 0x10;
	const FIELD_TYPE_NEWDECIMAL  = 0xf6;
	const FIELD_TYPE_ENUM        = 0xf7;
	const FIELD_TYPE_SET         = 0xf8;
	const FIELD_TYPE_TINY_BLOB   = 0xf9;
	const FIELD_TYPE_MEDIUM_BLOB = 0xfa;
	const FIELD_TYPE_LONG_BLOB   = 0xfb;
	const FIELD_TYPE_BLOB        = 0xfc;
	const FIELD_TYPE_VAR_STRING  = 0xfd;
	const FIELD_TYPE_STRING      = 0xfe;
	const FIELD_TYPE_GEOMETRY    = 0xff;

	// Field flags
	const NOT_NULL_FLAG       = 0x1;
	const PRI_KEY_FLAG        = 0x2;
	const UNIQUE_KEY_FLAG     = 0x4;
	const MULTIPLE_KEY_FLAG   = 0x8;
	const BLOB_FLAG           = 0x10;
	const UNSIGNED_FLAG       = 0x20;
	const ZEROFILL_FLAG       = 0x40;
	const BINARY_FLAG         = 0x80;
	const ENUM_FLAG           = 0x100;
	const AUTO_INCREMENT_FLAG = 0x200;
	const TIMESTAMP_FLAG      = 0x400;
	const SET_FLAG            = 0x800;

	// Character set and collation constants (using utf8mb4 general collation)
	const CHARSET_UTF8MB4 = 0xff;  // Collation ID 255 (utf8mb4_0900_ai_ci)

	// Max packet length constant
	const MAX_PACKET_LENGTH = 0x00ffffff;

	private $current_db = '';

	// Helper: Packets assembly and parsing
	public static function encode_int_8( int $val ): string {
		return chr( $val & 0xff );
	}

	public static function encode_int_16( int $val ): string {
		return pack( 'v', $val & 0xffff );
	}

	public static function encode_int_24( int $val ): string {
		// 3-byte little-endian integer
		return substr( pack( 'V', $val & 0xffffff ), 0, 3 );
	}

	public static function encode_int_32( int $val ): string {
		return pack( 'V', $val );
	}

	public static function encode_length_encoded_int( int $val ): string {
		// Encodes an integer in MySQL's length-encoded format
		if ( $val < 0xfb ) {
			return chr( $val );
		} elseif ( $val <= 0xffff ) {
			return "\xfc" . self::encode_int_16( $val );
		} elseif ( $val <= 0xffffff ) {
			return "\xfd" . self::encode_int_24( $val );
		} else {
			return "\xfe" . pack( 'P', $val ); // 8-byte little-endian for 64-bit
		}
	}

	public static function encode_length_encoded_string( string $str ): string {
		return self::encode_length_encoded_int( strlen( $str ) ) . $str;
	}

	// Hashing for caching_sha2_password (fast auth algorithm)
	public static function sha_256_hash( string $password, string $salt ): string {
		$stage1   = hash( 'sha256', $password, true );
		$stage2   = hash( 'sha256', $stage1, true );
		$scramble = hash( 'sha256', $stage2 . substr( $salt, 0, 20 ), true );
		// XOR stage1 and scramble to get token
		return $stage1 ^ $scramble;
	}

	// Build initial handshake packet (server greeting)
	public static function build_handshake_packet( int $conn_id, string &$auth_plugin_data ): string {
		$protocol_version = 0x0a;                     // Handshake protocol version (10)
		$server_version   = '5.7.30-php-mysql-server'; // Fake server version
		// Generate random auth plugin data (20-byte salt)
		$salt1            = random_bytes( 8 );
		$salt2            = random_bytes( 12 ); // total salt length = 8+12 = 20 bytes (with filler)
		$auth_plugin_data = $salt1 . $salt2;
		// Lower 2 bytes of capability flags
		$cap_flags_lower = (
			self::CLIENT_PROTOCOL_41 |
			self::CLIENT_SECURE_CONNECTION |
			self::CLIENT_PLUGIN_AUTH |
			self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA
		) & 0xffff;
		// Upper 2 bytes of capability flags
		$cap_flags_upper = (
			self::CLIENT_PROTOCOL_41 |
			self::CLIENT_SECURE_CONNECTION |
			self::CLIENT_PLUGIN_AUTH |
			self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA
		) >> 16;
		$charset         = self::CHARSET_UTF8MB4;
		$status_flags    = self::SERVER_STATUS_AUTOCOMMIT;

		// Assemble handshake packet payload
		$payload  = chr( $protocol_version );
		$payload .= $server_version . "\0";
		$payload .= self::encode_int_32( $conn_id );
		$payload .= $salt1;
		$payload .= "\0";  // filler byte
		$payload .= self::encode_int_16( $cap_flags_lower );
		$payload .= chr( $charset );
		$payload .= self::encode_int_16( $status_flags );
		$payload .= self::encode_int_16( $cap_flags_upper );
		$payload .= chr( strlen( $auth_plugin_data ) + 1 );  // auth plugin data length (salt + \0)
		$payload .= str_repeat( "\0", 10 );              // 10-byte reserved filler
		$payload .= $salt2;
		$payload .= "\0";  // terminating NUL for auth-plugin-data-part-2
		$payload .= self::AUTH_PLUGIN_NAME . "\0";
		return $payload;
	}

	// Build OK packet (after successful authentication or query execution)
	public static function build_ok_packet( int $affected_rows = 0, int $last_insert_id = 0 ): string {
		$payload  = chr( self::OK_PACKET );
		$payload .= self::encode_length_encoded_int( $affected_rows );
		$payload .= self::encode_length_encoded_int( $last_insert_id );
		$payload .= self::encode_int_16( self::SERVER_STATUS_AUTOCOMMIT ); // server status
		$payload .= self::encode_int_16( 0 );  // no warning count
		// No human-readable message for simplicity
		return $payload;
	}

	// Build ERR packet (for errors)
	public static function build_err_packet( int $error_code, string $sql_state, string $message ): string {
		$payload  = chr( self::ERR_PACKET );
		$payload .= self::encode_int_16( $error_code );
		$payload .= '#' . strtoupper( $sql_state );
		$payload .= $message;
		return $payload;
	}

	// Build Result Set packets from a SelectQueryResult (column count, column definitions, rows, EOF)
	public static function build_result_set_packets( array $columns, array $rows ): string {
		$sequence_id   = 1;  // Sequence starts at 1 for resultset (after COM_QUERY)
		$packet_stream = '';

		// 1. Column count packet (length-encoded integer for number of columns)
		$col_count         = count( $columns );
		$col_count_payload = self::encode_length_encoded_int( $col_count );
		$packet_stream    .= self::wrap_packet( $col_count_payload, $sequence_id++ );

		// 2. Column definition packets for each column
		foreach ( $columns as $col ) {
			// Protocol::ColumnDefinition41 format:]
			$col_payload  = self::encode_length_encoded_string( $col['catalog'] ?? 'sqlite' );
			$col_payload .= self::encode_length_encoded_string( $col['schema'] ?? '' );

			// Table alias
			$col_payload .= self::encode_length_encoded_string( $col['table'] ?? '' );

			// Original table name
			$col_payload .= self::encode_length_encoded_string( $col['orgTable'] ?? '' );

			// Column alias
			$col_payload .= self::encode_length_encoded_string( $col['name'] );

			// Original column name
			$col_payload .= self::encode_length_encoded_string( $col['orgName'] ?? $col['name'] );

			// Length of the remaining fixed fields. @TODO: What does that mean?
			$col_payload .= self::encode_length_encoded_int( $col['fixedLen'] ?? 0x0c );
			$col_payload .= self::encode_int_16( $col['charset'] ?? MySQL_Protocol::CHARSET_UTF8MB4 );
			$col_payload .= self::encode_int_32( $col['length'] );
			$col_payload .= self::encode_int_8( $col['type'] );
			$col_payload .= self::encode_int_16( $col['flags'] );
			$col_payload .= self::encode_int_8( $col['decimals'] );
			$col_payload .= "\x00";  // filler (1 byte, reserved)

			$packet_stream .= self::wrap_packet( $col_payload, $sequence_id++ );
		}
		// 3. EOF packet to mark end of column definitions (if not using CLIENT_DEPRECATE_EOF)
		$eof_payload    = chr( self::EOF_PACKET ) . self::encode_int_16( 0 ) . self::encode_int_16( 0 );
		$packet_stream .= self::wrap_packet( $eof_payload, $sequence_id++ );

		// 4. Row data packets (each row is a series of length-encoded values)
		foreach ( $rows as $row ) {
			$row_payload = '';
			// Iterate through columns in the defined order to match column definitions
			foreach ( $columns as $col ) {
				$column_name = $col['name'];
				$val         = $row->{$column_name} ?? null;

				if ( null === $val ) {
					// NULL is represented by 0xfb (NULL_VALUE)
					$row_payload .= "\xfb";
				} else {
					$val_str      = (string) $val;
					$row_payload .= self::encode_length_encoded_string( $val_str );
				}
			}
			$packet_stream .= self::wrap_packet( $row_payload, $sequence_id++ );
		}

		// 5. EOF packet to mark end of data rows (if not using CLIENT_DEPRECATE_EOF)
		$eof_payload_2  = chr( self::EOF_PACKET ) . self::encode_int_16( 0 ) . self::encode_int_16( 0 );
		$packet_stream .= self::wrap_packet( $eof_payload_2, $sequence_id++ );

		return $packet_stream;
	}

	// Helper to wrap a payload into a packet with length and sequence id
	public static function wrap_packet( string $payload, int $sequence_id ): string {
		$length = strlen( $payload );
		$header = self::encode_int_24( $length ) . self::encode_int_8( $sequence_id );
		return $header . $payload;
	}
}
