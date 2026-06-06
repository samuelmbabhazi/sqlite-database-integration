<?php
// Narrow the exact capture threshold on the full grammar, and separately probe
// a MINIMAL recursive pattern to see if a ~200-capture limit ever appears.
ini_set('pcre.backtrack_limit','1000000000'); ini_set('pcre.recursion_limit','10000000'); ini_set('pcre.jit','1');

// (1) Minimal recursive pattern: a balanced-paren rule, wrap (?&p) in N captures.
echo "== Minimal recursive (?&p) pattern: capture-count sweep ==\n";
$prev_ok=true;
for($N=50;$N<=5000;$N+=50){
  // N capture groups each wrapping a recursive call to subroutine p.
  $wrapped=str_repeat('((?&p))',$N);
  $pat='/(?(DEFINE)(?<p>a(?&p)?b))\A'.$wrapped.'\z/';
  $ok=@preg_match($pat,'',$m);
  if($ok===false){ printf("FAIL at N=%d captures, pattern=%s bytes: %s\n",$N,number_format(strlen($pat)),preg_last_error_msg()); break; }
}

// (2) JIT compile of the minimal: does jit add a tighter limit?
echo "\n== Same minimal but force a real match (JIT) ==\n";
for($N=1000;$N<=20000;$N+=1000){
  $wrapped=str_repeat('((?&p))',$N);
  $pat='/(?(DEFINE)(?<p>a(?&p)?b))\A'.$wrapped.'\z/';
  $ok=@preg_match($pat,str_repeat('ab',$N),$m);
  if($ok===false){ printf("FAIL at N=%d, pattern=%s bytes: %s\n",$N,number_format(strlen($pat)),preg_last_error_msg()); break; }
}
echo "done\n";
