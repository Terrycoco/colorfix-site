<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

echo "extension_loaded(imagick): " . (extension_loaded('imagick') ? "yes" : "no") . PHP_EOL;
echo "class_exists(Imagick): " . (class_exists('Imagick') ? "yes" : "no") . PHP_EOL;

if (class_exists('Imagick')) {
  try {
    $v = \Imagick::getVersion();
    echo "Imagick version: " . ($v['versionString'] ?? 'unknown') . PHP_EOL;
  } catch (Throwable $e) {
    echo "Imagick present but errored: " . $e->getMessage() . PHP_EOL;
  }
} else {
  echo "Tip: enable the Imagick PHP extension in cPanel (Search for “Extensions” or “Select PHP Version”), "
     . "check imagick for PHP 8.2, then reload this page.\n";
}
