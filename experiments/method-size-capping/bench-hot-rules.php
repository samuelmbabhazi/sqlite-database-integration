<?php
/** Count per-rule call frequency on the MySQL corpus. */

set_error_handler(
	function ( $s, $m, $f, $l ) {
		throw new ErrorException( $m, 0, $s, $f, $l );
	}
);

require_once __DIR__ . '/../../src/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-node.php';
require_once __DIR__ . '/../../src/parser/class-wp-parser-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/../../src/mysql/class-wp-mysql-lexer.php';

class HR_Parser {
	public static $counts = array();
	public $grammar;
	public $tokens;
	public $token_count;
	public $position;
	private $rule_names;
	private $fragment_ids;
	private $branches_for_token;
	private $nullable_branches;
	private $highest_terminal_id;
	private $sel_rid;

	public function __construct( WP_Parser_Grammar $g, array $tokens ) {
		$this->grammar             = $g;
		$this->token_count         = count( $tokens );
		$tokens[]                  = new WP_Parser_Token( 0, 0, 0, '' );
		$this->tokens              = $tokens;
		$this->position            = 0;
		$this->rule_names          = $g->rule_names;
		$this->fragment_ids        = $g->fragment_ids ?? array();
		$this->branches_for_token  = $g->branches_for_token;
		$this->nullable_branches   = $g->nullable_branches;
		$this->highest_terminal_id = $g->highest_terminal_id;
		$this->sel_rid             = $g->get_rule_id( 'selectStatement' );
	}
	public function parse() {
		$rid = $this->grammar->get_rule_id( 'query' );
		return $this->r( $rid );
	}
	private function r( $rid ) {
		self::$counts[ $rid ] = ( self::$counts[ $rid ] ?? 0 ) + 1;
		$tokens               = $this->tokens;
		$position             = $this->position;
		$tid                  = $tokens[ $position ]->id;
		if ( isset( $this->branches_for_token[ $rid ][ $tid ] ) ) {
			$cb = $this->branches_for_token[ $rid ][ $tid ];
		} elseif ( isset( $this->nullable_branches[ $rid ] ) ) {
			return true;
		} else {
			return false;
		}
		$htid        = $this->highest_terminal_id;
		$is_fragment = isset( $this->fragment_ids[ $rid ] );
		$is_sel      = $rid === $this->sel_rid;
		$ok          = false;
		$kids        = array();
		foreach ( $cb as $branch ) {
			$this->position = $position;
			$kids           = array();
			$ok             = true;
			foreach ( $branch as $sid ) {
				if ( $sid <= $htid ) {
					if ( $tokens[ $this->position ]->id === $sid ) {
						$kids[] = $tokens[ $this->position ];
						++$this->position;
						continue;
					}
					$ok = false;
					break;
				}
				$sn = $this->r( $sid );
				if ( false === $sn ) {
					$ok = false;
					break;
				}
				if ( true === $sn ) {
					continue;
				}
				if ( is_array( $sn ) ) {
					foreach ( $sn as $c ) {
						$kids[] = $c;
					}
				} else {
					$kids[] = $sn;
				}
			}
			if ( $ok && $is_sel && WP_MySQL_Lexer::INTO_SYMBOL === $tokens[ $this->position ]->id ) {
				$ok = false;
			}
			if ( $ok ) {
				break;
			}
		}
		if ( ! $ok ) {
			$this->position = $position;
			return false;
		}
		if ( ! $kids ) {
			return true;
		}
		if ( $is_fragment ) {
			return $kids;
		}
		return new WP_Parser_Node( $rid, $this->rule_names[ $rid ], $kids );
	}
}

$grammar = new WP_Parser_Grammar( require __DIR__ . '/../../src/mysql/mysql-grammar.php' );
$handle  = fopen( __DIR__ . '/../mysql/data/mysql-server-tests-queries.csv', 'r' );
$queries = array();
$header  = true;
while ( ( $r = fgetcsv( $handle, null, ',', '"', '\\' ) ) !== false ) {
	if ( $header ) {
		$header = false;
		continue;
	}
	if ( null !== $r[0] ) {
		$queries[] = $r[0];
	}
}
$queries    = array_slice( $queries, 0, (int) ( $argv[1] ?? 10000 ) );
$all_tokens = array();
foreach ( $queries as $q ) {
	$all_tokens[] = ( new WP_MySQL_Lexer( $q ) )->remaining_tokens();
}

foreach ( $all_tokens as $t ) {
	( new HR_Parser( $grammar, $t ) )->parse();
}
arsort( HR_Parser::$counts );
if ( getenv( 'DUMP_TOPN' ) ) {
	$topn = (int) getenv( 'DUMP_TOPN' );
	file_put_contents( "/tmp/top{$topn}.txt", implode( "\n", array_slice( array_keys( HR_Parser::$counts ), 0, $topn ) ) . "\n" );
	fwrite( STDERR, "Wrote /tmp/top{$topn}.txt\n" );
}
$total   = array_sum( HR_Parser::$counts );
$cumsum  = 0;
$covered = array();
$i       = 0;
foreach ( HR_Parser::$counts as $rid => $cnt ) {
	$cumsum         += $cnt;
	$covered[ $rid ] = true;
	$pct             = 100 * $cumsum / $total;
	if ( in_array( ++$i, array( 10, 25, 50, 100, 200, 500 ), true ) || $pct >= 80 ) {
		printf( "After top %d rules: cumulative %.1f%% (%s of %s calls)\n", $i, $pct, number_format( $cumsum ), number_format( $total ) );
		if ( $pct >= 95 ) {
			break;
		}
	}
}
