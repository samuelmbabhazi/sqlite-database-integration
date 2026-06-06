<?php
// Parser action-table-style lookups: nested array vs flat array vs packed binary.
$S = 2000; $W = 300;          // states x token-width
$N = $S * $W;
$nested = array(); $flat = array(); $bin = '';
for ($s=0;$s<$S;$s++){ $nested[$s]=array(); for($t=0;$t<$W;$t++){ $v=($s*$t)&0xffff; $nested[$s][$t]=$v; $flat[$s*$W+$t]=$v; $bin.=pack('n',$v); } }
// Random probe sequence (fixed, no rand in hot loop).
$P = 1<<20; $ss=array(); $tt=array(); $x=12345;
for($i=0;$i<$P;$i++){ $x=($x*1103515245+12345)&0x7fffffff; $ss[$i]=$x%$S; $tt[$i]=($x>>8)%$W; }
function ns($dur,$ops){ return $dur/$ops*1e9; }
$best=array();
foreach(array('nested','flat','packed') as $mode){
  $bestd=INF;
  for($r=0;$r<7;$r++){
    $acc=0; $t0=microtime(true);
    if($mode==='nested'){ for($i=0;$i<$P;$i++){ $acc+=$nested[$ss[$i]][$tt[$i]]; } }
    elseif($mode==='flat'){ for($i=0;$i<$P;$i++){ $acc+=$flat[$ss[$i]*$W+$tt[$i]]; } }
    else { for($i=0;$i<$P;$i++){ $o=($ss[$i]*$W+$tt[$i])*2; $u=unpack('n',substr($bin,$o,2)); $acc+=$u[1]; } }
    $d=microtime(true)-$t0; if($d<$bestd)$bestd=$d; }
  printf("%-8s %.2f ns/lookup (acc=%d)\n",$mode,ns($bestd,$P),$acc);
  $best[$mode]=ns($bestd,$P);
}
printf("packed / flat ratio: %.1fx slower\n", $best['packed']/$best['flat']);
// Bulk decode: unpack('n*') vs ord loop, over the whole table.
$bd=INF; for($r=0;$r<7;$r++){ $t0=microtime(true); $arr=unpack('n*',$bin); $d=microtime(true)-$t0; if($d<$bd)$bd=$d; }
$od=INF; for($r=0;$r<7;$r++){ $t0=microtime(true); $a=array(); $len=strlen($bin); for($i=0;$i<$len;$i+=2){ $a[]= (ord($bin[$i])<<8)|ord($bin[$i+1]); } $d=microtime(true)-$t0; if($d<$od)$od=$d; }
printf("bulk unpack('n*'): %.4fs  ord-loop: %.4fs  => unpack %.1fx faster\n",$bd,$od,$od/$bd);
