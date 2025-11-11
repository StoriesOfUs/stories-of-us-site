<?php
// fetch_plan.php — v3
// Accepts ?name=... (optional). If missing, returns newest *.json plan.
// Supports any plan filename (digest_* OR plan_*).

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__FILE__);
$dir  = $root . '/logs/digests';

// helper: newest file in dir
function newest_plan($dir) {
  if (!is_dir($dir)) return null;
  $newest = null;
  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    if (substr($f, -5) !== '.json') continue;
    $p = $dir.'/'.$f;
    if (!is_file($p)) continue;
    if ($newest === null || filemtime($p) > filemtime($newest)) {
      $newest = $p;
    }
  }
  return $newest;
}

$name = isset($_GET['name']) ? trim($_GET['name']) : '';
$path = '';

if ($name === '') {
  $path = newest_plan($dir);
  if (!$path) {
    echo json_encode(['ok'=>false,'error'=>'No plan files found']);
    exit;
  }
  $name = basename($path);
} else {
  // basic sanitization
  $name = basename($name);
  $path = $dir.'/'.$name;
  if (!is_file($path)) {
    echo json_encode(['ok'=>false,'error'=>"Plan not found: $path"]);
    exit;
  }
}

$json = file_get_contents($path);
if ($json === false) {
  echo json_encode(['ok'=>false,'error'=>"Failed to read plan: $path"]);
  exit;
}
$plan = json_decode($json, true);
if (!$plan) {
  echo json_encode(['ok'=>false,'error'=>'Failed to parse plan JSON']);
  exit;
}

echo json_encode(['ok'=>true,'name'=>$name,'plan'=>$plan], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
