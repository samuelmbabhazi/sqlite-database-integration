<?php declare( strict_types = 1 );

namespace WP_MySQL_Proxy;

class MySQL_Result {
	public $affected_rows  = 0;
	public $last_insert_id = null;
	public $columns        = array();
	public $rows           = array();

	public $error_info = null;

	public static function from_data( int $affected_rows, int $last_insert_id, array $columns, array $rows ): self {
		$result                 = new self();
		$result->affected_rows  = $affected_rows;
		$result->last_insert_id = $last_insert_id;
		$result->columns        = $columns;
		$result->rows           = $rows;
		return $result;
	}

	public static function from_error( string $sql_state, int $code, string $message ): self {
		$result             = new self();
		$result->error_info = array( $sql_state, $code, $message );
		return $result;
	}

	public function to_packets(): string {
		if ( $this->error_info ) {
			$err_packet = MySQL_Protocol::build_err_packet( $this->error_info[1], $this->error_info[0], $this->error_info[2] );
			return MySQL_Protocol::encode_int_24( strlen( $err_packet ) ) . MySQL_Protocol::encode_int_8( 1 ) . $err_packet;
		}

		if ( count( $this->columns ) > 0 ) {
			return MySQL_Protocol::build_result_set_packets( $this->columns, $this->rows );
		}

		$ok_packet = MySQL_Protocol::build_ok_packet( $this->affected_rows, $this->last_insert_id );
		return MySQL_Protocol::encode_int_24( strlen( $ok_packet ) ) . MySQL_Protocol::encode_int_8( 1 ) . $ok_packet;
	}
}
