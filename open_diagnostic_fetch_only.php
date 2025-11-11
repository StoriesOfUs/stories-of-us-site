<?php
// Hard-refresh opener: loads diagnostic_unified.html with fetch only.
$params = http_build_query([
  'auto'  => 'latest',
  'fetch' => '1',
  'cb'    => time(),   // cache buster
]);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: diagnostic_unified.html?' . $params);
exit;
