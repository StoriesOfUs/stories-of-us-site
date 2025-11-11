<?php
// Opens the unified diagnostic and auto-runs "Check all plans"
$params = http_build_query([
  'check' => 'all',
  'cb'    => time()
]);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Location: diagnostic_unified.html?' . $params);
exit;
