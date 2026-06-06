<?php
/**
 * Wall (c): per-call preg_match cost when the pattern has ~1400 named groups and
 * we export $matches with PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL.
 *
 * Build a pattern with N named DEFINE'd subroutines that ACTUALLY capture (named
 * capture groups, not (?:...) DEFINE bodies — DEFINE'd groups still allocate
 * ovector slots and export as named entries). Compare per-call cost with and
 * without $matches export, sweeping N.
 */
ini_set('pcre.backtrack_limit','1000000000'); ini_set('pcre.recursion_limit','10000000'); ini_set('pcre.jit','1');
$mk=function($N){
  // N named capturing subroutines in a DEFINE block; the start expression calls
  // a handful so most slots stay unmatched (-> exercised by UNMATCHED_AS_NULL).
  $def=''; for($i=0;$i<$N;$i++){ $def.="(?<g$i>x$i?)"; }  // each is a named group that can match
  // Start: match a fixed short string, then optionally call a few subroutines.
  $start='\A(?&g0)(?&g1)(?&g2)';
  return '/(?(DEFINE)'.$def.')'.$start.'/';
};
$subject='';  // g0,g1,g2 are all 'xK?' so empty subject matches (zero-length)
foreach(array(100,400,800,1200,1400,1600) as $N){
  $pat=$mk($N);
  $ok=@preg_match($pat,$subject,$m,PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL);
  if($ok===false){ printf("N=%d FAIL: %s\n",$N,preg_last_error_msg()); continue; }
  $named=count($m);
  // Bench: with export vs without.
  $iters=100000;
  // warm
  for($i=0;$i<5000;$i++)@preg_match($pat,$subject,$m,PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL);
  $best_exp=INF; for($r=0;$r<5;$r++){ $s=microtime(true); for($i=0;$i<$iters;$i++)@preg_match($pat,$subject,$m,PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL); $d=microtime(true)-$s; if($d<$best_exp)$best_exp=$d; }
  for($i=0;$i<5000;$i++)@preg_match($pat,$subject);
  $best_no=INF; for($r=0;$r<5;$r++){ $s=microtime(true); for($i=0;$i<$iters;$i++)@preg_match($pat,$subject); $d=microtime(true)-$s; if($d<$best_no)$best_no=$d; }
  printf("N=%-4d matches-exported=%-4d  with-export=%.2f us/call  no-export=%.2f us/call  export-cost=%.2f us\n",
    $N,$named,$best_exp/$iters*1e6,$best_no/$iters*1e6,($best_exp-$best_no)/$iters*1e6);
}
