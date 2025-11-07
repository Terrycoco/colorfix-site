<?php
declare(strict_types=1);

// Base dir of the project (api/ lives one level below)
$BASE = dirname(__DIR__);

// Explicit PSR-4 root + lowercase subdir maps
$prefixes = [
  'App\\'         => $BASE . '/app',        // generic
  'App\\Lib\\'    => $BASE . '/app/lib',    // lowercase dirs
  'App\\Repos\\'  => $BASE . '/app/repos',
  'App\\Services\\' => $BASE . '/app/services',
  'App\\Entities\\' => $BASE . '/app/entities',
  'App\\Controllers\\' => $BASE . '/app/controllers',
];

spl_autoload_register(function ($class) use ($prefixes) {
  foreach ($prefixes as $prefix => $dir) {
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) continue;

    $relative = substr($class, $len);                 // e.g. 'Logger' or 'PdoColorRepository'
    $relPath  = str_replace('\\', '/', $relative);

    // 1) Try exact-case under mapped dir
    $file = $dir . '/' . $relPath . '.php';
    if (is_file($file)) { require $file; return true; }

    // 2) Fallback: lowercase the first path segment (handles lib/repos/services case mismatch)
    $parts = explode('/', $relPath, 2); // ['Logger'] or ['PdoColorRepository'] or ['Sub', 'Thing.php']
    if (!empty($parts[0])) {
      $file2 = $dir . '/' . strtolower($parts[0]) . (isset($parts[1]) ? '/' . $parts[1] : '') . '.php';
      if (is_file($file2)) { require $file2; return true; }
    }
  }
  return false;
});
