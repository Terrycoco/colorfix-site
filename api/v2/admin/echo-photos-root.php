<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo "DOCUMENT_ROOT = " . ($_SERVER['DOCUMENT_ROOT'] ?? '(unset)') . "\n";
echo "PHOTOS_ROOT   = " . (($_SERVER['DOCUMENT_ROOT'] ?? '') . "/colorfix/photos") . "\n";
$dir = (($_SERVER['DOCUMENT_ROOT'] ?? '') . "/colorfix/photos");
echo "Exists? " . (is_dir($dir) ? "yes" : "no") . "\n";
echo "Writable? " . (is_writable($dir) ? "yes" : "no") . "\n";
