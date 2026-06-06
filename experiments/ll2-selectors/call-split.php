<?php
$src = '/Users/janjakes/.superset/worktrees/SQLite/parser-perf/packages/mysql-on-sqlite/src';
require_once "$src/parser/class-wp-parser-grammar.php";
require_once "$src/parser/class-wp-parser-node.php";
require_once "$src/parser/class-wp-parser-token.php";
require_once "$src/mysql/class-wp-mysql-token.php";
require_once "$src/mysql/class-wp-mysql-lexer.php";
// Subclass parser to count calls per rule.
require_once "$src/parser/class-wp-parser.php";
class Counting extends WP_Parser {
  public static $counts = array();
  protected function parse_recursive($rid){ self::$counts[$rid]=(self::$counts[$rid]??0)+1; return parent::parse_recursive($rid); }
}
$g = new WP_Parser_Grammar( require "$src/mysql/mysql-grammar.php" );
$h = fopen("$src/../tests/mysql/data/mysql-server-tests-queries.csv",'r');
$q=array(); while(($r=fgetcsv($h,null,',','"','\\'))!==false){ if(isset($r[0])&&$r[0]!=='')$q[]=$r[0]; }
fclose($h);
foreach($q as $s){ $t=(new WP_MySQL_Lexer($s))->remaining_tokens(); (new Counting($g,$t))->parse(); }
$single=0;$multi=0;
foreach(Counting::$counts as $rid=>$c){ if(isset($g->single_candidate_rules[$rid]))$single+=$c; else $multi+=$c; }
$tot=$single+$multi;
printf("total parse_recursive calls: %s\n", number_format($tot));
printf("single-candidate-rule calls: %s (%.1f%%)\n",number_format($single),100*$single/$tot);
printf("multi-candidate-rule calls:  %s (%.1f%%)\n",number_format($multi),100*$multi/$tot);
