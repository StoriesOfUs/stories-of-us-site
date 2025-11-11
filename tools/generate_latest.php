<?php
// tools/generate_latest.php
// Builds assets/feeds/latest_<region>.json for each v01 plan by calling articles_fetch.php
header('Content-Type: text/plain; charset=utf-8');

$webroot   = dirname(__DIR__);
$plansDir  = $webroot . '/logs/digests';
$outDir    = $webroot . '/assets/feeds';
@mkdir($outDir, 0775, true);

function isPlan($name){ return (bool)preg_match('/^plan_0\d_.*\.json$/i', $name); }
function regionKey($planName){
  if (preg_match('/^plan_0\d_(.*)\.json$/i', $planName, $m)) return $m[1];
  return preg_replace('/\.json$/','',$planName);
}
function http_post_json($url, $data){
  $ctx = stream_context_create([
    'http' => [
      'method'  => 'POST',
      'header'  => "Content-Type: application/json\r\n",
      'content' => json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
      'timeout' => 60
    ]
  ]);
  return file_get_contents($url, false, $ctx);
}

$planParam = $_GET['plan'] ?? '';
$plans = [];
if ($planParam) {
  if (!isPlan($planParam)) { http_response_code(400); echo "Not a v01 plan name: $planParam\n"; exit; }
  $plans[] = $planParam;
} else {
  foreach (scandir($plansDir) as $f) if (isPlan($f)) $plans[] = $f;
}
sort($plans);
if (!$plans){ echo "No v01 plans found.\n"; exit; }

$baseUrl = 'http://localhost:8888/website_2025/articles_fetch.php';
$itemsPerSource = intval($_GET['items'] ?? 5);
$totalWritten=0;

foreach ($plans as $p) {
  $full = $plansDir . '/' . $p;
  $js = json_decode(file_get_contents($full), true);
  if (!$js || !isset($js['categorySources']) || !isset($js['regionSources'])) {
    echo "Skip (invalid plan): $p\n"; continue;
  }
  $sources = array_merge($js['categorySources'], $js['regionSources']);
  $payload = ['sources'=>$sources, 'itemsPerSource'=>$itemsPerSource];

  $resp = http_post_json($baseUrl, $payload);
  if ($resp === false) { echo "Fetch failed for $p\n"; continue; }

  $data = json_decode($resp, true);
  if (!$data || empty($data['results'])) { echo "Bad response for $p\n"; continue; }

  $stories = [];
  foreach ($data['results'] as $r) {
    if (!empty($r['items'])) {
      foreach ($r['items'] as $it) {
        $stories[] = [
          'title'     => $it['title'] ?? ($it['link'] ?? ''),
          'url'       => $it['url'] ?? ($it['link'] ?? ''),
          'image'     => $it['image'] ?? '',
          'published' => $it['published'] ?? ($it['date'] ?? ''),
          'source'    => $r['name'] ?? '',
          'region'    => regionKey($p),
          'category'  => '' // optional for now
        ];
      }
    }
  }

  usort($stories, fn($a,$b)=> strtotime($b['published'] ?? 0) <=> strtotime($a['published'] ?? 0));

  $out = ['ok'=>true,'plan'=>$p,'generated'=>gmdate('c'),'stories'=>$stories];
  $dest = $outDir . '/latest_' . regionKey($p) . '.json';
  file_put_contents($dest, json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  echo "Wrote: assets/feeds/" . basename($dest) . " (" . count($stories) . " items)\n";
  $totalWritten++;
}
echo "Done. Plans processed: $totalWritten\n";
