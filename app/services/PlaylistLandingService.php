<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPlaylistRepository;

final class PlaylistLandingService
{
    public function __construct(
        private PdoPlaylistRepository $playlistRepo
    ) {}

    public function generateSlug(string $value, ?int $excludePlaylistId = null): string
    {
        $slug = $this->slugify($value);
        return $this->playlistRepo->generateUniqueSlug($slug, $excludePlaylistId);
    }

    public function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'playlist';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function buildLandingViewModel(array $row, string $origin): array
    {
        $headline = $this->firstNonEmpty([
            $row['headline'] ?? null,
            $row['title'] ?? null,
        ]);

        $pageTitle = $this->firstNonEmpty([
            $row['page_title'] ?? null,
            $headline,
        ]);

        $dek = trim((string)($row['dek'] ?? ''));
        $introHtml = trim((string)($row['intro_html'] ?? ''));
        $bodyHtml = trim((string)($row['body_html'] ?? ''));
        $metaDescription = $this->firstNonEmpty([
            $row['meta_description'] ?? null,
            $this->truncatePlainText($dek !== '' ? $dek : $introHtml, 160),
        ]);

        $slug = (string)($row['slug'] ?? '');
        $canonicalUrl = rtrim($origin, '/') . '/playlists/' . rawurlencode($slug);
        $heroImage = $this->normalizeImageUrl((string)($row['hero_image_url'] ?? ''), $origin);
        $heroAlt = $this->firstNonEmpty([
            $row['hero_alt'] ?? null,
            $headline,
        ]);

        $watchInstanceId = (int)($row['watch_playlist_instance_id'] ?? 0);
        $playlistId = (int)($row['playlist_id'] ?? 0);
        $watchUrl = $watchInstanceId > 0 && $playlistId > 0
            ? rtrim($origin, '/') . '/playlist/share/id=' . $playlistId
            : '';

        return [
            ...$row,
            'headline_final' => $headline,
            'page_title_final' => $pageTitle,
            'meta_description_final' => $metaDescription,
            'dek_final' => $dek,
            'intro_html_final' => $introHtml,
            'body_html_final' => $bodyHtml,
            'hero_image_final' => $heroImage,
            'hero_alt_final' => $heroAlt,
            'canonical_url' => $canonicalUrl,
            'watch_url' => $watchUrl,
        ];
    }

    public function truncatePlainText(string $htmlOrText, int $maxChars = 160): string
    {
        $plain = trim(html_entity_decode(strip_tags($htmlOrText), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '') {
            return '';
        }
        if (mb_strlen($plain) <= $maxChars) {
            return $plain;
        }
        return rtrim(mb_substr($plain, 0, $maxChars - 1)) . '…';
    }

    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private function normalizeImageUrl(string $url, string $origin): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $trimmed)) {
            return $trimmed;
        }
        return rtrim($origin, '/') . '/' . ltrim($trimmed, '/');
    }
}
