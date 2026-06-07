<?php

/**
 * This script runs the MySQL parser on all queries from the MySQL server suite.
 * It tracks parsing failures and exceptions and measures parsing performance.
 * This is an end-to-end benchmark that includes lexing time in the results.
 *
 * Options:
 *   --json       Print machine-readable benchmark output.
 *   --limit=N    Only benchmark the first N queries.
 *   --consume=MODE
 *                How much AST data to consume after parsing:
 *                none        Only require parse() to return an AST (default).
 *                descendants Walk all descendants with get_descendants().
 *                descendant-ids
 *                            Consume all descendants as scalar kind/id rows.
 *                descendant-rows
 *                            Consume all descendants as scalar kind/id/span rows.
 *                descendant-token-bytes
 *                            Consume scalar rows and read each token's raw bytes.
 *                descendant-packed-ids
 *                            Consume all descendants as one packed kind/id int per descendant.
 *                descendant-packed-rows
 *                            Consume all descendants as packed kind/id/span rows.
 *                descendant-packed-token-bytes
 *                            Consume packed rows and read each token's raw bytes.
 */

// Throw exception if anything fails.
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$json    = in_array( '--json', $argv, true );
$limit   = null;
$consume = 'none';
foreach ( $argv as $arg ) {
	if ( 0 === strpos( $arg, '--limit=' ) ) {
		$limit = max( 1, (int) substr( $arg, strlen( '--limit=' ) ) );
	}
	if ( 0 === strpos( $arg, '--consume=' ) ) {
		$consume = substr( $arg, strlen( '--consume=' ) );
	}
}

if ( ! in_array( $consume, array( 'none', 'descendants', 'descendant-ids', 'descendant-rows', 'descendant-token-bytes', 'descendant-packed-ids', 'descendant-packed-rows', 'descendant-packed-token-bytes' ), true ) ) {
	throw new InvalidArgumentException( sprintf( 'Unsupported --consume mode: %s', $consume ) );
}

// Use the integration loader so an already-loaded native extension selects
// the same public lexer/parser classes that runtime code uses.
require_once __DIR__ . '/../../src/load.php';

function get_stats( $total, $failures, $exceptions ) {
	return sprintf(
		'Total: %5d  |  Failures: %4d / %2d%%  |  Exceptions: %4d / %2d%%',
		$total,
		$failures,
		$failures / $total * 100,
		$exceptions,
		$exceptions / $total * 100
	);
}

function checksum_bytes( string $bytes ): int {
	$length   = strlen( $bytes );
	$checksum = $length;
	for ( $i = 0; $i < $length; $i++ ) {
		$checksum += ord( $bytes[ $i ] );
	}
	return $checksum;
}

function consume_native_descendant_id_rows( array $rows, int &$descendants, int &$checksum ): void {
	$descendants += intdiv( count( $rows ), 2 );
	foreach ( $rows as $value ) {
		$checksum += $value;
	}
}

function pack_kind_id( int $kind, int $id ): int {
	return $id * 2 + $kind;
}

function pack_span( int $start, int $length ): int {
	return $start * 4294967296 + $length;
}

function unpack_span_start( int $span ): int {
	return intdiv( $span, 4294967296 );
}

function unpack_span_length( int $span ): int {
	return $span & 0xffffffff;
}

function consume_native_descendant_packed_id_rows( array $rows, int &$descendants, int &$checksum ): void {
	$descendants += count( $rows );
	foreach ( $rows as $value ) {
		$checksum += $value;
	}
}

function consume_native_descendant_scalar_rows( array $rows, string $query, bool $consume_token_bytes, int &$descendants, int &$checksum ): void {
	$row_count    = count( $rows );
	$descendants += intdiv( $row_count, 4 );
	for ( $i = 0; $i < $row_count; $i += 4 ) {
		$kind     = $rows[ $i ];
		$id       = $rows[ $i + 1 ];
		$start    = $rows[ $i + 2 ];
		$length   = $rows[ $i + 3 ];
		$checksum += $kind + $id + $start + $length;
		if ( $consume_token_bytes && 1 === $kind ) {
			$checksum += checksum_bytes( substr( $query, $start, $length ) );
		}
	}
}

function consume_native_descendant_packed_scalar_rows( array $rows, string $query, bool $consume_token_bytes, int &$descendants, int &$checksum ): void {
	$row_count    = count( $rows );
	$descendants += intdiv( $row_count, 2 );
	for ( $i = 0; $i < $row_count; $i += 2 ) {
		$kind_id  = $rows[ $i ];
		$span     = $rows[ $i + 1 ];
		if ( $span < 0 ) {
			$checksum += $kind_id - 1;
		} else {
			$start     = unpack_span_start( $span );
			$length    = unpack_span_length( $span );
			$checksum += $kind_id + $start + $length;
		}
		if ( $consume_token_bytes && ( $kind_id & 1 ) === 1 ) {
			$checksum += checksum_bytes( substr( $query, $start, $length ) );
		}
	}
}

function consume_php_descendant_id_rows( WP_Parser_Node $node, int &$descendants, int &$checksum ): void {
	foreach ( $node->get_children() as $child ) {
		++$descendants;
		if ( $child instanceof WP_Parser_Node ) {
			$checksum += $child->rule_id;
			consume_php_descendant_id_rows( $child, $descendants, $checksum );
		} else {
			$checksum += 1 + $child->id;
		}
	}
}

function consume_php_descendant_packed_id_rows( WP_Parser_Node $node, int &$descendants, int &$checksum ): void {
	foreach ( $node->get_children() as $child ) {
		++$descendants;
		if ( $child instanceof WP_Parser_Node ) {
			$checksum += pack_kind_id( 0, $child->rule_id );
			consume_php_descendant_packed_id_rows( $child, $descendants, $checksum );
		} else {
			$checksum += pack_kind_id( 1, $child->id );
		}
	}
}

function consume_php_descendant_scalar_rows( WP_Parser_Node $node, string $query, bool $consume_token_bytes, int &$descendants, int &$checksum ): void {
	foreach ( $node->get_children() as $child ) {
		++$descendants;
		if ( $child instanceof WP_Parser_Node ) {
			$checksum += $child->rule_id - 1;
			consume_php_descendant_scalar_rows( $child, $query, $consume_token_bytes, $descendants, $checksum );
		} else {
			$checksum += 1 + $child->id + $child->start + $child->length;
			if ( $consume_token_bytes ) {
				$checksum += checksum_bytes( substr( $query, $child->start, $child->length ) );
			}
		}
	}
}

function consume_php_descendant_packed_scalar_rows( WP_Parser_Node $node, string $query, bool $consume_token_bytes, int &$descendants, int &$checksum ): void {
	foreach ( $node->get_children() as $child ) {
		++$descendants;
		if ( $child instanceof WP_Parser_Node ) {
			$checksum += pack_kind_id( 0, $child->rule_id ) - 1;
			consume_php_descendant_packed_scalar_rows( $child, $query, $consume_token_bytes, $descendants, $checksum );
		} else {
			$checksum += pack_kind_id( 1, $child->id ) + $child->start + $child->length;
			if ( $consume_token_bytes ) {
				$checksum += checksum_bytes( substr( $query, $child->start, $child->length ) );
			}
		}
	}
}

// Load the MySQL grammar.
$grammar_data = include __DIR__ . '/../../src/mysql/mysql-grammar.php';
$grammar      = new WP_Parser_Grammar( $grammar_data );

// Load the bounded checked-in corpus before timing so file IO is excluded
// from the benchmark.
$data_dir = __DIR__ . '/../mysql/data';
$handle   = fopen( "$data_dir/mysql-server-tests-queries.csv", 'r' );
$queries  = array();
while ( ( $record = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	$query = $record[0] ?? null;
	if ( null === $query || '' === $query ) {
		continue;
	}
	$queries[] = $query;
	if ( null !== $limit && count( $queries ) >= $limit ) {
		break;
	}
}

// Run the parser.
$failures    = array();
$exceptions  = array();
$processed   = 0;
$descendants = 0;
$checksum    = 0;
// Reuse a single parser across queries, mirroring the driver
// (WP_PDO_MySQL_On_SQLite::reset_or_create_parser), which resets tokens on the
// same instance rather than constructing a fresh parser per query.
$parser = null;
$start  = microtime( true );
foreach ( $queries as $query ) {
	try {
		$lexer  = new WP_MySQL_Lexer( $query );
		$tokens = $lexer instanceof WP_MySQL_Native_Lexer
			? $lexer->native_token_stream()
			: $lexer->remaining_tokens();
		if ( ( is_array( $tokens ) ? count( $tokens ) : $tokens->count() ) === 0 ) {
			throw new Exception( 'Failed to tokenize query: ' . $query );
		}

		if ( null === $parser ) {
			$parser = new WP_MySQL_Parser( $grammar, $tokens );
		} else {
			$parser->reset_tokens( $tokens );
		}
		$ast = $parser->parse();
		if ( null === $ast ) {
			$failures[] = $query;
		} elseif ( 'descendants' === $consume ) {
			$descendants += count( $ast->get_descendants() );
		} elseif ( 'descendant-ids' === $consume ) {
			if (
				class_exists( 'WP_MySQL_Native_Parser_Node', false )
				&& $ast instanceof WP_MySQL_Native_Parser_Node
				&& method_exists( $ast, 'get_native_descendant_id_rows' )
			) {
				consume_native_descendant_id_rows( $ast->get_native_descendant_id_rows(), $descendants, $checksum );
			} else {
				consume_php_descendant_id_rows( $ast, $descendants, $checksum );
			}
		} elseif ( 'descendant-rows' === $consume || 'descendant-token-bytes' === $consume ) {
			$consume_token_bytes = 'descendant-token-bytes' === $consume;
			if (
				class_exists( 'WP_MySQL_Native_Parser_Node', false )
				&& $ast instanceof WP_MySQL_Native_Parser_Node
				&& method_exists( $ast, 'get_native_descendant_scalar_rows' )
			) {
				consume_native_descendant_scalar_rows(
					$ast->get_native_descendant_scalar_rows(),
					$query,
					$consume_token_bytes,
					$descendants,
					$checksum
				);
			} else {
				consume_php_descendant_scalar_rows( $ast, $query, $consume_token_bytes, $descendants, $checksum );
			}
		} elseif ( 'descendant-packed-ids' === $consume ) {
			if (
				class_exists( 'WP_MySQL_Native_Parser_Node', false )
				&& $ast instanceof WP_MySQL_Native_Parser_Node
				&& method_exists( $ast, 'get_native_descendant_packed_id_rows' )
			) {
				consume_native_descendant_packed_id_rows( $ast->get_native_descendant_packed_id_rows(), $descendants, $checksum );
			} else {
				consume_php_descendant_packed_id_rows( $ast, $descendants, $checksum );
			}
		} elseif ( 'descendant-packed-rows' === $consume || 'descendant-packed-token-bytes' === $consume ) {
			$consume_token_bytes = 'descendant-packed-token-bytes' === $consume;
			if (
				class_exists( 'WP_MySQL_Native_Parser_Node', false )
				&& $ast instanceof WP_MySQL_Native_Parser_Node
				&& method_exists( $ast, 'get_native_descendant_packed_scalar_rows' )
			) {
				consume_native_descendant_packed_scalar_rows(
					$ast->get_native_descendant_packed_scalar_rows(),
					$query,
					$consume_token_bytes,
					$descendants,
					$checksum
				);
			} else {
				consume_php_descendant_packed_scalar_rows( $ast, $query, $consume_token_bytes, $descendants, $checksum );
			}
		}
	} catch ( Exception $e ) {
		$exceptions[] = $query;
	}

	$processed += 1;
	if ( ! $json && $processed > 0 && 0 === $processed % 1000 ) {
		echo get_stats( $processed, count( $failures ), count( $exceptions ) ), "\n";
	}
}
$duration = microtime( true ) - $start;
$qps      = $processed / $duration;

if ( $json ) {
	echo json_encode(
		array(
			'benchmark'        => 'mysql-parser',
			'implementation'   => class_exists( 'WP_MySQL_Native_Parser', false ) ? 'native-extension' : 'php',
			'extension_loaded' => extension_loaded( 'wp_mysql_parser' ),
			'queries'          => $processed,
			'consume'          => $consume,
			'descendants'      => $descendants,
			'checksum'         => $checksum,
			'duration'         => $duration,
			'qps'              => $qps,
			'failures'         => count( $failures ),
			'exceptions'       => count( $exceptions ),
			'php_version'      => PHP_VERSION,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	), "\n";
	exit;
}

echo get_stats( $processed, count( $failures ), count( $exceptions ) ), "\n";
printf( "AST consumption: %s", $consume );
if ( 'descendants' === $consume || 'descendant-ids' === $consume || 'descendant-rows' === $consume || 'descendant-token-bytes' === $consume || 'descendant-packed-ids' === $consume || 'descendant-packed-rows' === $consume || 'descendant-packed-token-bytes' === $consume ) {
	printf( " (%d descendants, checksum %d)", $descendants, $checksum );
}
echo "\n";

// Print the results.
printf( "\nParsed %d queries in %.5fs @ %d QPS.\n", $processed, $duration, $qps );
