<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy;

/**
 * MySQL wire protocol constants and helper functions.
 */
class MySQL_Protocol {
	/**
	 * MySQL client capability flags.
	 *
	 * @see https://github.com/mysql/mysql-server/blob/056a391cdc1af9b17b5415aee243483d1bac532d/include/mysql_com.h#L260
	 */
	const CLIENT_LONG_PASSWORD                  = 1 << 0;  // [NOT USED] Use improved version of old authentication.
	const CLIENT_FOUND_ROWS                     = 1 << 1;  // Send found rows instead of affected rows in EOF packet.
	const CLIENT_LONG_FLAG                      = 1 << 2;  // Get all column flags.
	const CLIENT_CONNECT_WITH_DB                = 1 << 3;  // Database can be specified in handshake reponse packet.
	const CLIENT_NO_SCHEMA                      = 1 << 4;  // [DEPRECATED] Don't allow "database.table.column".
	const CLIENT_COMPRESS                       = 1 << 5;  // Compression protocol supported.
	const CLIENT_ODBC                           = 1 << 6;  // Special handling of ODBC behavior. None since 3.22.
	const CLIENT_LOCAL_FILES                    = 1 << 7;  // Can use LOAD DATA LOCAL.
	const CLIENT_IGNORE_SPACE                   = 1 << 8;  // Ignore spaces before "(" (function names).
	const CLIENT_PROTOCOL_41                    = 1 << 9;  // New 4.1 protocol.
	const CLIENT_INTERACTIVE                    = 1 << 10; // This is an interactive client.
	const CLIENT_SSL                            = 1 << 11; // Use SSL encryption for the session.
	const CLIENT_IGNORE_SIGPIPE                 = 1 << 12; // Do not issue SIGPIPE if network failures occur.
	const CLIENT_TRANSACTIONS                   = 1 << 13; // Client knows about transactions.
	const CLIENT_RESERVED                       = 1 << 14; // [DEPRECATED] Old flag for the 4.1 protocol.
	const CLIENT_SECURE_CONNECTION              = 1 << 15; // [DEPRECATED] Old flag for 4.1 authentication.
	const CLIENT_MULTI_STATEMENTS               = 1 << 16; // Multi-statement support.
	const CLIENT_MULTI_RESULTS                  = 1 << 17; // Multi-result support.
	const CLIENT_PS_MULTI_RESULTS               = 1 << 18; // Multi-results and OUT parameters in PS-protocol.
	const CLIENT_PLUGIN_AUTH                    = 1 << 19; // Plugin authentication.
	const CLIENT_CONNECT_ATTRS                  = 1 << 20; // Permits connection attributes in 4.1 protocol.
	const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 1 << 21; // Enable auth response packet to be larger than 255 bytes.
	const CLIENT_CAN_HANDLE_EXPIRED_PASSWORDS   = 1 << 22; // Support for expired password extension.
	const CLIENT_SESSION_TRACK                  = 1 << 23; // Capable of handling server state change information.
	const CLIENT_DEPRECATE_EOF                  = 1 << 24; // Client no longer needs EOF packet.
	const CLIENT_OPTIONAL_RESULTSET_METADATA    = 1 << 25; // The client can handle optional metadata information in the resultset.
	const CLIENT_ZSTD_COMPRESSION_ALGORITHM     = 1 << 26; // Compression protocol extended to support zstd.
	const CLIENT_QUERY_ATTRIBUTES               = 1 << 27; // Support optional extension for query parameters in query and execute commands.
	const CLIENT_MULTI_FACTOR_AUTHENTICATION    = 1 << 28; // Support multi-factor authentication.
	const CLIENT_CAPABILITY_EXTENSIONS          = 1 << 29; // Reserved to extend the 32bit capabilities structure to 64bits.
	const CLIENT_SSL_VERIFY_SERVER_CERT         = 1 << 30; // Verify server certificate.
	const CLIENT_REMEMBER_OPTIONS               = 1 << 31; // Remember options between reconnects.

	/**
	 * MySQL server status flags.
	 *
	 * @see https://github.com/mysql/mysql-server/blob/056a391cdc1af9b17b5415aee243483d1bac532d/include/mysql_com.h#L810
	 */
	const SERVER_STATUS_IN_TRANS          = 1 << 0;  // A multi-statement transaction has been started.
	const SERVER_STATUS_AUTOCOMMIT        = 1 << 1;  // Server in autocommit mode.
	const SERVER_STATUS_UNUSED_2          = 1 << 2;  // [UNUSED]
	const SERVER_MORE_RESULTS_EXISTS      = 1 << 3;  // Multi query - next query exists.
	const SERVER_QUERY_NO_GOOD_INDEX_USED = 1 << 4;  // No good index was used for the query.
	const SERVER_QUERY_NO_INDEX_USED      = 1 << 5;  // No index was used for the query.
	const SERVER_STATUS_CURSOR_EXISTS     = 1 << 6;  // A cursor exists for a query. FETCH must be used to get data.
	const SERVER_STATUS_LAST_ROW_SENT     = 1 << 7;  // A cursor has been exhausted. Sent in reply to FETCH command.
	const SERVER_STATUS_DB_DROPPED        = 1 << 8;  // A database was dropped.
	const SERVER_STATUS_METADATA_CHANGED  = 1 << 9;  // A set of columns changed after a prepared statement was reprepared.
	const SERVER_QUERY_WAS_SLOW           = 1 << 10; // A query was slow.
	const SERVER_PS_OUT_PARAMS            = 1 << 11; // Mark ResultSet containing output parameter values.
	const SERVER_STATUS_IN_TRANS_READONLY = 1 << 12; // Set together with SERVER_STATUS_IN_TRANS for read-only transactions.
	const SERVER_SESSION_STATE_CHANGED    = 1 << 13; // One of the server state information has changed during last statement.

	/**
	 * MySQL command types.
	 *
	 * @see https://github.com/mysql/mysql-server/blob/056a391cdc1af9b17b5415aee243483d1bac532d/include/my_command.h#L48
	 * @see https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_command_phase.html
	 */
	const COM_SLEEP               = 0;  // Tells the server to sleep for the given number of seconds.
	const COM_QUIT                = 1;  // Tells the server that the client wants it to close the connection.
	const COM_INIT_DB             = 2;  // Change the default schema of the connection.
	const COM_QUERY               = 3;  // Tells the server to execute a query.
	const COM_FIELD_LIST          = 4;  // [DEPRECATED] Returns the list of fields for the given table.
	const COM_CREATE_DB           = 5;  // Currently refused by the server.
	const COM_DROP_DB             = 6;  // Currently refused by the server.
	const COM_UNUSED_2            = 7;  // [UNUSED] Used to be COM_REFRESH.
	const COM_UNUSED_1            = 8;  // [UNUSED] Used to be COM_SHUTDOWN.
	const COM_STATISTICS          = 9;  // Get a human readable string of some internal status vars.
	const COM_UNUSED_4            = 10; // [UNUSED] Used to be COM_PROCESS_INFO.
	const COM_CONNECT             = 11; // Currently refused by the server.
	const COM_UNUSED_5            = 12; // [UNUSED] Used to be COM_PROCESS_KILL.
	const COM_DEBUG               = 13; // Dump debug info to server's stdout.
	const COM_PING                = 14; // Check if the server is alive.
	const COM_TIME                = 15; // Currently refused by the server.
	const COM_DELAYED_INSERT      = 16; // Functionality removed.
	const COM_CHANGE_USER         = 17; // Change the user of the connection.
	const COM_BINLOG_DUMP         = 18; // Tells the server to send the binlog dump.
	const COM_TABLE_DUMP          = 19; // Tells the server to send the table dump.
	const COM_CONNECT_OUT         = 20; // Currently refused by the server.
	const COM_REGISTER_SLAVE      = 21; // Tells the server to register a slave.
	const COM_STMT_PREPARE        = 22; // Tells the server to prepare a statement.
	const COM_STMT_EXECUTE        = 23; // Tells the server to execute a prepared statement.
	const COM_STMT_SEND_LONG_DATA = 24; // Tells the server to send long data for a prepared statement.
	const COM_STMT_CLOSE          = 25; // Tells the server to close a prepared statement.
	const COM_STMT_RESET          = 26; // Tells the server to reset a prepared statement.
	const COM_SET_OPTION          = 27; // Tells the server to set an option.
	const COM_STMT_FETCH          = 28; // Tells the server to fetch a result from a prepared statement.
	const COM_DAEMON              = 29; // Currently refused by the server.
	const COM_BINLOG_DUMP_GTID    = 30; // Tells the server to send the binlog dump in GTID mode.
	const COM_RESET_CONNECTION    = 31; // Tells the server to reset the connection.
	const COM_CLONE               = 32; // Tells the server to clone a server.

	/**
	 * MySQL field types.
	 *
	 * @see https://github.com/mysql/mysql-server/blob/056a391cdc1af9b17b5415aee243483d1bac532d/include/field_types.h#L55
	 *
	 */
	const FIELD_TYPE_DECIMAL     = 0;
	const FIELD_TYPE_TINY        = 1;
	const FIELD_TYPE_SHORT       = 2;
	const FIELD_TYPE_LONG        = 3;
	const FIELD_TYPE_FLOAT       = 4;
	const FIELD_TYPE_DOUBLE      = 5;
	const FIELD_TYPE_NULL        = 6;
	const FIELD_TYPE_TIMESTAMP   = 7;
	const FIELD_TYPE_LONGLONG    = 8;
	const FIELD_TYPE_INT24       = 9;
	const FIELD_TYPE_DATE        = 10;
	const FIELD_TYPE_TIME        = 11;
	const FIELD_TYPE_DATETIME    = 12;
	const FIELD_TYPE_YEAR        = 13;
	const FIELD_TYPE_NEWDATE     = 14;
	const FIELD_TYPE_VARCHAR     = 15;
	const FIELD_TYPE_BIT         = 16;
	const FIELD_TYPE_NEWDECIMAL  = 246;
	const FIELD_TYPE_ENUM        = 247;
	const FIELD_TYPE_SET         = 248;
	const FIELD_TYPE_TINY_BLOB   = 249;
	const FIELD_TYPE_MEDIUM_BLOB = 250;
	const FIELD_TYPE_LONG_BLOB   = 251;
	const FIELD_TYPE_BLOB        = 252;
	const FIELD_TYPE_VAR_STRING  = 253;
	const FIELD_TYPE_STRING      = 254;
	const FIELD_TYPE_GEOMETRY    = 255;

	/**
	 * MySQL field flags.
	 *
	 * @see https://github.com/mysql/mysql-server/blob/056a391cdc1af9b17b5415aee243483d1bac532d/include/mysql_com.h#L154
	 */
	const FIELD_NOT_NULL_FLAG            = 1 << 0;  // Field can't be NULL.
	const FIELD_PRI_KEY_FLAG             = 1 << 1;  // Field is part of a primary key.
	const FIELD_UNIQUE_KEY_FLAG          = 1 << 2;  // Field is part of a unique key.
	const FIELD_MULTIPLE_KEY_FLAG        = 1 << 3;  // Field is part of a key.
	const FIELD_BLOB_FLAG                = 1 << 4;  // Field is a blob.
	const FIELD_UNSIGNED_FLAG            = 1 << 5;  // Field is an unsigned integer.
	const FIELD_ZEROFILL_FLAG            = 1 << 6;  // Field is a zero-filled integer.
	const FIELD_BINARY_FLAG              = 1 << 7;  // Field is binary.
	const FIELD_ENUM_FLAG                = 1 << 8;  // Field is an enum.
	const FIELD_AUTO_INCREMENT_FLAG      = 1 << 9;  // Field is an auto-increment field.
	const FIELD_TIMESTAMP_FLAG           = 1 << 10; // Field is a timestamp.
	const FIELD_SET_FLAG                 = 1 << 11; // Field is a set.
	const FIELD_NO_DEFAULT_VALUE_FLAG    = 1 << 12; // Field doesn't have default value.
	const FIELD_ON_UPDATE_NOW_FLAG       = 1 << 13; // Field is set to NOW on UPDATE.
	const FIELD_PART_KEY_FLAG            = 1 << 14; // [INTERNAL] Field is part of a key.
	const FIELD_NUM_FLAG                 = 1 << 15; // Field is a number.
	const FIELD_UNIQUE_FLAG              = 1 << 16; // [INTERNAL]
	const FIELD_BINCMP_FLAG              = 1 << 17; // [INTERNAL]
	const FIELD_GET_FIXED_FIELDS_FLAG    = 1 << 18; // Used to get fields in item tree.
	const FIELD_IN_PART_FUNC_FLAG        = 1 << 19; // Field part of partition function.
	const FIELD_IN_ADD_INDEX_FLAG        = 1 << 20; // [INTERNAL]
	const FIELD_IS_RENAMED_FLAG          = 1 << 21; // [INTERNAL]
	const FIELD_FLAGS_STORAGE_MEDIA_FLAG = 1 << 22; // Field storage media, bit 22-23.
	const FIELD_FLAGS_STORAGE_MEDIA_MASK = 3 << self::FIELD_FLAGS_STORAGE_MEDIA_FLAG;
	const FIELD_FLAGS_COLUMN_FORMAT_FLAG = 1 << 24; // Field column format, bit 24-25.
	const FIELD_FLAGS_COLUMN_FORMAT_MASK = 3 << self::FIELD_FLAGS_COLUMN_FORMAT_FLAG;
	const FIELD_IS_DROPPED_FLAG          = 1 << 26; // [INTERNAL]
	const FIELD_EXPLICIT_NULL_FLAG       = 1 << 27; // Field is explicitly specified as NULL by user.
	const FIELD_GROUP_FLAG               = 1 << 28; // [INTERNAL]
	const FIELD_NOT_SECONDARY_FLAG       = 1 << 29; // Field will not be loaded in secondary engine.
	const FIELD_IS_INVISIBLE_FLAG        = 1 << 30; // Field is explicitly marked as invisible by user.

	/**
	 * Special packet headers.
	 *
	 * @see https://github.com/mysql/mysql-server/blob/056a391cdc1af9b17b5415aee243483d1bac532d/extra/boost/boost_1_87_0/boost/mysql/impl/internal/protocol/deserialization.hpp#L257
	 */
	const OK_PACKET_HEADER  = 0x00;
	const EOF_PACKET_HEADER = 0xfe;
	const ERR_PACKET_HEADER = 0xff;

	// Auth specific markers for caching_sha2_password
	const AUTH_MORE_DATA_HEADER  = 0x01;  // followed by 1 byte (caching_sha2_password specific)
	const CACHING_SHA2_FAST_AUTH = 3;
	const CACHING_SHA2_FULL_AUTH = 4;
	const AUTH_PLUGIN_NAME       = 'caching_sha2_password';

	// Character set and collation constants (using utf8mb4 general collation)
	const CHARSET_UTF8MB4 = 0xff;  // Collation ID 255 (utf8mb4_0900_ai_ci)

	// Max packet length constant
	const MAX_PACKET_LENGTH = 0x00ffffff;

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
		$server_version   = '8.9.38-php-mysql-server'; // Fake server version
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
		$payload  = chr( self::OK_PACKET_HEADER );
		$payload .= self::encode_length_encoded_int( $affected_rows );
		$payload .= self::encode_length_encoded_int( $last_insert_id );
		$payload .= self::encode_int_16( self::SERVER_STATUS_AUTOCOMMIT ); // server status
		$payload .= self::encode_int_16( 0 );  // no warning count
		// No human-readable message for simplicity
		return $payload;
	}

	// Build ERR packet (for errors)
	public static function build_err_packet( int $error_code, string $sql_state, string $message ): string {
		$payload  = chr( self::ERR_PACKET_HEADER );
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
		$eof_payload    = chr( self::EOF_PACKET_HEADER ) . self::encode_int_16( 0 ) . self::encode_int_16( 0 );
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
		$eof_payload_2  = chr( self::EOF_PACKET_HEADER ) . self::encode_int_16( 0 ) . self::encode_int_16( 0 );
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
