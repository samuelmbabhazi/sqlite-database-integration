<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy;

use WP_MySQL_Proxy\Adapter\Adapter;

class MySQL_Session {
	private $adapter;
	private $client_id;
	private $auth_plugin_data;
	private $sequence_id;
	private $authenticated = false;
	private $buffer        = '';

	public function __construct( Adapter $adapter, int $client_id ) {
		$this->adapter          = $adapter;
		$this->client_id        = $client_id;
		$this->auth_plugin_data = '';
		$this->sequence_id      = 0;
	}

	/**
	 * Get the initial handshake packet to send to the client
	 *
	 * @return string Binary packet data to send to client
	 */
	public function get_initial_handshake(): string {
		$handshake_payload = MySQL_Protocol::build_handshake_packet( $this->client_id, $this->auth_plugin_data );
		return MySQL_Protocol::encode_int_24( strlen( $handshake_payload ) ) .
				MySQL_Protocol::encode_int_8( $this->sequence_id++ ) .
				$handshake_payload;
	}

	/**
	 * Process bytes received from the client
	 *
	 * @param string $data Binary data received from client
	 * @return string|null Response to send back to client, or null if no response needed
	 * @throws Incomplete_Input_Exception When more data is needed to complete a packet
	 */
	public function receive_bytes( string $data ): ?string {
		// Append new data to existing buffer
		$this->buffer .= $data;

		// Check if we have enough data for a header
		if ( strlen( $this->buffer ) < 4 ) {
			throw new Incomplete_Input_Exception( 'Incomplete packet header, need more bytes' );
		}

		// Parse packet header
		$packet_length        = unpack( 'V', substr( $this->buffer, 0, 3 ) . "\x00" )[1];
		$received_sequence_id = ord( $this->buffer[3] );

		// Check if we have the complete packet
		$total_packet_length = 4 + $packet_length;
		if ( strlen( $this->buffer ) < $total_packet_length ) {
			throw new Incomplete_Input_Exception(
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
		if ( MySQL_Protocol::COM_QUERY === $command ) {
			$query = substr( $payload, 1 );
			return $this->process_query( $query );
		} elseif ( MySQL_Protocol::COM_INIT_DB === $command ) {
			return $this->process_query( 'USE ' . substr( $payload, 1 ) );
		} elseif ( MySQL_Protocol::COM_QUIT === $command ) {
			return '';
		} elseif ( MySQL_Protocol::COM_PING === $command ) {
			return MySQL_Protocol::wrap_packet( MySQL_Protocol::build_ok_packet(), $received_sequence_id + 1 );
		} else {
			// Unsupported command
			$err_packet = MySQL_Protocol::build_err_packet( 0x04D2, 'HY000', 'Unsupported command' );
			return MySQL_Protocol::encode_int_24( strlen( $err_packet ) ) .
					MySQL_Protocol::encode_int_8( 1 ) .
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
		if ( $capability_flags & MySQL_Protocol::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA ) {
			$auth_response_length = $this->read_length_encoded_int( $payload, $offset );
			$auth_response        = substr( $payload, $offset, $auth_response_length );
			$offset               = min( $payload_length, $offset + $auth_response_length );
		} elseif ( $capability_flags & MySQL_Protocol::CLIENT_SECURE_CONNECTION ) {
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
		if ( $capability_flags & MySQL_Protocol::CLIENT_CONNECT_WITH_DB ) {
			$database = $this->read_null_terminated_string( $payload, $offset );
		}

		$auth_plugin_name = '';
		if ( $capability_flags & MySQL_Protocol::CLIENT_PLUGIN_AUTH ) {
			$auth_plugin_name = $this->read_null_terminated_string( $payload, $offset );
		}

		if ( $capability_flags & MySQL_Protocol::CLIENT_CONNECT_ATTRS ) {
			$attrs_length = $this->read_length_encoded_int( $payload, $offset );
			$offset       = min( $payload_length, $offset + $attrs_length );
		}

		$this->authenticated = true;
		$this->sequence_id   = 2;

		$response_packets = '';

		if ( MySQL_Protocol::AUTH_PLUGIN_NAME === $auth_plugin_name ) {
			$fast_auth_payload = chr( MySQL_Protocol::AUTH_MORE_DATA_HEADER ) . chr( MySQL_Protocol::CACHING_SHA2_FAST_AUTH );
			$response_packets .= MySQL_Protocol::encode_int_24( strlen( $fast_auth_payload ) );
			$response_packets .= MySQL_Protocol::encode_int_8( $this->sequence_id++ );
			$response_packets .= $fast_auth_payload;
		}

		$ok_packet         = MySQL_Protocol::build_ok_packet();
		$response_packets .= MySQL_Protocol::encode_int_24( strlen( $ok_packet ) );
		$response_packets .= MySQL_Protocol::encode_int_8( $this->sequence_id++ );
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
			$result = $this->adapter->handle_query( $query );
			return $result->to_packets();
		} catch ( MySQL_Proxy_Exception $e ) {
			$err_packet = MySQL_Protocol::build_err_packet( 0x04A7, '42000', 'Syntax error or unsupported query: ' . $e->getMessage() );
			return MySQL_Protocol::encode_int_24( strlen( $err_packet ) ) .
					MySQL_Protocol::encode_int_8( 1 ) .
					$err_packet;
		}
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
