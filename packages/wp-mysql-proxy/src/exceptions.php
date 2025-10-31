<?php declare( strict_types = 1 ); // phpcs:disable WordPress.Files.FileName.InvalidClassFileName

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace WP_MySQL_Proxy;

use Exception;

class MySQLServerException extends Exception {
}

class IncompleteInputException extends MySQLServerException {
	public function __construct( string $message = 'Incomplete input data, more bytes needed' ) {
		parent::__construct( $message );
	}
}
