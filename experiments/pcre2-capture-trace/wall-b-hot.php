<?php
// Wall (b) variant: wrap captures around the HOT recursive call sites that fire
// on every query (expression cascade + query/select chain), not arbitrary ones.
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$base='/Users/janjakes/.superset/worktrees/SQLite/parser-perf/packages/mysql-on-sqlite';
require_once "$base/src/parser/class-wp-parser-grammar.php";
require_once "$base/src/parser/class-wp-parser-token.php";
require_once "$base/src/mysql/class-wp-mysql-token.php";
require_once "$base/src/mysql/class-wp-mysql-lexer.php";
ini_set('pcre.backtrack_limit','1000000000'); ini_set('pcre.recursion_limit','10000000'); ini_set('pcre.jit','1');
const TOKEN_OFFSET=0x4000; function tch($t){return mb_chr($t+TOKEN_OFFSET,'UTF-8');}
$grammar=new WP_Parser_Grammar(require "$base/src/mysql/mysql-grammar.php");
$low_nt=$grammar->lowest_non_terminal_id; $rules=$grammar->rules;
$single=$grammar->single_candidate_rules ?? array();
$select_rid=$grammar->get_rule_id('selectStatement'); $into=tch(WP_MySQL_Lexer::INTO_SYMBOL);
$compiled=array();
$compile=function($rid)use(&$compiled,$rules,$low_nt,$single,$select_rid,$into){
  if(isset($compiled[$rid]))return $compiled[$rid]; $alts=array(); $safe=isset($single[$rid]);
  foreach($rules[$rid] as $branch){ $alt=''; foreach($branch as $i=>$sym){ $alt.= $sym<$low_nt?tch($sym):"RREF{$sym}RREF"; if($i===0&&$safe)$alt.='(*THEN)'; } $alts[]=$alt; }
  $body='(?:'.implode('|',$alts).')'; if($rid===$select_rid)$body.='(?!'.$into.')'; return $compiled[$rid]=$body; };
foreach(array_keys($rules) as $rid)$compile($rid);
do{ $changed=false; $refs=array(); foreach($compiled as $rid=>$b)$refs[$rid]=0;
  foreach($compiled as $b){ if(preg_match_all('/RREF(\d+)RREF/',$b,$m))foreach($m[1] as $r)$refs[(int)$r]=($refs[(int)$r]??0)+1; }
  foreach($compiled as $rid=>$b){ if(($refs[$rid]??0)!==1)continue; if(strpos($b,"RREF{$rid}RREF")!==false)continue;
    foreach($compiled as $crid=>$cb){ if(strpos($cb,"RREF{$rid}RREF")!==false){ $compiled[$crid]=str_replace("RREF{$rid}RREF",$b,$cb); unset($compiled[$rid]); $changed=true; break 2; } } }
}while($changed);
$rule_to_idx=array(); $idx_to_rule=array();
foreach($compiled as $rid=>$_){ $rule_to_idx[$rid]=count($idx_to_rule); $idx_to_rule[]=$rid; }
$start_rid=$grammar->get_rule_id('query');
// Hot rules: expression cascade. Find their rule ids if present after inlining.
$hot_names=array('expr','boolPri','predicate','bitExpr','simpleExpr','exprList','primaryExpr');
$hot_idx=array();
foreach($hot_names as $nm){ $rid=@$grammar->get_rule_id($nm); if($rid!==null&&isset($rule_to_idx[$rid]))$hot_idx[$rule_to_idx[$rid]]=$nm; }
echo "Hot rules surviving inlining: ".implode(',',array_values($hot_idx))." (".count($hot_idx)." rules)\n";

$build=function($ncap,$hot_only)use($compiled,$idx_to_rule,$rule_to_idx,$start_rid,$hot_idx){
  $wrapped=0; $define='';
  foreach($idx_to_rule as $rid){ $body=$compiled[$rid];
    $body=preg_replace_callback('/RREF(\d+)RREF/',function($m)use($rule_to_idx,&$wrapped,$ncap,$hot_only,$hot_idx){
      $tgt=$rule_to_idx[(int)$m[1]]; $ref='(?&r'.$tgt.')';
      $eligible = $hot_only ? isset($hot_idx[$tgt]) : true;
      if($eligible && $wrapped<$ncap){$wrapped++;return '('.$ref.')';} return $ref; },$body);
    $define.="(?<r{$rule_to_idx[$rid]}>{$body})"; }
  return array('/(?(DEFINE)'.$define.')\A(?&r'.$rule_to_idx[$start_rid].')\z/u',$wrapped); };

$h=fopen("$base/tests/mysql/data/mysql-server-tests-queries.csv",'r'); $queries=array(); $hdr=true;
while(($r=fgetcsv($h,null,',','"','\\'))!==false){ if($hdr){$hdr=false;continue;} if($r[0]!==null)$queries[]=$r[0]; if(count($queries)>=30000)break; }
fclose($h);
$enc=array(); foreach($queries as $q){ $s=''; foreach((new WP_MySQL_Lexer($q))->remaining_tokens() as $t)$s.=tch($t->id); $enc[]=$s; }
$n=count($enc);
$bench=function($pat)use($enc,$n){ $run=function()use($pat,$enc){ $s=microtime(true); foreach($enc as $e)@preg_match($pat,$e); return microtime(true)-$s; };
  for($i=0;$i<2;$i++)$run(); $qs=array(); for($r=0;$r<7;$r++)$qs[]=$n/$run(); sort($qs); return $qs[count($qs)-1]; };

foreach(array(array(0,false),array(6,true),array(50,true),array(200,true)) as $cfg){
  list($ncap,$hot)=$cfg; list($pat,$w)=$build($ncap,$hot);
  @preg_match($pat,"\xff"); $qps=$bench($pat);
  printf("captures=%-3d (hot=%s, wrapped=%d)  validate=%d QPS\n",$ncap,$hot?'y':'n',$w,$qps);
}
