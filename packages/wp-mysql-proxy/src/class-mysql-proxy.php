<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy;

use WP_MySQL_Proxy\Adapter\Adapter;

class MySQL_Proxy {
	private $query_handler;
	private $socket;
	private $port;
	private $clients        = array();
	private $client_servers = array();

	public function __construct( Adapter $query_handler, $options = array() ) {
		$this->query_handler = $query_handler;
		$this->port          = $options['port'] ?? 3306;
	}

	public function start() {
		$this->socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		socket_set_option( $this->socket, SOL_SOCKET, SO_REUSEADDR, 1 );
		socket_bind( $this->socket, '0.0.0.0', $this->port );
		socket_listen( $this->socket );
		echo "MySQL PHP Proxy listening on port {$this->port}...\n";
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
					$client_id                          = $this->get_client_id( $client );
					$this->client_servers[ $client_id ] = new MySQL_Session( $this->query_handler );

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
					$client_id = $this->get_client_id( $client );
					$this->client_servers[ $client_id ]->reset();
					unset( $this->client_servers[ $client_id ] );
					socket_close( $client );
					unset( $this->clients[ array_search( $client, $this->clients, true ) ] );
					continue;
				}

				try {
					// Process the data
					$client_id = $this->get_client_id( $client );
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

	/**
	 * Get a numeric ID for a client connected to the proxy.
	 *
	 * @param  resource|object $client The client Socket object or resource.
	 * @return int                     The numeric ID of the client.
	 */
	private function get_client_id( $client ): int {
		if ( is_resource( $client ) ) {
			return get_resource_id( $client );
		} else {
			return spl_object_id( $client );
		}
	}
}
