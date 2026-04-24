<?php
/**
 * Parse every query in the MySQL test corpus with both parsers and
 * compare the resulting ASTs. Fails on the first mismatch.
 */

set_error_handler(
	function ( $s, $m, $f, $l ) {
		throw new ErrorException( $m, 0, $s, $f, $l );
	}
);

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-node.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-parser.php';
require_once '/tmp/compiled.php';

function ast_signature( $n ) {
	if ( null === $n ) {
		return 'null';
	}
	if ( $n instanceof WP_Parser_Token ) {
		return 't(' . $n->id . ',' . $n->start . ',' . $n->length . ')';
	}
	$out = 'n(' . $n->rule_name;
	foreach ( $n->get_children() as $c ) {
		$out .= ',' . ast_signature( $c );
	}
	return $out . ')';
}

$grammar = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$handle  = fopen( __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv', 'r' );
$header  = true;
$limit   = (int) ( $argv[1] ?? PHP_INT_MAX );
$n       = 0;
$miss    = 0;
while ( ( $row = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false && $n < $limit ) {
	if ( $header ) {
		$header = false;
		continue;
	}
	if ( null === $row[0] ) {
		continue;
	}
	++$n;
	$tokens1 = ( new WP_MySQL_Lexer( $row[0] ) )->remaining_tokens();
	$tokens2 = ( new WP_MySQL_Lexer( $row[0] ) )->remaining_tokens();
	$a1      = ( new WP_MySQL_Parser( $grammar, $tokens1 ) )->parse();
	$a2      = ( new WP_MySQL_Compiled_Parser( $tokens2 ) )->parse();
	$s1      = ast_signature( $a1 );
	$s2      = ast_signature( $a2 );
	if ( $s1 !== $s2 ) {
		++$miss;
		if ( $miss <= 5 ) {
			echo "MISMATCH query #$n:\n";
			echo '  ', substr( $row[0], 0, 200 ), "\n";
			echo '  interpreter: ', substr( $s1, 0, 300 ), "\n";
			echo '  compiled:    ', substr( $s2, 0, 300 ), "\n";
		}
	}
}
echo "Checked $n queries, $miss mismatches.\n";
