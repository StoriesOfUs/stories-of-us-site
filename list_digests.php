<?php
// list_digests.php — returns all *.json under logs/digests/ as JSON
header('Content-Type: application/json; charset=utf-8');

$root = __DIR__;
$dir  = $root . '/logs/digests';

if (!is_dir($dir)) {
  echo json_encode(['ok'=>false,'error'=>"Missing directory: $dir"]);
  exit;
}

$items = [];
$dh = opendir($dir);
if ($dh === false) {
  echo json_encode(['ok'=>false,'error'=>'Failed to open digests directory']);
  exit;
}

while (($f = readdir($dh)) !== false) {
  if ($f === '.' || $f === '..') continue;
  if (substr($f, -5) !== '.json') continue;
  $p = $dir . '/' . $f;
  if (!is_file($p)) continue;
  $items[] = [
    'name'  => $f,
    'path'  => $p,
    'mtime' => @filemtime($p) ?: 0
  ];
}
closedir($dh);

// newest first
usort($items, function($a,$b){ return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0); });

echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
