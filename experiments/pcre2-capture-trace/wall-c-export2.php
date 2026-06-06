<?php
// Wall (c) corrected: N named groups ON the matching path, most unmatched.
// Measures the per-call cost of exporting a large $matches with
// PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL.
ini_set('pcre.jit','1');
$mk=function($N){
  // A run of N optional named groups: (?<gK>x)? . Subject 'x' matches only g0 via
  // the first; rest stay unmatched -> exported as null (or [null,-1] w/ offsets).
  $body=''; for($i=0;$i<$N;$i++){ $body.="(?<g$i>x)?"; }
  return '/\A'.$body.'\z/';
};
$subject='x'; // matches g0; g1..g(N-1) unmatched
foreach(array(100,400,800,1200,1400,1600,2000) as $N){
  $pat=$mk($N);
  $ok=@preg_match($pat,$subject,$m,PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL);
  if($ok===false){ printf("N=%d FAIL: %s\n",$N,preg_last_error_msg()); continue; }
  $named=count($m);
  $iters=50000;
  for($i=0;$i<3000;$i++)@preg_match($pat,$subject,$m,PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL);
  $be=INF; for($r=0;$r<5;$r++){ $s=microtime(true); for($i=0;$i<$iters;$i++)@preg_match($pat,$subject,$m,PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL); $d=microtime(true)-$s; if($d<$be)$be=$d; }
  for($i=0;$i<3000;$i++)@preg_match($pat,$subject);
  $bn=INF; for($r=0;$r<5;$r++){ $s=microtime(true); for($i=0;$i<$iters;$i++)@preg_match($pat,$subject); $d=microtime(true)-$s; if($d<$bn)$bn=$d; }
  printf("N=%-4d exported=%-5d  with-export=%.2f us  no-export=%.2f us  export-only=%.2f us\n",
    $N,$named,$be/$iters*1e6,$bn/$iters*1e6,($be-$bn)/$iters*1e6);
}
