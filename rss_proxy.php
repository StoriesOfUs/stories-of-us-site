<?php
// website_2025/rss_proxy.php — proxy with allowlist + optional localhost dev-bypass

header('Content-Type: application/json; charset=utf-8');
function jerr($code,$msg){ echo json_encode(['ok'=>false,'status'=>$code,'error'=>$msg], JSON_UNESCAPED_SLASHES); exit; }

$url = isset($_GET['url']) ? trim($_GET['url']) : '';
if ($url==='') jerr(400,'Missing url');

$u = @parse_url($url);
if(!$u || empty($u['scheme']) || empty($u['host'])) jerr(400,'Bad url');
$host = strtolower($u['host']);

// ---- DEV BYPASS (localhost only) ------------------------------------------
$dev = isset($_GET['dev']) && $_GET['dev']==='1';
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$localhost = in_array($remote, ['127.0.0.1','::1','0.0.0.0'], true);
// ---------------------------------------------------------------------------

// Core allowlist (regions + infra)
$allowed_hosts = [
  // North America
  'rss.nytimes.com','feeds.npr.org','rssfeeds.usatoday.com','theglobeandmail.com','www.theglobeandmail.com',
  // Latin America
  'batimes.com.ar','riotimesonline.com','www.riotimesonline.com','mexiconewsdaily.com',
  // Western Europe
  'feeds.bbci.co.uk','bbc.co.uk','www.bbc.co.uk','rss.dw.com','dw.com','www.france24.com','france24.com','apnews.com','www.apnews.com',
  // Eastern Europe
  'www.kyivpost.com','kyivpost.com','www.ukrinform.net','ukrinform.net','balkaninsight.com','www.balkaninsight.com',
  // Middle East
  'www.aljazeera.com','aljazeera.com','www.thenationalnews.com','thenationalnews.com','www.timesofisrael.com','timesofisrael.com',
  // Africa
  'allafrica.com','www.allafrica.com','mg.co.za','www.mg.co.za','www.standardmedia.co.ke','standardmedia.co.ke',
  'internewske.org','www.internewske.org','www.africanews.com','africanews.com',
  // East Asia
  'www.japantimes.co.jp','japantimes.co.jp','www.koreaherald.com','koreaherald.com','www.scmp.com','scmp.com',
  // South Asia
  'www.thehindu.com','thehindu.com','www.dawn.com','dawn.com',
  // Southeast Asia
  'www.bangkokpost.com','bangkokpost.com','www.channelnewsasia.com','channelnewsasia.com',
  'www.asiasentinel.com','asiasentinel.com','www.khmertimeskh.com','khmertimeskh.com',
  // Central Asia
  'astanatimes.com','www.kt.kz','kt.kz',
  // Oceania
  'www.abc.net.au','abc.net.au','www.rnz.co.nz','rnz.co.nz','www.theguardian.com','theguardian.com',
  // Global
  'news.un.org','www.un.org','news.google.com','www.google.com',
  // Infrastructure commonly used by publishers
  'feeds.feedburner.com','www.feedburner.com'
];

// Category & new Arts/Access/Tech/History/General hosts
$category_hosts = [
  // Activism / Disability orgs / NGOs
  'www.amnesty.org','amnesty.org','www.amnestyusa.org','amnestyusa.org',
  'grassrootsonline.org','www.grassrootsonline.org',
  'fundhumanrights.org','www.fundhumanrights.org',
  'www.globaljustice.org.uk','globaljustice.org.uk',
  'www.lwv.org','lwv.org','www.globalcitizen.org','globalcitizen.org',
  'www.equaltimes.org','equaltimes.org','dralegal.org','www.dralegal.org',
  'www.chrc-ccdp.gc.ca','chrc-ccdp.gc.ca','pwd.org.au','www.pwd.org.au',
  'www.driadvocacy.org','driadvocacy.org','wid.org','www.wid.org',
  'www.aapd.com','aapd.com','inclusion-international.org','www.inclusion-international.org',
  'www.acb.org','acb.org','enil.eu','www.enil.eu','wbu.ngo','www.wbu.ngo',
  'www.iddcconsortium.net','iddcconsortium.net',

  // Arts (beefed up)
  'disabilityarts.online','www.disabilityarts.online',
  'hyperallergic.com','www.hyperallergic.com',
  'news.artnet.com','www.artnet.com','artnet.com',
  'www.artnews.com','artnews.com',
  'www.apollo-magazine.com','apollo-magazine.com',
  'www.creativereview.co.uk','creativereview.co.uk',
  'www.designweek.co.uk','designweek.co.uk',
  'www.artshub.com.au','artshub.com.au',
  'www.smithsonianmag.com','smithsonianmag.com',
  'www.theartnewspaper.com','theartnewspaper.com',
  'www.europebeyondaccess.com','europebeyondaccess.com',
  'www.britishcouncil.org','britishcouncil.org','arts.britishcouncil.org',
  'www.a-n.co.uk','a-n.co.uk','weareunlimited.org.uk','www.weareunlimited.org.uk',
  'on-the-move.org','www.on-the-move.org','www.frieze.com','frieze.com',

  // Access / Architecture / Design
  'www.archdaily.com','archdaily.com',
  'www.dezeen.com','dezeen.com',
  'metropolismag.com','www.metropolismag.com',
  'archinect.com','www.archinect.com',
  'www.architectmagazine.com','architectmagazine.com',
  'www.riba.org','riba.org','www.architecture.com','architecture.com',
  'www.bdcnetwork.com','bdcnetwork.com',
  'www.architectsjournal.co.uk','architectsjournal.co.uk',
  'www.archpaper.com','archpaper.com',
  'www.smartcitieslibrary.com','smartcitieslibrary.com',
  'www.internationaldisabilityalliance.org','internationaldisabilityalliance.org',
  'www.ifhp.org','ifhp.org','www.jacces.org','jacces.org',
  'universaldesign.ie','www.universaldesign.ie',
  'www.aia.org','aia.org','www.iccsafe.org','iccsafe.org',
  'www.designboom.com','designboom.com',
  'www.architecturaldigest.com','architecturaldigest.com',

  // Tech
  'www.techradar.com','techradar.com','tech.eu','www.tech.eu',
  'mashable.com','www.mashable.com','tech.co','www.tech.co',
  'www.techmeme.com','techmeme.com','machinelearning.apple.com',
  'hai.stanford.edu','www.hai.stanford.edu','www.theverge.com','theverge.com',
  'www.wired.com','wired.com','venturebeat.com','www.venturebeat.com',
  'spectrum.ieee.org','www.spectrum.ieee.org','www.bensbites.com','bensbites.com',
  'www.therundown.ai','therundown.ai','news.ycombinator.com',

  // History / Museums / Libraries
  'www.historytoday.com','historytoday.com','onlinelibrary.wiley.com',
  'historyandpolicy.org','www.historyandpolicy.org','phm.org.uk','www.phm.org.uk',
  'www.historyworkshop.org.uk','historyworkshop.org.uk','www.history.ac.uk','history.ac.uk',
  'www.balh.org.uk','balh.org.uk','reviews.history.ac.uk',
  'www.liverpoolmuseums.org.uk','liverpoolmuseums.org.uk','www.nationalarchives.gov.uk','nationalarchives.gov.uk',
  'www.publiclibrariesnews.com','publiclibrariesnews.com','americanlibrariesmagazine.org','www.americanlibrariesmagazine.org',
  'www.deutschlandmuseum.de','deutschlandmuseum.de','www.museumsassociation.org','museumsassociation.org',
  'icom.museum','www.icom.museum','media.nms.ac.uk','www.nms.ac.uk','tepapa.govt.nz','www.tepapa.govt.nz',
  'archive.org','blog.archive.org','www.archivesportaleurope.net','archivesportaleurope.net',
  'www.ica.org','ica.org','www.unesco.org','unesco.org','womenshistorynetwork.org','www.womenshistorynetwork.org',
  'dishist.org','www.dishist.org',

  // General disability orgs/news
  'www.disabilityinnovation.com','disabilityinnovation.com',
  'www.humanity-inclusion.org.uk','humanity-inclusion.org.uk',
  'www.edf-feph.org','edf-feph.org','cbm-global.org','www.cbm-global.org',
  'globaldisabilityfund.org','www.globaldisabilityfund.org','www.disabilityscoop.com','disabilityscoop.com',
  'add.org.uk','www.add.org.uk'
];

// merge
$allowed_hosts = array_values(array_unique(array_merge($allowed_hosts,$category_hosts)));

// Allow when host is on allowlist OR (localhost + dev=1)
$allowed = in_array($host,$allowed_hosts,true) ||
           in_array(preg_replace('/^www\./','',$host),$allowed_hosts,true) ||
           in_array('www.'.$host,$allowed_hosts,true) ||
           ($localhost && $dev);

if(!$allowed){
  jerr(403,'Host not allowed: '.$host);
}

// Referer hints (some publishers expect a site referer)
$referer = isset($_GET['referer']) ? trim($_GET['referer']) : '';
if($referer===''){
  if(strpos($host,'theguardian.com')!==false) $referer='https://www.theguardian.com/';
  if(strpos($host,'scmp.com')!==false)        $referer='https://www.scmp.com/';
  if(strpos($host,'rnz.co.nz')!==false)       $referer='https://www.rnz.co.nz/';
  if(strpos($host,'theglobeandmail.com')!==false) $referer='https://www.theglobeandmail.com/';
  if(strpos($host,'france24.com')!==false)    $referer='https://www.france24.com/en/';
  if(strpos($host,'a-n.co.uk')!==false)       $referer='https://www.a-n.co.uk/';
}

$ua = ($_GET['ua'] ?? '') ?: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15';
$accept = 'text/xml,application/rss+xml,application/atom+xml,application/xml;q=0.9,*/*;q=0.8';

$ch = curl_init();
curl_setopt_array($ch,[
  CURLOPT_URL=>$url,
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_FOLLOWLOCATION=>true,
  CURLOPT_MAXREDIRS=>5,
  CURLOPT_CONNECTTIMEOUT=>10,
  CURLOPT_TIMEOUT=>20,
  CURLOPT_ENCODING=>'',
  CURLOPT_USERAGENT=>$ua,
  CURLOPT_HTTPHEADER=>array_filter([
    'Accept: '.$accept,
    'Accept-Language: en-GB,en;q=0.9',
    $referer ? 'Referer: '.$referer : null,
  ]),
  CURLOPT_SSL_VERIFYPEER=>true,
  CURLOPT_SSL_VERIFYHOST=>2,
]);

$body = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if($body===false) jerr(0,'cURL error: '.$err);
if($code>=400)    jerr($code,'HTTP '.$code);

// Try to parse a first title (RSS/Atom)
$firstTitle=null;
libxml_use_internal_errors(true);
$xml=@simplexml_load_string($body);
if($xml!==false){
  if(isset($xml->channel->item[0]->title)) $firstTitle=trim((string)$xml->channel->item[0]->title);
  elseif(isset($xml->entry[0]->title))     $firstTitle=trim((string)$xml->entry[0]->title);
}

echo json_encode(['ok'=>true,'status'=>$code,'first_title'=>$firstTitle,'bytes'=>strlen($body)], JSON_UNESCAPED_SLASHES);
