<?php
// Confirm: does the 76KB grammar pattern JIT-compile, or fall back to interpreted?
// Use the documented PCRE2 JIT size limit behavior: jit on small recursive => huge
// speedup; on the grammar pattern, toggling pcre.jit should change nothing if it
// can't JIT.
$base='/Users/janjakes/.superset/worktrees/SQLite/parser-perf/packages/mysql-on-sqlite';
require_once "$base/src/parser/class-wp-parser-grammar.php";
require_once "$base/src/parser/class-wp-parser-token.php";
require_once "$base/src/mysql/class-wp-mysql-token.php";
require_once "$base/src/mysql/class-wp-mysql-lexer.php";
ini_set('pcre.backtrack_limit','1000000000'); ini_set('pcre.recursion_limit','10000000');
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
$rule_to_idx=array(); $i2r=array(); foreach($compiled as $rid=>$_){ $rule_to_idx[$rid]=count($i2r); $i2r[]=$rid; }
$define=''; foreach($i2r as $rid){ $b=preg_replace_callback('/RREF(\d+)RREF/',function($m)use($rule_to_idx){return '(?&r'.$rule_to_idx[(int)$m[1]].')';},$compiled[$rid]); $define.="(?<r{$rule_to_idx[$rid]}>{$b})"; }
$pat='/(?(DEFINE)'.$define.')\A(?&r'.$rule_to_idx[$grammar->get_rule_id('query')].')\z/u';
$h=fopen("$base/tests/mysql/data/mysql-server-tests-queries.csv",'r'); $q=array(); $hdr=true;
while(($r=fgetcsv($h,null,',','"','\\'))!==false){ if($hdr){$hdr=false;continue;} if($r[0]!==null)$q[]=$r[0]; if(count($q)>=10000)break; } fclose($h);
$enc=array(); foreach($q as $x){ $s=''; foreach((new WP_MySQL_Lexer($x))->remaining_tokens() as $t)$s.=tch($t->id); $enc[]=$s; }
$bench=function($pat)use($enc){ $run=function()use($pat,$enc){$s=microtime(true);foreach($enc as $e)@preg_match($pat,$e);return microtime(true)-$s;};
  for($i=0;$i<2;$i++)$run(); $qs=array();for($r=0;$r<5;$r++)$qs[]=count($enc)/$run(); sort($qs); return $qs[count($qs)-1]; };
foreach(array('1','0') as $jit){ ini_set('pcre.jit',$jit); @preg_match($pat,"\xff"); printf("pcre.jit=%s  grammar validate=%d QPS\n",$jit,$bench($pat)); }
