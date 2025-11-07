<?php
header('Content-Type: text/plain; charset=utf-8');

function has_bom($file) {
  $h = @fopen($file, 'rb'); if (!$h) return null;
  $bin = fread($h, 3); fclose($h);
  return bin2hex($bin) === 'efbbbf'; // UTF-8 BOM
}

$dir = __DIR__;
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($rii as $f) {
  if (!$f->isFile()) continue;
  if (substr($f->getFilename(), -4) !== '.php') continue;
  $bom = has_bom($f->getPathname());
  if ($bom === null) { echo "SKIP  {$f->getPathname()} (unreadable)\n"; continue; }
  echo ($bom ? 'BOM  ' : 'OK   ') . $f->getPathname() . "\n";
}
