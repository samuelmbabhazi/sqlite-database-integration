<?php
/**
 * Wall (a): how many numbered captures wrapping a recursive (?&rule) reference
 * can a PCRE2 pattern hold before compilation fails (bytecode/source limit)?
 *
 * We build the real recursive grammar DEFINE block (via the v3 builder logic),
 * then wrap N of its (?&rN) subroutine call sites in numbered capture groups
 * and try to compile. We sweep N and report the first failure.
 */
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$base='/Users/janjakes/.superset/worktrees/SQLite/parser-perf/packages/mysql-on-sqlite';
require_once "$base/src/parser/class-wp-parser-grammar.php";
require_once "$base/src/parser/class-wp-parser-token.php";
require_once "$base/src/mysql/class-wp-mysql-token.php";
require_once "$base/src/mysql/class-wp-mysql-lexer.php";
ini_set('pcre.backtrack_limit','1000000000');
ini_set('pcre.recursion_limit','10000000');
ini_set('pcre.jit','1');
const TOKEN_OFFSET=0x4000;
function tch($t){return mb_chr($t+TOKEN_OFFSET,'UTF-8');}

// --- Reproduce the v3 compiled DEFINE block (no captures) ---
$grammar=new WP_Parser_Grammar(require "$base/src/mysql/mysql-grammar.php");
$low_nt=$grammar->lowest_non_terminal_id; $rules=$grammar->rules;
$single=$grammar->single_candidate_rules ?? array();
$select_rid=$grammar->get_rule_id('selectStatement');
$into=tch(WP_MySQL_Lexer::INTO_SYMBOL);
$compiled=array();
$compile=function($rid)use(&$compiled,$rules,$low_nt,$single,$select_rid,$into){
  if(isset($compiled[$rid]))return $compiled[$rid];
  $alts=array(); $safe=isset($single[$rid]);
  foreach($rules[$rid] as $branch){ $alt='';
    foreach($branch as $i=>$sym){ $alt.= $sym<$low_nt ? tch($sym) : "RREF{$sym}RREF"; if($i===0&&$safe)$alt.='(*THEN)'; }
    $alts[]=$alt; }
  $body='(?:'.implode('|',$alts).')'; if($rid===$select_rid)$body.='(?!'.$into.')';
  return $compiled[$rid]=$body;
};
foreach(array_keys($rules) as $rid)$compile($rid);
// inline single-use non-recursive rules to fixpoint
do{ $changed=false; $refs=array();
  foreach($compiled as $rid=>$b)$refs[$rid]=0;
  foreach($compiled as $b){ if(preg_match_all('/RREF(\d+)RREF/',$b,$m))foreach($m[1] as $r)$refs[(int)$r]=($refs[(int)$r]??0)+1; }
  foreach($compiled as $rid=>$b){ if(($refs[$rid]??0)!==1)continue; if(strpos($b,"RREF{$rid}RREF")!==false)continue;
    foreach($compiled as $crid=>$cb){ if(strpos($cb,"RREF{$rid}RREF")!==false){ $compiled[$crid]=str_replace("RREF{$rid}RREF",$b,$cb); unset($compiled[$rid]); $changed=true; break 2; } } }
}while($changed);
$rule_to_idx=array(); $idx_to_rule=array();
foreach($compiled as $rid=>$_){ $rule_to_idx[$rid]=count($idx_to_rule); $idx_to_rule[]=$rid; }
// Build define with a callback that can OPTIONALLY wrap the (?&rN) call sites in captures.
$build_define=function($num_captures)use($compiled,$idx_to_rule,$rule_to_idx){
  $wrapped=0; $define='';
  foreach($idx_to_rule as $rid){
    $body=$compiled[$rid];
    $body=preg_replace_callback('/RREF(\d+)RREF/',function($m)use($rule_to_idx,&$wrapped,$num_captures){
      $ref='(?&r'.$rule_to_idx[(int)$m[1]].')';
      if($wrapped<$num_captures){ $wrapped++; return '('.$ref.')'; }  // numbered capture wrapping (?&rule)
      return $ref;
    },$body);
    $define.="(?<r{$rule_to_idx[$rid]}>{$body})";
  }
  return array($define,$wrapped);
};
$start_rid=$grammar->get_rule_id('query');
$total_callsites=0;
foreach($compiled as $b){ $total_callsites+=preg_match_all('/RREF\d+RREF/',$b,$x); }
printf("Final DEFINE rules=%d, total (?&rule) call sites=%d\n",count($idx_to_rule),$total_callsites);

// Sweep number of captures.
$ns=array(0,50,100,150,200,250,300,400,600,800,1000,1500,2000,$total_callsites);
foreach($ns as $N){
  list($define,$wrapped)=$build_define($N);
  $pattern='/(?(DEFINE)'.$define.')\A(?&r'.$rule_to_idx[$start_rid].')\z/u';
  $ok=@preg_match($pattern,"\xff");
  $err=preg_last_error();
  $errmsg=preg_last_error_msg();
  $compile_ok = !($ok===false && $err!==PREG_BAD_UTF8_ERROR);
  printf("captures=%-5d wrapped=%-5d pattern=%s bytes -> %s%s\n",
    $N,$wrapped,number_format(strlen($pattern)),
    $compile_ok?'COMPILES':'FAIL',
    $compile_ok?'':" ($errmsg)");
  if(!$compile_ok && $err!==PREG_BAD_UTF8_ERROR) break;
}
