<?php
$root = dirname(__DIR__);
$ok = [
  'articles_fetch.php' => file_exists($root.'/articles_fetch.php'),
  'generate_latest.php' => file_exists(__FILE__ ? __DIR__.'/generate_latest.php' : ''),
  'build_digests.php' => file_exists(__DIR__.'/build_digests.php'),
];
?>
<!doctype html><meta charset="utf-8"><title>Stories Of Us — Tools</title>
<body style="font:16px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:720px;margin:40px auto">
<h1>Stories Of Us — Tools</h1>
<ul>
  <li><a href="/website_2025/tools/generate_latest.php">Generate Latest (all plans)</a></li>
  <li><a href="/website_2025/tools/build_digests.php">Build Digests (daily/weekly/monthly)</a></li>
</ul>
<h2>File checks</h2>
<ul>
  <li>articles_fetch.php: <strong><?= $ok['articles_fetch.php'] ? 'FOUND' : 'MISSING' ?></strong></li>
  <li>tools/generate_latest.php: <strong><?= $ok['generate_latest.php'] ? 'FOUND' : 'MISSING' ?></strong></li>
  <li>tools/build_digests.php: <strong><?= $ok['build_digests.php'] ? 'FOUND' : 'MISSING' ?></strong></li>
</ul>
</body>
