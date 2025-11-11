<?php
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$js  = json_decode($raw, true);
if (!$js) { echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$name = trim($js['name'] ?? '');
$plan = $js['plan'] ?? null;
if (!$plan) { echo json_encode(['ok'=>false,'error'=>'Missing plan data']); exit; }

$dir = __DIR__ . '/logs/digests';
if (!is_dir($dir)) mkdir($dir, 0777, true);

// if no name provided, auto-name
if ($name === '') {
  $name = 'plan_' . date('Y-m-d\TH-i-s\Z') . '.json';
}

$path = "$dir/$name";
file_put_contents($path, json_encode($plan, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

echo json_encode(['ok'=>true, 'saved_as'=>$name]);
