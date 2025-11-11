<?php
// sources_health.php — Health probe with encoding-safe strategy + domain bypasses
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function bad($msg, $code=400){
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_SLASHES);
  exit;
}

$raw = file_get_contents('php://input');
if (!$raw) bad('Empty request');
$in = json_decode($raw, true);
if (!is_array($in)) bad('Invalid JSON');

$sources = isset($in['sources']) && is_array($in['sources']) ? $in['sources'] : [];
if (!$sources) bad('No sources');

// Returns host from URL or empty string
function host_of($url){
  $p = parse_url($url);
  return isset($p['host']) ? strtolower($p['host']) : '';
}

/**
 * Strategy:
 *   1) HEAD first (no body) — avoids decoding issues entirely.
 *   2) If HEAD is inconclusive, GET a tiny range with forced Accept-Encoding: gzip, deflate (no zstd/br).
 *   3) If still odd, mark WARN unless HTTP error.
 *   4) Domain bypass: for known sites with quirky encodings (e.g., wbu.ngo), treat HTTP 200 as OK.
 */
function curl_head($url, $headers = []){
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_NOBODY => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_USERAGENT => 'StoriesOfUsHealth/1.2 (+localhost)',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADER => true
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);
  return [$resp, $info, $err];
}

function curl_small_get($url, $headers = []){
  $baseHeaders = array_merge([
    // Force only gzip/deflate to avoid zstd/br issues
    'Accept-Encoding: gzip, deflate',
  ], $headers);

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_USERAGENT => 'StoriesOfUsHealth/1.2 (+localhost)',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_RANGE => '0-2048',     // tiny sniff
    CURLOPT_HEADER => true,
    CURLOPT_HTTPHEADER => $baseHeaders,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);
  if ($resp === false) return [null, $info, $err];

  $hs = $info['header_size'] ?? 0;
  $headersStr = substr($resp, 0, $hs);
  $body       = substr($resp, $hs);
  $ct         = strtolower($info['content_type'] ?? '');
  return [[
    'status' => $info['http_code'] ?? 0,
    'ct'     => $ct,
    'headers'=> $headersStr,
    'body'   => $body
  ], $info, $err];
}

$results = [];
foreach ($sources as $s) {
  $name = trim($s['name'] ?? '');
  $url  = trim($s['url']  ?? '');
  $row  = ['name'=>$name, 'url'=>$url, 'status'=>'err', 'http'=>0, 'reason'=>''];
  if (!$url) { $row['reason']='Missing URL'; $results[]=$row; continue; }

  $host = host_of($url);

  // 1) HEAD first
  list($headResp, $headInfo, $headErr) = curl_head($url);
  $http = intval($headInfo['http_code'] ?? 0);
  $row['http'] = $http;

  // Domain-specific bypasses (safe and intentional)
  $bypassHosts = ['wbu.ngo'];
  if (in_array($host, $bypassHosts, true) && $http >= 200 && $http < 400) {
    $row['status'] = 'ok';
    $row['reason'] = 'Bypass: domain OK on HEAD';
    $results[] = $row;
    continue;
  }

  if ($http >= 400 || $http === 0) {
    // HTTP failure on HEAD
    $row['status'] = 'err';
    $row['reason'] = $headErr ? ('HEAD: ' . $headErr) : ('HTTP ' . $http);
    $results[] = $row;
    continue;
  }

  // If content-type header alone looks fine, we can already mark OK
  $ctHeader = strtolower($headInfo['content_type'] ?? '');
  if (strpos($ctHeader, 'html') !== false || strpos($ctHeader, 'xml') !== false) {
    $row['status'] = 'ok';
    $row['reason'] = 'HEAD OK: ' . ($ctHeader ?: 'content-type ok');
    $results[] = $row;
    continue;
  }

  // 2) Small GET sniff with safe encodings only
  list($getResp, $getInfo, $getErr) = curl_small_get($url);
  if (!$getResp) {
    $row['status'] = 'warn';
    $row['reason'] = $getErr ?: 'GET sniff failed';
    $results[] = $row;
    continue;
  }

  $row['http'] = intval($getInfo['http_code'] ?? 0);
  $ct = $getResp['ct'];
  $body = $getResp['body'];

  $isXml  = (strpos($ct, 'xml') !== false) || preg_match('/<rss|<feed/i', $body);
  $isHtml = (strpos($ct, 'html') !== false) || preg_match('/<html/i', $body);

  if ($row['http'] >= 400 || $row['http'] == 0) {
    $row['status'] = 'err';
    $row['reason'] = 'HTTP ' . $row['http'];
  } else if ($isXml || $isHtml) {
    $row['status'] = 'ok';
    $row['reason'] = $isXml ? 'Looks like RSS/Atom' : 'Looks like HTML';
  } else {
    $row['status'] = 'warn';
    $row['reason'] = 'Unusual content-type: ' . $ct;
  }

  $results[] = $row;
}

echo json_encode(['ok'=>true,'results'=>$results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
