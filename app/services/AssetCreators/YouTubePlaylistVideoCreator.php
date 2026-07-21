<?php
declare(strict_types=1);

namespace App\Services\AssetCreators;

use RuntimeException;

final class YouTubePlaylistVideoCreator
{
    private const FPS = 30;
    private const WIDTH = 1920;
    private const HEIGHT = 1080;
    private const DEFAULT_SLIDE_MS = 4000;
    private const DEFAULT_INTRO_MS = 3500;
    private const DEFAULT_TEXT_MS = 3500;
    private const DEFAULT_HUE_WHEEL_MS = 5000;
    private const DEFAULT_BRAND_BUMPER_MS = 4200;
    private const DISSOLVE_MS = 550;
    private const CUT_MS = 0;
    private const CAPTION_DELAY_MS = 700;
    private const CAPTION_FADE_MS = 450;
    private const SIGNATURE_REVEAL_DELAY_MS = 760;
    private const SIGNATURE_REVEAL_DURATION_MS = 1750;
    private const FINAL_FADE_MS = 700;

    public function __construct(
        private string $rootDir,
        private string $baseUrl = ''
    ) {}

    public function preview(array $payload): array
    {
        $recipe = $this->recipeFromPayload($payload);
        $plan = $this->planForRecipe($recipe);
        if (empty($plan['items'])) {
            throw new RuntimeException('YouTube preview needs at least one included slide.');
        }

        $projectRoot = $this->projectRoot();
        $previewDir = $projectRoot . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'youtube-preview' . DIRECTORY_SEPARATOR . 'current';
        $this->preparePreviewDir($previewDir);

        $recipePath = $previewDir . DIRECTORY_SEPARATOR . 'recipe.json';
        $outputPath = $previewDir . DIRECTORY_SEPARATOR . 'preview.mp4';
        file_put_contents($recipePath, json_encode(['plan' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'render-youtube-video.mjs';
        if (!is_file($scriptPath)) {
            throw new RuntimeException('YouTube render script is missing.');
        }
        if (!$this->commandExists('node')) {
            throw new RuntimeException('YouTube preview requires Node/Remotion locally. Run this preview from the local app, not the Bluehost server.');
        }

        $this->runCommand([
            'node',
            $scriptPath,
            '--recipe=' . $recipePath,
            '--output=' . $outputPath,
            '--preview=1',
        ], $projectRoot);

        if (!is_file($outputPath)) {
            throw new RuntimeException('YouTube preview renderer finished but no MP4 was found.');
        }

        return [
            'kind' => 'video',
            'preview_type' => 'local_video',
            'channel' => 'youtube',
            'title' => (string)($plan['title'] ?? 'YouTube Preview'),
            'local_path' => $outputPath,
            'recipe_path' => $recipePath,
            'open_url' => 'file://' . $outputPath,
            'file_size_bytes' => filesize($outputPath) ?: null,
            'duration_seconds' => $this->durationSeconds($plan),
            'slide_count' => count($plan['items']),
            'persisted' => false,
            'creates_asset_library_row' => false,
            'cleanup_policy' => 'replace_previous_preview',
        ];
    }

    private function recipeFromPayload(array $payload): array
    {
        $recipe = $payload['recipe'] ?? $payload['instructions'] ?? null;
        if (is_string($recipe)) {
            $decoded = json_decode($recipe, true);
            $recipe = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($recipe)) {
            throw new RuntimeException('Preview recipe required.');
        }
        return $recipe;
    }

    public function planForRecipe(array $recipe): array
    {
        $rows = is_array($recipe['video_rows'] ?? null)
            ? $recipe['video_rows']
            : (is_array($recipe['pairs'] ?? null) ? $recipe['pairs'] : []);
        $row = null;
        foreach ($rows as $candidate) {
            if (is_array($candidate) && ($candidate['include'] ?? true) !== false) {
                $row = $candidate;
                break;
            }
        }
        if (!is_array($row)) {
            throw new RuntimeException('YouTube recipe has no included video row.');
        }

        $slides = is_array($row['slides'] ?? null) ? $row['slides'] : [];
        $items = [];
        foreach ($slides as $index => $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $asset = is_array($slide['asset'] ?? null) ? $slide['asset'] : [];
            $itemType = (string)($slide['item_type'] ?? $slide['type'] ?? 'normal');
            $imageUrl = $this->absoluteUrl(
                (string)($asset['public_url'] ?? $slide['image_url'] ?? $slide['public_url'] ?? '')
            );
            if ($imageUrl === '' && strtolower(trim($itemType)) !== 'brand-bumper') {
                continue;
            }
            $items[] = [
                'playlist_item_id' => isset($slide['playlist_item_id']) ? (int)$slide['playlist_item_id'] : null,
                'type' => $itemType,
                'item_type' => $itemType,
                'title' => (string)($slide['title'] ?? $asset['title'] ?? ''),
                'subtitle' => (string)($slide['subtitle'] ?? ''),
                'body' => (string)($slide['body'] ?? ''),
                'image_url' => $imageUrl,
                'duration_ms' => isset($slide['duration_ms']) ? (int)$slide['duration_ms'] : null,
                'sort_order' => $index + 1,
            ];
        }

        $title = trim((string)($row['search_title'] ?? $row['title'] ?? $recipe['source']['title'] ?? 'ColorFix YouTube Preview'));
        $music = $this->musicFromRecipe($recipe);

        return [
            'playlist_id' => isset($recipe['source']['playlist_id']) ? (int)$recipe['source']['playlist_id'] : null,
            'title' => $title !== '' ? $title : 'ColorFix YouTube Preview',
            'type' => 'youtube_preview',
            'total_items' => count($items),
            'items' => $items,
            'music' => $music,
            'video' => [
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'fps' => self::FPS,
                'default_slide_duration_ms' => self::DEFAULT_SLIDE_MS,
                'default_intro_duration_ms' => self::DEFAULT_INTRO_MS,
                'default_text_duration_ms' => self::DEFAULT_TEXT_MS,
                'default_hue_wheel_duration_ms' => self::DEFAULT_HUE_WHEEL_MS,
                'default_brand_bumper_duration_ms' => self::DEFAULT_BRAND_BUMPER_MS,
                'dissolve_ms' => self::DISSOLVE_MS,
                'cut_ms' => self::CUT_MS,
                'caption_delay_after_photo_ms' => self::CAPTION_DELAY_MS,
                'caption_fade_ms' => self::CAPTION_FADE_MS,
                'signature_reveal_delay_ms' => self::SIGNATURE_REVEAL_DELAY_MS,
                'signature_reveal_duration_ms' => self::SIGNATURE_REVEAL_DURATION_MS,
                'final_fade_ms' => self::FINAL_FADE_MS,
                'timeline' => $this->buildTimeline($items),
            ],
        ];
    }

    private function musicFromRecipe(array $recipe): ?array
    {
        $music = is_array($recipe['music'] ?? null) ? $recipe['music'] : [];
        if (!$music && is_array($recipe['source']['music'] ?? null)) {
            $music = $recipe['source']['music'];
        }
        $src = $this->absoluteUrl((string)($music['public_url'] ?? $music['src'] ?? $music['rel_path'] ?? ''));
        if ($src === '') {
            return null;
        }

        $volume = (float)($music['volume'] ?? 0.35);
        if ($volume < 0) {
            $volume = 0;
        } elseif ($volume > 1) {
            $volume = 1;
        }

        return [
            'asset_library_id' => isset($music['asset_library_id']) ? (int)$music['asset_library_id'] : null,
            'title' => (string)($music['title'] ?? ''),
            'src' => $src,
            'public_url' => $src,
            'rel_path' => (string)($music['rel_path'] ?? ''),
            'mime_type' => (string)($music['mime_type'] ?? ''),
            'volume' => $volume,
        ];
    }

    private function buildTimeline(array $items): array
    {
        $cursor = 0;
        $timeline = [];
        foreach ($items as $index => $item) {
            $duration = $this->slideDuration($item);
            $transition = strtolower(trim((string)($item['transition'] ?? 'animation'))) === 'cut' ? 'cut' : 'dissolve';
            $timeline[] = [
                'index' => $index,
                'start_ms' => $cursor,
                'duration_ms' => $duration,
                'end_ms' => $cursor + $duration,
                'transition' => $transition,
                'transition_ms' => $transition === 'cut' ? self::CUT_MS : self::DISSOLVE_MS,
            ];
            $cursor += $duration;
        }
        return $timeline;
    }

    private function slideDuration(array $item): int
    {
        $type = strtolower(trim((string)($item['type'] ?? $item['item_type'] ?? 'normal')));
        $explicit = (int)($item['duration_ms'] ?? 0);
        if ($type === 'brand-bumper') {
            return max(self::DEFAULT_BRAND_BUMPER_MS, $explicit);
        }
        if ($explicit > 0) {
            return $explicit;
        }
        return match ($type) {
            'intro' => self::DEFAULT_INTRO_MS,
            'text' => self::DEFAULT_TEXT_MS,
            'hue-wheel' => self::DEFAULT_HUE_WHEEL_MS,
            'brand-bumper' => self::DEFAULT_BRAND_BUMPER_MS,
            default => self::DEFAULT_SLIDE_MS,
        };
    }

    private function durationSeconds(array $plan): float
    {
        $timeline = is_array($plan['video']['timeline'] ?? null) ? $plan['video']['timeline'] : [];
        $end = 0;
        foreach ($timeline as $entry) {
            if (is_array($entry)) {
                $end = max($end, (int)($entry['end_ms'] ?? 0));
            }
        }
        return round($end / 1000, 2);
    }

    private function absoluteUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $value)) {
            return $value;
        }
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            $base = 'https://colorfix.terrymarr.com';
        }
        return $base . '/' . ltrim($value, '/');
    }

    private function preparePreviewDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create YouTube preview folder.');
        }
        foreach (['preview.mp4', 'recipe.json'] as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function projectRoot(): string
    {
        $root = realpath($this->rootDir);
        if ($root && is_dir($root . DIRECTORY_SEPARATOR . 'scripts')) {
            return $root;
        }
        $fallback = realpath(dirname(__DIR__, 3));
        if ($fallback && is_dir($fallback . DIRECTORY_SEPARATOR . 'scripts')) {
            return $fallback;
        }
        return rtrim($this->rootDir, DIRECTORY_SEPARATOR);
    }

    private function commandExists(string $command): bool
    {
        $process = proc_open('command -v ' . escapeshellarg($command), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        $stdout = trim((string)stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 && $stdout !== '';
    }

    private function runCommand(array $command, string $cwd): void
    {
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $process = proc_open($escaped, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start YouTube preview renderer.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $detail = trim((string)($stderr ?: $stdout));
            throw new RuntimeException('YouTube preview renderer failed' . ($detail !== '' ? ': ' . $detail : '.'));
        }
    }
}
