<?php
// tools/build_digests_cli.php — builds daily/weekly/monthly digests from assets/feeds/latest_*.json
$webroot = dirname(__DIR__);
$latestDir = $webroot . '/assets/feeds';
$digDir    = $webroot . '/assets/digests';
@mkdir("$digDir/daily", 0775, true);
@mkdir("$digDir/weekly", 0775, true);
@mkdir("$digDir/monthly", 0775, true);

function globLatest($dir){ return glob($dir . '/latest_*.json'); }
function loadStories($file){ $js=json_decode(file_get_contents($file),true); return $js['stories']??[]; }
function writeDigest($path,$stories){
  usort($stories, fn($a,$b)=> strtotime($b['published']??0) <=> strtotime($a['published']??0));
  $stories = array_slice($stories,0,200);
  $out=['ok'=>true,'generated'=>gmdate('c'),'count'=>count($stories),'stories'=>$stories];
  @mkdir(dirname($path),0775,true);
  file_put_contents($path, json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

$today = date('Y-m-d');
$all=[];
foreach (globLatest($latestDir) as $f) $all=array_merge($all, loadStories($f));
writeDigest("$digDir/daily/$today.json", $all);

$weekFiles=[]; for($i=0;$i<7;$i++){ $d=date('Y-m-d',strtotime("-$i day")); $p="$digDir/daily/$d.json"; if(file_exists($p)) $weekFiles[]=$p; }
$week=[]; foreach($weekFiles as $f){ $js=json_decode(file_get_contents($f),true); if($js&&!empty($js['stories'])) $week=array_merge($week,$js['stories']); }
writeDigest("$digDir/weekly/".date('o-\WW').".json", $week?:$all);

$ym = date('Y-m'); $mf=glob("$digDir/daily/$ym-*.json"); $mon=[];
foreach($mf as $f){ $js=json_decode(file_get_contents($f),true); if($js&&!empty($js['stories'])) $mon=array_merge($mon,$js['stories']); }
writeDigest("$digDir/monthly/$ym.json", $mon?:$all);

echo "Digests built.\n";
