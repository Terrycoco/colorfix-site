<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/services/PhotoCompressionService.php';

use App\Services\PhotoCompressionService;

function usage(): void
{
    echo "Usage: php scripts/compress_photos.php [--root=PATH] [--min-mb=MB] [--quality=Q] [--png-level=LEVEL] [--max-dim=PX] [--strip] [--dry-run] [--full]\n";
    echo "Defaults: --root=../photos --min-mb=1 --quality=92 --png-level=9 (no strip, no resize, saved-palettes only)\n";
}

$opts = [
    'root' => null,
    'min_mb' => 1,
    'quality' => 92,
    'png_level' => 9,
    'max_dim' => 0,
    'dry_run' => false,
    'strip' => false,
    'skip_scope' => false,
];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $opts['root'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--min-mb=')) {
        $opts['min_mb'] = (float)substr($arg, 9);
    } elseif (str_starts_with($arg, '--quality=')) {
        $opts['quality'] = (int)substr($arg, 10);
    } elseif (str_starts_with($arg, '--png-level=')) {
        $opts['png_level'] = (int)substr($arg, 12);
    } elseif (str_starts_with($arg, '--max-dim=')) {
        $opts['max_dim'] = (int)substr($arg, 10);
    } elseif ($arg === '--dry-run') {
        $opts['dry_run'] = true;
    } elseif ($arg === '--strip') {
        $opts['strip'] = true;
    } elseif ($arg === '--full') {
        $opts['skip_scope'] = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        usage();
        exit(0);
    }
}

$root = $opts['root'] ?: realpath(__DIR__ . '/../photos');
if (!$root || !is_dir($root)) {
    fwrite(STDERR, "Invalid photos root. Use --root=PATH\n");
    exit(1);
}

$minBytes = (int)round(max(0, $opts['min_mb']) * 1024 * 1024);

$svc = new PhotoCompressionService();

try {
    $result = $svc->compressTree($root, [
        'quality' => $opts['quality'],
        'min_bytes' => $minBytes,
        'png_level' => $opts['png_level'],
        'max_dim' => $opts['max_dim'],
        'dry_run' => $opts['dry_run'],
        'strip' => $opts['strip'],
        'skip_scope' => $opts['skip_scope'],
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Root: {$result['root']}\n";
echo "Min bytes: {$result['min_bytes']}\n";
echo "JPEG quality: {$result['quality']}\n";
echo "PNG level: {$result['png_level']}\n";
echo "Max dimension: {$result['max_dim']}\n";
echo "Strip metadata: " . (!empty($result['strip']) ? 'yes' : 'no') . "\n";
echo "Dry run: " . ($result['dry_run'] ? 'yes' : 'no') . "\n";
echo "JPEG compressed: {$result['jpeg_count']}\n";
echo "PNG compressed: {$result['png_count']}\n";
echo "Skipped (below threshold): {$result['skipped']}\n";
