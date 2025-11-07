<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use RuntimeException;

function out($s){ echo $s, PHP_EOL; }

try {
  if (!class_exists('Imagick')) throw new RuntimeException('Imagick not available.');
  $psd = $_GET['psd'] ?? '';
  if ($psd === '') throw new RuntimeException('Pass ?psd=/absolute/path/to/your.psd');

  if (!is_file($psd)) throw new RuntimeException("File not found: $psd");

  $im = new Imagick();
  if (!$im->readImage($psd)) throw new RuntimeException('readImage failed');
  $im = $im->coalesceImages();

  $im->setIteratorIndex(0);
  $W = $im->getImageWidth();
  $H = $im->getImageHeight();
  out("PSD: $psd");
  out("Canvas: {$W}x{$H}");
  out(str_repeat('-', 60));

  $idx = 0;
  foreach ($im as $layer) {
    $idx++;
    $label = (string)$layer->getImageProperty('label');
    $page  = $layer->getImagePage(); // [width,height,x,y]
    $lw    = $layer->getImageWidth();
    $lh    = $layer->getImageHeight();

    // Try to measure alpha content
    $alpha = $layer->separateImageChannel(Imagick::CHANNEL_ALPHA);
    $stats = $alpha->getImageChannelStatistics();
    // In IM6, stats might be returned under key 2 or 'alpha'; be defensive:
    $alphaStats = $stats[Imagick::CHANNEL_ALPHA] ?? ($stats['alpha'] ?? null);
    $alphaMean  = $alphaStats ? ($alphaStats['mean'] ?? 0) : 0;
    $alpha->destroy();

    out(sprintf(
      "#%02d label='%s' size=%dx%d page=(%d,%d) alphaMean=%.4f",
      $idx, $label, $lw, $lh, (int)($page['x']??0), (int)($page['y']??0), $alphaMean
    ));
  }

  out(str_repeat('-', 60));
  out("NOTE:");
  out("- If alphaMean ~ 0.0000 for your masked layers, IM6 isn't exposing group masks' alpha.");
  out("- In that case, export PNG masks from PS (manual path), or we can try a different extraction method.");
} catch (Throwable $e) {
  out("ERROR: " . $e->getMessage());
}
