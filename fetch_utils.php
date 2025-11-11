<?php
// fetch_utils.php — helpers for polite HTTP, robots, and parsing

function fu_user_agent() {
    return "StoriesOfUsFetcher/1.0 (+https://example.org/contact) PHP/" . PHP_VERSION;
}

function fu_host($url) {
    $p = parse_url($url);
    return $p['scheme'].'://'.$p['host'];
}

function fu_http_get($url, $accept = null, $acceptLanguage = null, $timeout = 12) {
    $ch = curl_init($url);
    $headers = ["User-Agent: " . fu_user_agent()];
    if ($accept) $headers[] = "Accept: $accept";
    if ($acceptLanguage) $headers[] = "Accept-Language: $acceptLanguage";
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => "", // allow gzip/deflate
        CURLOPT_SSL_VERIFYPEER => false, // local dev convenience
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ["ok" => ($code>=200 && $code<400) && $body!==false, "code"=>$code, "body"=>$body, "contentType"=>$ct, "error"=>$err];
}

// very small robots.txt check (allow by default if fetch fails)
function fu_robots_allow($url) {
    $base = fu_host($url);
    $robots = rtrim($base,'/')."/robots.txt";
    $r = fu_http_get($robots, "text/plain,*/*");
    if (!$r["ok"]) return true;
    $ua = strtolower(fu_user_agent());
    $lines = preg_split("/\r?\n/", $r["body"]);
    $applies = false; $disallows = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === "" || $ln[0]==="#") continue;
        if (stripos($ln, "User-agent:") === 0) {
            $uaRule = trim(substr($ln, 11));
            $applies = ($uaRule === "*" || stripos($ua, $uaRule)!==false);
        } else if ($applies && stripos($ln, "Disallow:") === 0) {
            $path = trim(substr($ln, 9));
            if ($path !== "") $disallows[] = $path;
        }
    }
    $path = parse_url($url, PHP_URL_PATH) ?? "/";
    foreach ($disallows as $d) {
        if ($d === "/") return false;
        if (strpos($path, $d) === 0) return false;
    }
    return true;
}

function fu_sleep_jitter_ms($minMs=350, $maxMs=1100) {
    usleep(rand($minMs, $maxMs)*1000);
}

// --- Parsing helpers ---

function fu_try_json($text) {
    $j = json_decode($text, true);
    return is_array($j) ? $j : null;
}

function fu_parse_xml($text) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($text);
    if ($xml === false) return null;
    return $xml;
}

// JSON Feed: https://jsonfeed.org/version/1
function fu_parse_json_feed($json, $limit=5) {
    $out = [];
    if (!isset($json["items"])) return $out;
    foreach ($json["items"] as $it) {
        if (count($out) >= $limit) break;
        if (!isset($it["url"]) && !isset($it["external_url"])) continue;
        $out[] = [
            "title" => $it["title"] ?? "(untitled)",
            "url"   => $it["url"] ?? $it["external_url"],
            "date"  => $it["date_published"] ?? ($it["date_modified"] ?? null),
            "summary" => $it["summary"] ?? null
        ];
    }
    return $out;
}

// RSS/Atom
function fu_parse_rss_atom($xmlObj, $limit=5) {
    $out = [];
    if (isset($xmlObj->channel->item)) { // RSS
        foreach ($xmlObj->channel->item as $item) {
            if (count($out) >= $limit) break;
            $out[] = [
                "title" => (string)$item->title,
                "url"   => (string)$item->link,
                "date"  => (string)$item->pubDate,
                "summary" => (string)$item->description
            ];
        }
    } else if (isset($xmlObj->entry)) { // Atom
        foreach ($xmlObj->entry as $e) {
            if (count($out) >= $limit) break;
            $link = null;
            if (isset($e->link)) {
                foreach ($e->link as $ln) {
                    $attrs = $ln->attributes();
                    if (!$attrs) continue;
                    if (!isset($attrs['rel']) || (string)$attrs['rel']==='alternate') {
                        $link = (string)$attrs['href'];
                        break;
                    }
                }
            }
            $out[] = [
                "title" => (string)$e->title,
                "url"   => $link,
                "date"  => (string)$e->updated,
                "summary" => (string)$e->summary
            ];
        }
    }
    return $out;
}

function fu_abs($base, $rel) {
    if (!$rel) return $rel;
    if (parse_url($rel, PHP_URL_SCHEME)) return $rel;
    if (substr($rel,0,2) === "//") {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: "https";
        return $scheme . ":" . $rel;
    }
    $p = parse_url($base);
    $root = $p['scheme'].'://'.$p['host'] . (isset($p['port'])?':'.$p['port']:'');
    if (substr($rel,0,1) === "/") return $root.$rel;
    $dir = isset($p['path']) ? preg_replace("#/[^/]*$#","/", $p['path']) : "/";
    return $root.$dir.$rel;
}

function fu_html_extract_list($html, $baseUrl, $rule, $limit=5) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$doc->loadHTML($html)) return [];
    $xp = new DOMXPath($doc);
    // Rule: { "item": "//ul/li/article|//div[contains(@class,'post')]", "title": ".//a", "href": ".//a/@href", "date": ".//time/@datetime" }
    $items = $xp->query($rule["item"] ?? "//article|//li");
    $out = [];
    foreach ($items as $node) {
        if (count($out) >= $limit) break;
        $title = null; $href=null; $date=null;
        if (!empty($rule["title"])) {
            $n = $xp->query($rule["title"], $node);
            if ($n && $n->length) $title = trim($n->item(0)->textContent);
        } else $title = trim($node->textContent);
        if (!empty($rule["href"])) {
            $n = $xp->query($rule["href"], $node);
            if ($n && $n->length) $href = $n->item(0)->nodeValue;
        }
        if (!empty($rule["date"])) {
            $n = $xp->query($rule["date"], $node);
            if ($n && $n->length) $date = $n->item(0)->nodeValue;
        }
        if ($href) $href = fu_abs($baseUrl, $href);
        if ($title && $href) $out[] = ["title"=>$title, "url"=>$href, "date"=>$date];
    }
    return $out;
}

function fu_pick_search_url($base, $rules, $keywords) {
    // rule.searchTemplates: ["{base}/?s={q}", "{base}/search?q={q}"]
    if (!empty($rules["searchTemplates"])) {
        $tpl = $rules["searchTemplates"][0];
        $q = urlencode(implode(" ", $keywords));
        $b = rtrim($base,'/');
        return str_replace(["{base}","{q}"], [$b,$q], $tpl);
    }
    // generic guesses
    $q = urlencode(implode(" ", $keywords));
    $b = rtrim($base,'/');
    $guesses = [ "$b/?s=$q", "$b/search?q=$q", "$b/?q=$q" ];
    return $guesses[0];
}
