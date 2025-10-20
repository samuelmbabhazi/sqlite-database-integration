<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

class MySQLServerException extends Exception {
}

interface MySQLServerQueryResult {
	public function to_packets(): string;
}

interface MySQLQueryHandler {
	public function handle_query( string $query ): MySQLServerQueryResult;
}

class SelectQueryResult implements MySQLServerQueryResult {
	public $columns;  // Each column: ['name' => string, 'type' => int, 'length' => int, 'flags' => int, 'decimals' => int]
	public $rows;     // Array of rows, each an array of values (strings, numbers, or null)

	public function __construct( array $columns = array(), array $rows = array() ) {
		$this->columns = $columns;
		$this->rows    = $rows;
	}

	public function to_packets(): string {
		return MySQLProtocol::build_result_set_packets( $this );
	}
}

class OkayPacketResult implements MySQLServerQueryResult {
	public $affected_rows;
	public $last_insert_id;

	public function __construct( int $affected_rows, int $last_insert_id ) {
		$this->affected_rows  = $affected_rows;
		$this->last_insert_id = $last_insert_id;
	}

	public function to_packets(): string {
		$ok_packet = MySQLProtocol::build_ok_packet( $this->affected_rows, $this->last_insert_id );
		return MySQLProtocol::encode_int_24( strlen( $ok_packet ) ) . MySQLProtocol::encode_int_8( 1 ) . $ok_packet;
	}
}

class ErrorQueryResult implements MySQLServerQueryResult {
	public $code;
	public $sql_state;
	public $message;

	public function __construct( string $message = 'Syntax error or unsupported query', string $sql_state = '42000', int $code = 0x04A7 ) {
		$this->code      = $code;
		$this->sql_state = $sql_state;
		$this->message   = $message;
	}

	public function to_packets(): string {
		$err_packet = MySQLProtocol::build_err_packet( $this->code, $this->sql_state, $this->message );
		return MySQLProtocol::encode_int_24( strlen( $err_packet ) ) . MySQLProtocol::encode_int_8( 1 ) . $err_packet;
	}
}

class MySQLProtocol {
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
	public static function build_result_set_packets( SelectQueryResult $result ): string {
		$sequence_id   = 1;  // Sequence starts at 1 for resultset (after COM_QUERY)
		$packet_stream = '';

		// 1. Column count packet (length-encoded integer for number of columns)
		$col_count         = count( $result->columns );
		$col_count_payload = self::encode_length_encoded_int( $col_count );
		$packet_stream    .= self::wrap_packet( $col_count_payload, $sequence_id++ );

		// 2. Column definition packets for each column
		foreach ( $result->columns as $col ) {
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
			$col_payload .= self::encode_int_16( $col['charset'] ?? MySQLProtocol::CHARSET_UTF8MB4 );
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
		foreach ( $result->rows as $row ) {
			$row_payload = '';
			// Iterate through columns in the defined order to match column definitions
			foreach ( $result->columns as $col ) {
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

class IncompleteInputException extends MySQLServerException {
	public function __construct( string $message = 'Incomplete input data, more bytes needed' ) {
		parent::__construct( $message );
	}
}

class MySQLGateway {
	private $query_handler;
	private $connection_id;
	private $auth_plugin_data;
	private $sequence_id;
	private $authenticated = false;
	private $buffer        = '';

	public function __construct( MySQLQueryHandler $query_handler ) {
		$this->query_handler    = $query_handler;
		$this->connection_id    = random_int( 1, 1000 );
		$this->auth_plugin_data = '';
		$this->sequence_id      = 0;
	}

	/**
	 * Get the initial handshake packet to send to the client
	 *
	 * @return string Binary packet data to send to client
	 */
	public function get_initial_handshake(): string {
		$handshake_payload = MySQLProtocol::build_handshake_packet( $this->connection_id, $this->auth_plugin_data );
		return MySQLProtocol::encode_int_24( strlen( $handshake_payload ) ) .
				MySQLProtocol::encode_int_8( $this->sequence_id++ ) .
				$handshake_payload;
	}

	/**
	 * Process bytes received from the client
	 *
	 * @param string $data Binary data received from client
	 * @return string|null Response to send back to client, or null if no response needed
	 * @throws IncompleteInputException When more data is needed to complete a packet
	 */
	public function receive_bytes( string $data ): ?string {
		// Append new data to existing buffer
		$this->buffer .= $data;

		// Check if we have enough data for a header
		if ( strlen( $this->buffer ) < 4 ) {
			throw new IncompleteInputException( 'Incomplete packet header, need more bytes' );
		}

		// Parse packet header
		$packet_length        = unpack( 'V', substr( $this->buffer, 0, 3 ) . "\x00" )[1];
		$received_sequence_id = ord( $this->buffer[3] );

		// Check if we have the complete packet
		$total_packet_length = 4 + $packet_length;
		if ( strlen( $this->buffer ) < $total_packet_length ) {
			throw new IncompleteInputException(
				'Incomplete packet payload, have ' . strlen( $this->buffer ) .
				' bytes, need ' . $total_packet_length . ' bytes'
			);
		}

		// Extract the complete packet
		$packet = substr( $this->buffer, 0, $total_packet_length );

		// Remove the processed packet from the buffer
		$this->buffer = substr( $this->buffer, $total_packet_length );

		// Process the packet
		$payload = substr( $packet, 4, $packet_length );

		// If not authenticated yet, process authentication
		if ( ! $this->authenticated ) {
			return $this->process_authentication( $payload );
		}

		// Otherwise, process as a command
		$command = ord( $payload[0] );
		if ( MySQLProtocol::COM_QUERY === $command ) {
			$query = substr( $payload, 1 );
			return $this->process_query( $query );
		} elseif ( MySQLProtocol::COM_INIT_DB === $command ) {
			return $this->process_query( 'USE ' . substr( $payload, 1 ) );
		} elseif ( MySQLProtocol::COM_QUIT === $command ) {
			return '';
		} else {
			// Unsupported command
			$err_packet = MySQLProtocol::build_err_packet( 0x04D2, 'HY000', 'Unsupported command' );
			return MySQLProtocol::encode_int_24( strlen( $err_packet ) ) .
					MySQLProtocol::encode_int_8( 1 ) .
					$err_packet;
		}
	}

	/**
	 * Process authentication packet from client
	 *
	 * @param string $payload Authentication packet payload
	 * @return string Response packet to send back
	 */
	private function process_authentication( string $payload ): string {
		$offset         = 0;
		$payload_length = strlen( $payload );

		$capability_flags = $this->read_unsigned_int_little_endian( $payload, $offset, 4 );
		$offset          += 4;

		$client_max_packet_size = $this->read_unsigned_int_little_endian( $payload, $offset, 4 );
		$offset                += 4;

		$client_character_set = 0;
		if ( $offset < $payload_length ) {
			$client_character_set = ord( $payload[ $offset ] );
		}
		$offset += 1;

		// Skip reserved bytes (always zero)
		$offset = min( $payload_length, $offset + 23 );

		$username = $this->read_null_terminated_string( $payload, $offset );

		$auth_response = '';
		if ( $capability_flags & MySQLProtocol::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA ) {
			$auth_response_length = $this->read_length_encoded_int( $payload, $offset );
			$auth_response        = substr( $payload, $offset, $auth_response_length );
			$offset               = min( $payload_length, $offset + $auth_response_length );
		} elseif ( $capability_flags & MySQLProtocol::CLIENT_SECURE_CONNECTION ) {
			$auth_response_length = 0;
			if ( $offset < $payload_length ) {
				$auth_response_length = ord( $payload[ $offset ] );
			}
			$offset       += 1;
			$auth_response = substr( $payload, $offset, $auth_response_length );
			$offset        = min( $payload_length, $offset + $auth_response_length );
		} else {
			$auth_response = $this->read_null_terminated_string( $payload, $offset );
		}

		$database = '';
		if ( $capability_flags & MySQLProtocol::CLIENT_CONNECT_WITH_DB ) {
			$database = $this->read_null_terminated_string( $payload, $offset );
		}

		$auth_plugin_name = '';
		if ( $capability_flags & MySQLProtocol::CLIENT_PLUGIN_AUTH ) {
			$auth_plugin_name = $this->read_null_terminated_string( $payload, $offset );
		}

		if ( $capability_flags & MySQLProtocol::CLIENT_CONNECT_ATTRS ) {
			$attrs_length = $this->read_length_encoded_int( $payload, $offset );
			$offset       = min( $payload_length, $offset + $attrs_length );
		}

		$this->authenticated = true;
		$this->sequence_id   = 2;

		$response_packets = '';

		if ( MySQLProtocol::AUTH_PLUGIN_NAME === $auth_plugin_name ) {
			$fast_auth_payload = chr( MySQLProtocol::AUTH_MORE_DATA ) . chr( MySQLProtocol::CACHING_SHA2_FAST_AUTH );
			$response_packets .= MySQLProtocol::encode_int_24( strlen( $fast_auth_payload ) );
			$response_packets .= MySQLProtocol::encode_int_8( $this->sequence_id++ );
			$response_packets .= $fast_auth_payload;
		}

		$ok_packet         = MySQLProtocol::build_ok_packet();
		$response_packets .= MySQLProtocol::encode_int_24( strlen( $ok_packet ) );
		$response_packets .= MySQLProtocol::encode_int_8( $this->sequence_id++ );
		$response_packets .= $ok_packet;

		return $response_packets;
	}

	private function read_unsigned_int_little_endian( string $payload, int $offset, int $length ): int {
		$slice = substr( $payload, $offset, $length );
		if ( '' === $slice || $length <= 0 ) {
			return 0;
		}

		switch ( $length ) {
			case 1:
				return ord( $slice[0] );
			case 2:
				$padded   = str_pad( $slice, 2, "\x00", STR_PAD_RIGHT );
				$unpacked = unpack( 'v', $padded );
				return $unpacked[1] ?? 0;
			case 3:
			case 4:
			default:
				$padded   = str_pad( $slice, 4, "\x00", STR_PAD_RIGHT );
				$unpacked = unpack( 'V', $padded );
				return $unpacked[1] ?? 0;
		}
	}

	private function read_null_terminated_string( string $payload, int &$offset ): string {
		$null_position = strpos( $payload, "\0", $offset );
		if ( false === $null_position ) {
			$result = substr( $payload, $offset );
			$offset = strlen( $payload );
			return $result;
		}

		$result = substr( $payload, $offset, $null_position - $offset );
		$offset = $null_position + 1;
		return $result;
	}

	private function read_length_encoded_int( string $payload, int &$offset ): int {
		if ( $offset >= strlen( $payload ) ) {
			return 0;
		}

		$first   = ord( $payload[ $offset ] );
		$offset += 1;

		if ( $first < 0xfb ) {
			return $first;
		}

		if ( 0xfb === $first ) {
			return 0;
		}

		if ( 0xfc === $first ) {
			$value   = $this->read_unsigned_int_little_endian( $payload, $offset, 2 );
			$offset += 2;
			return $value;
		}

		if ( 0xfd === $first ) {
			$value   = $this->read_unsigned_int_little_endian( $payload, $offset, 3 );
			$offset += 3;
			return $value;
		}

		// 0xfe indicates an 8-byte integer
		$value = 0;
		$slice = substr( $payload, $offset, 8 );
		if ( '' !== $slice ) {
			$slice = str_pad( $slice, 8, "\x00" );
			$value = unpack( 'P', $slice )[1];
		}
		$offset += 8;
		return (int) $value;
	}

	/**
	 * Process a query from the client
	 *
	 * @param string $query SQL query to process
	 * @return string Response packet to send back
	 */
	private function process_query( string $query ): string {
		$query = trim( $query );

		try {
			$result = $this->query_handler->handle_query( $query );
			return $result->to_packets();
		} catch ( MySQLServerException $e ) {
			$err_packet = MySQLProtocol::build_err_packet( 0x04A7, '42000', 'Syntax error or unsupported query: ' . $e->getMessage() );
			return MySQLProtocol::encode_int_24( strlen( $err_packet ) ) .
					MySQLProtocol::encode_int_8( 1 ) .
					$err_packet;
		}
	}

	/**
	 * Reset the server state for a new connection
	 */
	public function reset(): void {
		$this->connection_id    = random_int( 1, 1000 );
		$this->auth_plugin_data = '';
		$this->sequence_id      = 0;
		$this->authenticated    = false;
		$this->buffer           = '';
	}

	/**
	 * Check if there's any buffered data that hasn't been processed yet
	 *
	 * @return bool True if there's data in the buffer
	 */
	public function has_buffered_data(): bool {
		return ! empty( $this->buffer );
	}

	/**
	 * Get the number of bytes currently in the buffer
	 *
	 * @return int Number of bytes in buffer
	 */
	public function get_buffer_size(): int {
		return strlen( $this->buffer );
	}
}

class MySQLSocketServer {
	private $query_handler;
	private $socket;
	private $port;
	private $clients        = array();
	private $client_servers = array();

	public function __construct( MySQLQueryHandler $query_handler, $options = array() ) {
		$this->query_handler = $query_handler;
		$this->port          = $options['port'] ?? 3306;
	}

	public function start() {
		$this->socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		socket_bind( $this->socket, '0.0.0.0', $this->port );
		socket_listen( $this->socket );
		echo "MySQL PHP Server listening on port {$this->port}...\n";
		while ( true ) {
			// Prepare arrays for socket_select()
			$read   = array_merge( array( $this->socket ), $this->clients );
			$write  = null;
			$except = null;

			// Wait for activity on any socket
			$select_result = socket_select( $read, $write, $except, null );
			if ( false === $select_result || $select_result <= 0 ) {
				continue;
			}

			// Check if there's a new connection
			if ( in_array( $this->socket, $read, true ) ) {
				$client = socket_accept( $this->socket );
				if ( $client ) {
					echo "New client connected.\n";
					$this->clients[]                    = $client;
					$client_id                          = spl_object_id( $client );
					$this->client_servers[ $client_id ] = new MySQLGateway( $this->query_handler );

					// Send initial handshake
					echo "Pre handshake\n";
					$handshake = $this->client_servers[ $client_id ]->get_initial_handshake();
					echo "Post handshake\n";
					socket_write( $client, $handshake );
				}
				// Remove server socket from read array
				unset( $read[ array_search( $this->socket, $read, true ) ] );
			}

			// Handle client activity
			echo "Waiting for client activity\n";
			foreach ( $read as $client ) {
				echo "calling socket_read\n";
				$data = @socket_read( $client, 4096 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				echo "socket_read returned\n";
				$display = '';
				for ( $i = 0; $i < strlen( $data ); $i++ ) {
					$byte = ord( $data[ $i ] );
					if ( $byte >= 32 && $byte <= 126 ) {
						// Printable ASCII character
						$display .= $data[ $i ];
					} else {
						// Non-printable, show as hex
						$display .= sprintf( '%02x ', $byte );
					}
				}
				echo rtrim( $display ) . "\n";

				if ( false === $data || '' === $data ) {
					// Client disconnected
					echo "Client disconnected.\n";
					$client_id = spl_object_id( $client );
					$this->client_servers[ $client_id ]->reset();
					unset( $this->client_servers[ $client_id ] );
					socket_close( $client );
					unset( $this->clients[ array_search( $client, $this->clients, true ) ] );
					continue;
				}

				try {
					// Process the data
					$client_id = spl_object_id( $client );
					echo "Receiving bytes\n";
					$response = $this->client_servers[ $client_id ]->receive_bytes( $data );
					if ( $response ) {
						echo "Writing response\n";
						echo $response;
						socket_write( $client, $response );
					}
					echo "Response written\n";

					// Process any buffered data
					while ( $this->client_servers[ $client_id ]->has_buffered_data() ) {
						echo "Processing buffered data\n";
						try {
							$response = $this->client_servers[ $client_id ]->receive_bytes( '' );
							if ( $response ) {
								socket_write( $client, $response );
							}
						} catch ( IncompleteInputException $e ) {
							break;
						}
					}
					echo "After the while loop\n";
				} catch ( IncompleteInputException $e ) {
					echo "Incomplete input exception\n";
					continue;
				}
			}
			echo "restarting the while() loop!\n";
		}
	}
}
