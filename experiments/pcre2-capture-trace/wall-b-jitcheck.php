<?php
// Is JIT actually compiling the 76KB grammar pattern? Compare a SMALL recursive
// pattern (definitely JIT-able) with 0 vs 6 captures around its recursive call.
ini_set('pcre.jit','1'); ini_set('pcre.backtrack_limit','1000000000'); ini_set('pcre.recursion_limit','10000000');
// Small recursive arithmetic-ish grammar over bytes.
$def0='(?<e>(?&t)(?:[+\-](?&t))*)(?<t>(?&f)(?:[*\/](?&f))*)(?<f>[0-9]+|\((?&e)\))';
$pat0='/(?(DEFINE)'.$def0.')\A(?&e)\z/';
// Same but wrap the 6 (?&...) call sites in captures.
$def6='(?<e>((?&t))(?:[+\-]((?&t)))*)(?<t>((?&f))(?:[*\/]((?&f)))*)(?<f>[0-9]+|\(((?&e)))\))';
$pat6='/(?(DEFINE)'.$def6.')\A((?&e))\z/';
// Build inputs: nested expressions.
function gen($d){ if($d<=0)return (string)mt_rand(0,9); return '('.gen($d-1).'+'.gen($d-1).'*'.gen($d-1).')'; }
mt_srand(1); $inp=array(); for($i=0;$i<20000;$i++)$inp[]=gen(3);
$bench=function($pat)use($inp){ $run=function()use($pat,$inp){$s=microtime(true);foreach($inp as $x)@preg_match($pat,$x);return microtime(true)-$s;};
  for($i=0;$i<3;$i++)$run(); $qs=array(); for($r=0;$r<7;$r++)$qs[]=count($inp)/$run(); sort($qs); return $qs[count($qs)-1]; };
@preg_match($pat0,'1'); $q0=$bench($pat0);
@preg_match($pat6,'1'); $q6=$bench($pat6);
printf("small recursive: 0-cap=%d QPS  6-cap=%d QPS  ratio=%.2fx\n",$q0,$q6,$q0/$q6);
