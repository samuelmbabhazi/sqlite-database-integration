<?php

require_once __DIR__ . '/class-wp-mysql-polyfill-parser.php';

if ( class_exists( 'WP_MySQL_Native_Parser', false ) ) {
	if ( ! function_exists( 'wp_sqlite_mysql_native_new_node' ) ) {
		require_once __DIR__ . '/mysql-rust-bridge.php';
	}
} else {
	require_once __DIR__ . '/class-wp-mysql-native-parser.php';
}

class WP_MySQL_Parser extends WP_MySQL_Native_Parser {
}
