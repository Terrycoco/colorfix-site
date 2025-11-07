<?php
header('Content-Type: text/plain; charset=utf-8');

$paths = [
  sys_get_temp_dir() . '/colorfix-login.log',
  __DIR__ . '/logs/login.log',
];

$results = [];
foreach ($paths as $p) {
  $dir = dirname($p);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $ok = @file_put_contents($p, date('c') . " WRITE TEST\n", FILE_APPEND);
  $results[] = [$p, $ok !== false ? 'OK' : 'FAIL'];
}

echo "sys_get_temp_dir(): " . sys_get_temp_dir() . PHP_EOL;
echo "open_basedir: " . (ini_get('open_basedir') ?: '(none)') . PHP_EOL . PHP_EOL;

foreach ($results as [$p, $status]) {
  echo $status . "  " . $p . PHP_EOL;
}
