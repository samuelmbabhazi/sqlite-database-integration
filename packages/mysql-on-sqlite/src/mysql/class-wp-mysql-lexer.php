<?php

require_once __DIR__ . '/class-wp-mysql-polyfill-lexer.php';

if ( class_exists( 'WP_MySQL_Native_Lexer', false ) ) {
	if ( ! function_exists( 'wp_sqlite_mysql_native_new_token' ) ) {
		require_once __DIR__ . '/mysql-rust-bridge.php';
	}
} else {
	require_once __DIR__ . '/class-wp-mysql-native-lexer.php';
}

class WP_MySQL_Lexer extends WP_MySQL_Native_Lexer {
}
