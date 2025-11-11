<?php
// articles_fetch.php — v3 (encoding-safe + HTML selector auto-fallback)
// PHP 8.1+ with curl, dom, libxml, mbstring.

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (isset($_GET['ping'])) { echo json_encode(['ok'=>true,'ping'=>'pong']); exit; }
if (isset($_GET['selftest'])) {
  $ext = [
    'curl' => extension_loaded('curl'),
    'dom' => extension_loaded('dom'),
    'libxml' => extension_loaded('libxml'),
    'mbstring' => extension_loaded('mbstring')
  ];
  $flags = [
    'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
    'php_version' => PHP_VERSION
  ];
  $netOk = true; $netErr = null;
  try {
    $t = http_head('https://example.com/');
    if (($t['http_code'] ?? 0) < 200 || ($t['http_code'] ?? 0) >= 400) { $netOk=false; $netErr='example.com status '.$t['http_code']; }
  } catch (Throwable $e) { $netOk=false; $netErr=$e->getMessage(); }
  echo json_encode(['ok'=>true,'extensions'=>$ext,'flags'=>$flags,'network'=>['ok'=>$netOk,'error'=>$netErr]]);
  exit;
}

// ---- input ------------------------------------------------------------------
$raw = file_get_contents('php://input');
$body = $raw ? json_decode($raw, true) : null;

if (!$body || empty($body['sources']) || !is_array($body['sources'])) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Missing sources payload']);
  exit;
}

$itemsPerSource = max(1, (int)($body['itemsPerSource'] ?? 3));
$results = [];
foreach ($body['sources'] as $src) {
  $name = $src['name'] ?? '(unnamed)';
  $type = strtolower($src['type'] ?? 'rss');
  $url  = $src['url']  ?? null;
  $sel  = $src['selector'] ?? null;
  $headers = $src['headers'] ?? [];
  $lang = $src['accept_language'] ?? ($src['prefer_lang'] ?? null);

  if (!$url) { $results[] = ['name'=>$name,'error'=>'Missing URL']; continue; }

  try {
    if ($type === 'rss' || $type === 'atom' || $type === 'feed') {
      $r = fetch_feed($url, $itemsPerSource, $lang, $headers);
    } else { // html
      if (!$sel || !isset($sel['item'])) {
        throw new RuntimeException('Missing selector for html source');
      }
      $r = fetch_html_list($url, $sel, $itemsPerSource, $lang, $headers);
    }
    $results[] = array_merge(['name'=>$name,'url'=>$url], $r);
  } catch (Throwable $e) {
    $results[] = ['name'=>$name,'url'=>$url,'error'=>$e->getMessage()];
  }
}

echo json_encode(['ok'=>true,'results'=>$results], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

// ===== helpers ===============================================================

function default_headers(string $acceptLang = null, array $extra = []) : array {
  // Do NOT set Accept-Encoding — let libcurl advertise what it actually supports.
  $h = [
    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0 Safari/537.36',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/rss+xml;q=0.9,*/*;q=0.8',
    'Connection: keep-alive',
    'Cache-Control: no-cache',
  ];
  if ($acceptLang) $h[] = 'Accept-Language: '.$acceptLang;
  foreach ($extra as $k=>$v) {
    if (is_int($k)) { $h[] = $v; } else { $h[] = $k.': '.$v; }
  }
  return $h;
}

function http_head(string $url, ?string $acceptLang=null, array $extraHeaders=[]) : array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_HEADER => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_ENCODING => '', // let libcurl negotiate supported encodings
    CURLOPT_HTTPHEADER => default_headers($acceptLang, $extraHeaders),
  ]);
  $raw = curl_exec($ch);
  $info = curl_getinfo($ch);
  if ($raw === false) { throw new RuntimeException('HEAD failed: '.curl_error($ch)); }
  curl_close($ch);
  return $info;
}

function http_get(string $url, ?string $acceptLang=null, array $extraHeaders=[], ?string $referer=null) : array {
  $ch = curl_init($url);
  $headers = default_headers($acceptLang, $extraHeaders);
  if ($referer) $headers[] = 'Referer: '.$referer;
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_ENCODING => '', // advertise only encodings libcurl supports
    CURLOPT_HTTPHEADER => $headers,
  ]);
  $body = curl_exec($ch);
  $info = curl_getinfo($ch);
  if ($body === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('GET failed: '.$err);
  }
  $http = $info['http_code'] ?? 0;
  if ($http === 403 && !$referer) {
    curl_close($ch);
    return http_get($url, $acceptLang, $extraHeaders, parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'/');
  }
  curl_close($ch);
  if ($http >= 400) throw new RuntimeException($http.' '.$info['url']);
  return ['body'=>$body,'info'=>$info];
}

function fetch_feed(string $url, int $limit, ?string $acceptLang, array $headers) : array {
  $res = http_get($url, $acceptLang, $headers);
  $xml = @simplexml_load_string($res['body']);
  if ($xml === false) throw new RuntimeException('RSS parse failed');
  $items = [];
  if (isset($xml->channel->item)) {
    foreach ($xml->channel->item as $i) {
      $items[] = [
        'title' => trim((string)$i->title),
        'link'  => trim((string)$i->link),
        'date'  => (string)($i->pubDate ?? $i->date ?? ''),
      ];
      if (count($items) >= $limit) break;
    }
  }
  if (!$items && isset($xml->entry)) {
    foreach ($xml->entry as $e) {
      $link = '';
      if ($e->link) {
        foreach ($e->link as $lnk) {
          $attrs = $lnk->attributes(); if (!$attrs) continue;
          if ((string)$attrs->rel === 'alternate' || (string)$attrs->href) { $link = (string)$attrs->href; break; }
        }
      }
      $items[] = [
        'title' => trim((string)$e->title),
        'link'  => $link,
        'date'  => (string)($e->updated ?? $e->published ?? ''),
      ];
      if (count($items) >= $limit) break;
    }
  }
  if (!$items) throw new RuntimeException('Unrecognized feed structure');
  return ['items'=>$items];
}

function normalize_entities(string $html) : string {
  $map = [
    '&nbsp;' => '&#160;', '&raquo;' => '&#187;', '&laquo;' => '&#171;',
    '&hellip;' => '&#8230;', '&ndash;' => '&#8211;', '&mdash;' => '&#8212;',
  ];
  $html = strtr($html, $map);
  if (!mb_detect_encoding($html, 'UTF-8', true)) { $html = mb_convert_encoding($html, 'UTF-8'); }
  if (stripos($html, '<meta charset=') === false) {
    $html = preg_replace('~<head(\b[^>]*)>~i', '<head$1><meta charset="utf-8">', $html, 1);
  }
  $html = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', '', $html);
  return $html;
}

function fetch_html_list(string $url, array $sel, int $limit, ?string $acceptLang, array $headers) : array {
  $res = http_get($url, $acceptLang, $headers);
  $clean = normalize_entities($res['body']);

  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  $ok = $dom->loadHTML($clean, LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_COMPACT|LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();
  if (!$ok) throw new RuntimeException('HTML parse failed (cleaned)');

  $xp = new DOMXPath($dom);
  $q = fn($css)=>css_to_xpath($css);

  $items = [];
  $itemNodes = $xp->query($q($sel['item']));

  if (!$itemNodes || $itemNodes->length === 0) {
    // AUTO-FALLBACK: try generic anchors that look like article cards.
    $guess = $xp->query("//a[@href and normalize-space(string(.))!='']");
    $seen = [];
    foreach ($guess as $a) {
      $href = $a->getAttribute('href');
      if (!$href) continue;
      $abs = absolutize_url($href, $res['info']['url']);
      // quick heuristics
      if (!preg_match('~https?://~i', $abs)) continue;
      if (!preg_match('~/(news|story|article|press|blog|202[0-9])~i', $abs)) continue;
      $title = trim(preg_replace('~\s+~u', ' ', $a->textContent));
      if (strlen($title) < 20) continue;
      if (isset($seen[$abs])) continue;
      $seen[$abs] = true;
      $items[] = ['title'=>$title, 'link'=>$abs, 'date'=>''];
      if (count($items) >= $limit) break;
    }
    if ($items) return ['items'=>$items, 'note'=>'auto_fallback'];
    throw new RuntimeException('No items matched selector');
  }

  foreach ($itemNodes as $node) {
    $title = '';
    $link = '';
    $date = '';

    if (!empty($sel['title'])) {
      $tn = $xp->query($q($sel['title']), $node);
      if ($tn && $tn->length) $title = trim(node_text($tn->item(0)));
    }
    if (!empty($sel['link'])) {
      $ln = $xp->query($q(preg_replace('~\[(href|src)\]~i', '', $sel['link'])), $node);
      if ($ln && $ln->length) {
        $a = $ln->item(0);
        $href = $a->getAttribute('href') ?: $a->getAttribute('src');
        if ($href) $link = absolutize_url($href, $res['info']['url']);
      }
    }
    if (!empty($sel['date'])) {
      $dn = $xp->query($q($sel['date']), $node);
      if ($dn && $dn->length) $date = trim(node_text($dn->item(0)));
    }

    if (!$title) $title = trim(node_text($node));
    if (!$link) {
      $a = $xp->query('.//a[@href]', $node);
      if ($a && $a->length) $link = absolutize_url($a->item(0)->getAttribute('href'), $res['info']['url']);
    }

    if ($title || $link) { $items[] = ['title'=>$title, 'link'=>$link, 'date'=>$date]; }
    if (count($items) >= $limit) break;
  }

  if (!$items) throw new RuntimeException('No items extracted');
  return ['items'=>$items];
}

function node_text(?DOMNode $n) : string {
  return $n ? trim(preg_replace('~\s+~u', ' ', $n->textContent)) : '';
}

function absolutize_url(string $maybe, string $base) : string {
  if (preg_match('~^https?://~i', $maybe)) return $maybe;
  $p = parse_url($base); if (!$p) return $maybe;
  if (str_starts_with($maybe, '//')) return $p['scheme'].':'.$maybe;
  if (str_starts_with($maybe, '/')) return $p['scheme'].'://'.$p['host'].$maybe;
  $dir = isset($p['path']) ? preg_replace('~[^/]+$~','',$p['path']) : '/';
  return $p['scheme'].'://'.$p['host'].$dir.$maybe;
}

// very small CSS→XPath subset
function css_to_xpath(string $css) : string {
  $parts = preg_split('~\s+~', trim($css)); $xp = '.';
  foreach ($parts as $p) {
    $seg='*'; $pred=[];
    if (preg_match('~^[a-z0-9_-]+~i', $p, $m)) { $seg = $m[0]; }
    if (preg_match_all('~\.([a-z0-9_-]+)~i', $p, $m)) { foreach ($m[1] as $c) $pred[] = "contains(concat(' ', normalize-space(@class), ' '), ' $c ')"; }
    if (preg_match('~#([a-z0-9_-]+)~i', $p, $m)) { $pred[] = "@id='{$m[1]}'"; }
    if (preg_match_all('~\[(href|src)\]~i', $p, $m)) { foreach ($m[1] as $a) $pred[] = "@$a"; }
    $xp .= '//'.$seg.($pred ? '['.implode(' and ', $pred).']' : '');
  }
  return $xp;
}
