<?php
// Opens the unified diagnostic WITHOUT auto-fetch so you can pick a plan first.
$params = http_build_query([
  'auto' => 'latest', // just loads the latest list; does not fetch
  'cb'   => time(),   // cache buster
]);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: diagnostic_unified.html?' . $params);
exit;
