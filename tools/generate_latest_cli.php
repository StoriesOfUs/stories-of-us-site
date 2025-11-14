<?php
// tools/generate_latest_cli.php
// Builds assets/feeds/latest_<region>.json for each v01 plan.
// Designed for GitHub Actions / CLI use (no web server needed).

$webroot   = dirname(__DIR__);
$plansDir  = $webroot . '/logs/digests';
$outDir    = $webroot . '/assets/feeds';
@mkdir($outDir, 0775, true);

function isPlan($name){ return (bool)preg_match('/^plan_0\d_.*\.json$/i', $name); }
function regionKey($planName){
  if (preg_match('/^plan_0\d_(.*)\.json$/i', $planName, $m)) return $m[1];
  return preg_replace('/\.json$/','',$planName);
}

// Map source name → category label (you can tweak these later)
function categoryForSource($name){
  $map = [
    'Amnesty International – Latest'              => 'Activism',
    'Grassroots International — Google News'     => 'Activism',
    'Fund for Global Human Rights – Latest'      => 'Activism',
    'Global Justice Now – News'                  => 'Activism',
    'Equal Times'                                => 'General',
    'DRA – Disability Rights Advocates'          => 'Access',
    'PWDA – People With Disability Australia'    => 'Access',
    'WID – Blog'                                 => 'Tech',
    'AAPD – Media'                               => 'General',
    'Inclusion International – News'             => 'Access',
    'ENIL – News'                                => 'Access',
    'World Blind Union – News & Events'          => 'Access',
    'IDDC – Blog'                                => 'History',
    'NPR – World'                                => 'General',
    'The Globe and Mail – World'                 => 'General',
    'BBC Mundo — Mundo'                          => 'General',
    'DW Español — Mundo'                         => 'General',
    'BBC World — Top Stories'                    => 'General',
    'DW — World'                                 => 'General',
    'Euronews — Europe'                          => 'General',
    'RFE/RL — News'                              => 'General',
    'Al Jazeera — All'                           => 'General',
    'Arab News — RSS'                            => 'General',
    'AllAfrica — Latest'                         => 'General',
    'Africanews — News'                          => 'General',
    'NHK World — News'                           => 'General',
    'SCMP — Asia'                                => 'General',
    'The Hindu — News'                           => 'General',
    'Dawn — Latest'                              => 'General',
    'The Straits Times — Asia'                   => 'General',
    'Bangkok Post — Top Stories'                 => 'General',
    'ABC News (AU) — Just In'                    => 'General',
    'NZ Herald — RSS'                            => 'General',
  ];
  return $map[$name] ?? 'Latest';
}

// Simple HTTP GET with curl (RSS/Atom or HTML)
function http_get($url, $timeout=20){
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_USERAGENT => 'StoriesOfUsBot/1.0 (GitHub Actions)',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_ENCODING => '', // auto (gzip/deflate/br)
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($body === false || $code >= 400 || $code === 0) return [null, $code, $ct, $err ?: "HTTP $code"];
  return [$body, $code, $ct, null];
}

function parse_rss_atom($xml, $limit){
  libxml_use_internal_errors(true);
  $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
  if (!$sx) return null;
  $items = [];

  // RSS
  if (isset($sx->channel->item)) {
    foreach ($sx->channel->item as $it) {
      $title = trim((string)$it->title);
      $link  = trim((string)$it->link);
      if (!$link && isset($it->guid)) $link = trim((string)$it->guid);
      $date  = trim((string)$it->pubDate);
      // description / summary
      $desc  = trim((string)$it->description);
      if (!$desc && isset($it->children('content', true)->encoded)) {
        $desc = trim((string)$it->children('content', true)->encoded);
      }
      $desc = strip_tags($desc);
      if (strlen($desc) > 500) $desc = substr($desc, 0, 500) . '…';

      $img = '';
      if (isset($it->enclosure) && strpos((string)$it->enclosure['type'], 'image/') === 0) {
        $img = (string)$it->enclosure['url'];
      }

      if ($title || $link) {
        $items[] = [
          'title'     => $title ?: '(untitled)',
          'url'       => $link,
          'image'     => $img,
          'published' => $date,
          'summary'   => $desc
        ];
      }
      if (count($items) >= $limit) break;
    }
    return $items;
  }

  // Atom
  if (isset($sx->entry)) {
    foreach ($sx->entry as $it) {
      $title = trim((string)$it->title);
      $link  = '';
      if (isset($it->link)) {
        foreach ($it->link as $l) {
          $href = (string)$l['href']; $rel = (string)$l['rel'];
          if ($rel === 'alternate' || $rel === '' || !$link) $link = $href;
        }
      }
      $date = trim((string)$it->updated) ?: trim((string)$it->published);
      $desc = trim((string)$it->summary);
      if (!$desc && isset($it->content)) $desc = trim((string)$it->content);
      $desc = strip_tags($desc);
      if (strlen($desc) > 500) $desc = substr($desc, 0, 500) . '…';

      $items[] = [
        'title'     => $title ?: '(untitled)',
        'url'       => $link,
        'image'     => '',
        'published' => $date,
        'summary'   => $desc
      ];
      if (count($items) >= $limit) break;
    }
    return $items;
  }

  return null;
}

// HTML fallback — try <meta description> and <title>
function html_fallback($html){
  $title = '';
  $desc  = '';
  if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i',$html,$m))
    $title = html_entity_decode($m[1], ENT_QUOTES|ENT_HTML5,'UTF-8');
  if (!$title && preg_match('/<title>(.*?)<\/title>/is',$html,$m))
    $title = trim(html_entity_decode($m[1], ENT_QUOTES|ENT_HTML5,'UTF-8'));

  if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i',$html,$m))
    $desc = html_entity_decode($m[1], ENT_QUOTES|ENT_HTML5,'UTF-8');
  $desc = strip_tags($desc);

  if (!$title) return [];
  return [[
    'title'     => $title,
    'url'       => '',
    'image'     => '',
    'published' => '',
    'summary'   => $desc
  ]];
}

$itemsPerSource = 5;
$plans = [];
foreach (scandir($plansDir) as $f) if (isPlan($f)) $plans[] = $f;
sort($plans);
if (!$plans){ echo "No v01 plans found.\n"; exit(0); }

$total=0;
foreach ($plans as $p) {
  $full = $plansDir . '/' . $p;
  $plan = json_decode(file_get_contents($full), true);
  if (!$plan || !isset($plan['categorySources']) || !isset($plan['regionSources'])) {
    echo "Skip (invalid plan): $p\n"; continue;
  }
  $sources = array_merge($plan['categorySources'], $plan['regionSources']);

  $stories = [];
  foreach ($sources as $s) {
    $name = $s['name'] ?? ''; $url = $s['url'] ?? '';
    if (!$url) continue;
    [$body,$code,$ct,$err] = http_get($url);
    if ($body === null) {
      echo "Source failed: $name ($url) — $err\n";
      continue;
    }

    $items = null;
    $looksXml = (stripos($ct,'xml')!==false) || stripos($body,'<rss')!==false || stripos($body,'<feed')!==false;
    if ($looksXml) $items = parse_rss_atom($body, $itemsPerSource);
    if ($items === null) {
      $looksHtml = (stripos($ct,'html')!==false) || preg_match('/<html/i',$body);
      if ($looksHtml) $items = html_fallback($body);
    }
    if (!$items) continue;

    $cat = categoryForSource($name);
    foreach ($items as $it) {
      $stories[] = [
        'title'     => $it['title'] ?? '',
        'url'       => $it['url'] ?? '',
        'image'     => $it['image'] ?? '',
        'published' => $it['published'] ?? '',
        'source'    => $name,
        'region'    => regionKey($p),
        'category'  => $cat,
        'summary'   => $it['summary'] ?? ''
      ];
    }
  }

  usort($stories, fn($a,$b)=> strtotime($b['published'] ?? 0) <=> strtotime($a['published'] ?? 0));
  $out = ['ok'=>true,'plan'=>$p,'generated'=>gmdate('c'),'stories'=>$stories];
  $dest = $outDir . '/latest_' . regionKey($p) . '.json';
  @mkdir(dirname($dest), 0775, true);
  file_put_contents($dest, json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  echo "Wrote: assets/feeds/" . basename($dest) . " (" . count($stories) . " items)\n";
  $total++;
}
echo "Done. Plans processed: $total\n";
