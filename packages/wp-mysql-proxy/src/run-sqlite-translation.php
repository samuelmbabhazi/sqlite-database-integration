<?php declare( strict_types = 1 );

use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\Adapter\SQLite_Adapter;

require_once __DIR__ . '/mysql-server.php';
require_once __DIR__ . '/handler-sqlite-translation.php';

define( 'WP_SQLITE_AST_DRIVER', true );

$proxy = new MySQL_Proxy(
	new SQLite_Adapter( __DIR__ . '/../database/test.db' ),
	array( 'port' => 3306 )
);
$proxy->start();
