<?php

define( 'WP_MYSQL_ON_SQLITE_LOADER_PATH', __FILE__ );

/**
 * Load the PDO MySQL-on-SQLite driver and its dependencies.
 */
require_once __DIR__ . '/php-polyfills.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/parser/class-wp-parser.php';
require_once __DIR__ . '/parser/class-wp-parser-node.php';
require_once __DIR__ . '/parser/class-wp-parser-token.php';
require_once __DIR__ . '/mysql/class-wp-mysql-token.php';

/**
 * Whether the loaded "wp_mysql_parser" extension speaks a grammar ABI that this
 * code supports.
 *
 * The native parser and PHP exchange the parser grammar via
 * "wp_sqlite_mysql_native_export_grammar()"; the shape of that data is an ABI.
 * Compatibility is tracked by the extension's minor version (the "x" in "0.x"):
 * a backward-incompatible change to the grammar ABI bumps the minor version.
 * This code supports the "0.2.x" line. A version outside the supported range -
 * e.g. an older extension binary lagging a plugin update - cannot exchange the
 * grammar safely and must fall back to the pure-PHP path.
 *
 * Keep the supported range in sync with the extension's "Cargo.toml" version
 * (see "packages/php-ext-wp-mysql-parser/README.md").
 *
 * @param  string|false $extension_version Version reported by "phpversion( 'wp_mysql_parser' )".
 * @return bool                            Whether the native lexer/parser path can be used.
 */
function wp_sqlite_mysql_native_grammar_abi_supported( $extension_version ): bool {
	if ( ! is_string( $extension_version ) ) {
		return false;
	}
	return version_compare( $extension_version, '0.2.0', '>=' )
		&& version_compare( $extension_version, '0.3.0', '<' );
}

/*
 * The MySQL lexer and parser have an optional native (e.g. Rust) implementation,
 * registered by the "wp_mysql_parser" extension. When loaded, it pre-declares
 * WP_MySQL_Native_Lexer / WP_MySQL_Native_Parser; otherwise we use the pure-PHP
 * classes shipped here. WP_MySQL_Lexer / WP_MySQL_Parser is the public entrypoint
 * either way.
 *
 * The native lexer and parser are a matched pair - the native lexer emits a token
 * stream that only the native parser can consume - so they are selected together
 * or not at all. We only select the native path when the loaded extension speaks a
 * grammar ABI this code supports; otherwise (including a stale extension binary) we
 * fall back to the pure-PHP path cleanly instead of failing at parse time.
 */
$wp_sqlite_use_native_parser =
	class_exists( 'WP_MySQL_Native_Lexer', false )
	&& class_exists( 'WP_MySQL_Native_Parser', false )
	&& wp_sqlite_mysql_native_grammar_abi_supported( phpversion( 'wp_mysql_parser' ) );

if ( $wp_sqlite_use_native_parser ) {
	require_once __DIR__ . '/mysql/native/class-wp-mysql-lexer.php';
	require_once __DIR__ . '/mysql/native/mysql-rust-bridge.php';
	require_once __DIR__ . '/mysql/native/trait-wp-mysql-native-parser-impl.php';
	require_once __DIR__ . '/mysql/native/class-wp-mysql-parser.php';
} else {
	require_once __DIR__ . '/mysql/class-wp-mysql-lexer.php';
	require_once __DIR__ . '/mysql/class-wp-mysql-parser.php';
}
require_once __DIR__ . '/sqlite/class-wp-sqlite-connection.php';
require_once __DIR__ . '/sqlite/class-wp-sqlite-configurator.php';
require_once __DIR__ . '/sqlite/class-wp-sqlite-driver.php';
require_once __DIR__ . '/sqlite/class-wp-sqlite-driver-exception.php';
require_once __DIR__ . '/sqlite/class-wp-sqlite-information-schema-builder.php';
require_once __DIR__ . '/sqlite/class-wp-sqlite-information-schema-exception.php';
require_once __DIR__ . '/sqlite/class-wp-sqlite-information-schema-reconstructor.php';
require_once __DIR__ . '/sqlite/class-wp-sqlite-pdo-user-defined-functions.php';
require_once __DIR__ . '/sqlite/class-wp-pdo-mysql-on-sqlite.php';
require_once __DIR__ . '/sqlite/class-wp-pdo-proxy-statement.php';
