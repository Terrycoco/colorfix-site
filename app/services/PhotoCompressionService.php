<?php
declare(strict_types=1);

namespace App\Services;

final class PhotoCompressionService
{
    public function compressTree(string $root, array $options = []): array
    {
        $root = rtrim($root, '/');
        if ($root === '' || !is_dir($root)) {
            throw new \RuntimeException("Invalid root directory: {$root}");
        }

        $quality = (int)($options['quality'] ?? 92);
        $quality = max(50, min(100, $quality));
        $minBytes = (int)($options['min_bytes'] ?? (1 * 1024 * 1024));
        $minBytes = max(0, $minBytes);
        $pngLevel = (int)($options['png_level'] ?? 9);
        $pngLevel = max(0, min(9, $pngLevel));
        $maxDim = (int)($options['max_dim'] ?? 0);
        $maxDim = max(0, $maxDim);
        $dryRun = !empty($options['dry_run']);
        $strip = !empty($options['strip']);
        $scope = $options['scope'] ?? 'saved-palettes';
        $skipScope = !empty($options['skip_scope']);

        if (!$this->hasBinary('mogrify')) {
            throw new \RuntimeException('mogrify (ImageMagick) not found on PATH');
        }

        $jpegPaths = [];
        $pngPaths = [];
        $skipped = 0;

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $path = $file->getPathname();
            if (!$this->pathAllowed($path, $scope, $skipScope)) continue;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) continue;
            $size = $file->getSize();
            if ($size < $minBytes) {
                $skipped++;
                continue;
            }
            if ($ext === 'png') {
                $pngPaths[] = $path;
            } else {
                $jpegPaths[] = $path;
            }
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'root' => $root,
                'quality' => $quality,
                'png_level' => $pngLevel,
                'max_dim' => $maxDim,
                'min_bytes' => $minBytes,
                'dry_run' => true,
                'jpeg_count' => count($jpegPaths),
                'png_count' => count($pngPaths),
                'skipped' => $skipped,
            ];
        }

        $jpegArgs = ['-auto-orient', '-interlace', 'Plane', '-quality', (string)$quality];
        if ($maxDim > 0) {
            $jpegArgs = array_merge($jpegArgs, ['-resize', "{$maxDim}x{$maxDim}>"]);
        }
        if ($strip) $jpegArgs[] = '-strip';
        $pngArgs = ['-auto-orient', '-define', "png:compression-level={$pngLevel}"];
        if ($maxDim > 0) {
            $pngArgs = array_merge($pngArgs, ['-resize', "{$maxDim}x{$maxDim}>"]);
        }
        if ($strip) $pngArgs[] = '-strip';

        $jpegCompressed = $this->runMogrifyChunks($jpegPaths, $jpegArgs);
        $pngCompressed = $this->runMogrifyChunks($pngPaths, $pngArgs);
        $this->ensurePermissions($jpegPaths);
        $this->ensurePermissions($pngPaths);

        return [
            'ok' => true,
            'root' => $root,
            'quality' => $quality,
            'png_level' => $pngLevel,
            'max_dim' => $maxDim,
            'min_bytes' => $minBytes,
            'strip' => $strip,
            'dry_run' => false,
            'jpeg_count' => $jpegCompressed,
            'png_count' => $pngCompressed,
            'skipped' => $skipped,
        ];
    }

    private function hasBinary(string $bin): bool
    {
        $cmd = 'command -v ' . escapeshellarg($bin) . ' >/dev/null 2>&1';
        $code = 0;
        @exec($cmd, $out, $code);
        return $code === 0;
    }

    private function runMogrifyChunks(array $paths, array $args, int $chunkSize = 50): int
    {
        if (!$paths) return 0;
        $total = 0;
        $chunks = array_chunk($paths, $chunkSize);
        foreach ($chunks as $chunk) {
            $cmd = 'mogrify ' . $this->joinArgs($args) . ' ' . $this->joinArgs($chunk);
            $code = 0;
            @exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException('mogrify failed for batch');
            }
            $total += count($chunk);
        }
        return $total;
    }

    private function joinArgs(array $args): string
    {
        return implode(' ', array_map('escapeshellarg', $args));
    }

    private function ensurePermissions(array $paths): void
    {
        if (empty($paths)) return;
        foreach ($paths as $path) {
            @chmod($path, 0644);
        }
    }

    private function pathAllowed(string $path, string $scope, bool $skipScope): bool
    {
        if ($skipScope) {
            return true;
        }
        if ($scope === 'saved-palettes') {
            return str_contains($path, DIRECTORY_SEPARATOR . 'saved-palettes' . DIRECTORY_SEPARATOR);
        }
        return true;
    }
}
