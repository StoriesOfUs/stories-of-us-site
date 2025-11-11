<?php
// tools/build_digests.php
// Aggregates latest_*.json into daily/weekly/monthly digests in assets/digests/
header('Content-Type: text/plain; charset=utf-8');

$webroot = dirname(__DIR__);
$latestDir = $webroot . '/assets/feeds';
$digDir    = $webroot . '/assets/digests';
@mkdir($digDir, 0775, true);
@mkdir("$digDir/daily", 0775, true);
@mkdir("$digDir/weekly", 0775, true);
@mkdir("$digDir/monthly", 0775, true);

function globLatest($dir){ return glob($dir . '/latest_*.json'); }
function loadStories($file){
  $js = json_decode(file_get_contents($file), true);
  return $js['stories'] ?? [];
}
function writeDigest($path, $stories){
  usort($stories, fn($a,$b)=> strtotime($b['published'] ?? 0) <=> strtotime($a['published'] ?? 0));
  $stories = array_slice($stories, 0, 200);
  $out = ['ok'=>true, 'generated'=>gmdate('c'), 'count'=>count($stories), 'stories'=>$stories];
  file_put_contents($path, json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

$today = date('Y-m-d');

// DAILY from current latest_* files
$all = [];
foreach (globLatest($latestDir) as $f) $all = array_merge($all, loadStories($f));
$dailyPath = "$digDir/daily/$today.json";
writeDigest($dailyPath, $all);
echo "Daily written: assets/digests/daily/$today.json (".count($all)." items)\n";

// WEEKLY from the last 7 daily files (fallback to today if history is thin)
$weekFiles = [];
for ($i=0; $i<7; $i++){
  $d = date('Y-m-d', strtotime("-$i day"));
  $p = "$digDir/daily/$d.json";
  if (file_exists($p)) $weekFiles[] = $p;
}
$weekStories = [];
foreach ($weekFiles as $f) {
  $js = json_decode(file_get_contents($f), true);
  if ($js && !empty($js['stories'])) $weekStories = array_merge($weekStories, $js['stories']);
}
$weeklyName = date('o-\WW') . '.json';
writeDigest("$digDir/weekly/$weeklyName", $weekStories ?: $all);
echo "Weekly written: assets/digests/weekly/$weeklyName\n";

// MONTHLY from daily files in this month (fallback to today)
$ym = date('Y-m');
$monthFiles = glob("$digDir/daily/$ym-*.json");
$monthStories = [];
foreach ($monthFiles as $f) {
  $js = json_decode(file_get_contents($f), true);
  if ($js && !empty($js['stories'])) $monthStories = array_merge($monthStories, $js['stories']);
}
writeDigest("$digDir/monthly/$ym.json", $monthStories ?: $all);
echo "Monthly written: assets/digests/monthly/$ym.json\n";

echo "Done.\n";
