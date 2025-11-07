<?php
header('Content-Type: text/plain; charset=utf-8');
$here = __DIR__;
$cmd  = 'php -l ' . escapeshellarg($here . '/browse-palettes.php') . ' 2>&1';
echo "Running: $cmd\n\n";
$out = shell_exec($cmd);
echo $out ?: "No output.\n";
