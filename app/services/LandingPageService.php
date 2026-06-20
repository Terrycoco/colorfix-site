<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoLandingPageRepository;
use RuntimeException;

final class LandingPageService
{
    private const STATUSES = ['draft', 'public', 'archived'];
    private const PAGE_TYPES = ['playlist', 'collection', 'article', 'redirect'];

    public function __construct(
        private PdoLandingPageRepository $repo,
        private string $baseUrl = ''
    ) {}

    public function listPages(array $filters = []): array
    {
        return array_map([$this, 'withComputedUrls'], $this->repo->list($filters));
    }

    public function savePage(array $payload): array
    {
        $data = $this->normalizePayload($payload);
        $id = $this->repo->save($data);
        $page = $this->repo->findById($id);
        if (!$page) {
            throw new RuntimeException('Landing page save failed');
        }
        return $this->withComputedUrls($page);
    }

    public function getPublicPage(string $slug, ?string $src = null): ?array
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') return null;
        $page = $this->repo->findBySlug($slug);
        if (!$page || ($page['status'] ?? '') !== 'public') {
            return null;
        }
        return $this->withComputedUrls($page, $src);
    }

    public function getPageById(int $id): ?array
    {
        $page = $id > 0 ? $this->repo->findById($id) : null;
        return $page ? $this->withComputedUrls($page) : null;
    }

    private function normalizePayload(array $payload): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('title required');
        }

        $slug = $this->normalizeSlug((string)($payload['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->normalizeSlug($title);
        }
        if ($slug === '') {
            throw new RuntimeException('slug required');
        }

        $status = strtolower(trim((string)($payload['status'] ?? 'draft')));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }

        $pageType = strtolower(trim((string)($payload['page_type'] ?? 'playlist')));
        if (!in_array($pageType, self::PAGE_TYPES, true)) {
            $pageType = 'playlist';
        }

        $redirectUrl = $this->nullableString($payload['redirect_url'] ?? null);
        if ($pageType === 'redirect' && !$redirectUrl) {
            throw new RuntimeException('redirect_url required for redirect pages');
        }

        return [
            'id' => (int)($payload['id'] ?? 0),
            'slug' => $slug,
            'title' => $title,
            'search_title' => $this->nullableString($payload['search_title'] ?? null),
            'description' => $this->nullableString($payload['description'] ?? null),
            'status' => $status,
            'page_type' => $pageType,
            'primary_playlist_instance_id' => $this->nullableInt($payload['primary_playlist_instance_id'] ?? null),
            'featured_pin_asset_id' => $this->nullableInt($payload['featured_pin_asset_id'] ?? null),
            'redirect_url' => $redirectUrl,
        ];
    }

    private function withComputedUrls(array $page, ?string $src = null): array
    {
        $slug = (string)($page['slug'] ?? '');
        $publicPath = '/s/' . rawurlencode($slug);
        $page['public_path'] = $publicPath;
        $page['public_url'] = $this->absoluteUrl($publicPath);
        $page['pinterest_url'] = $this->absoluteUrl($publicPath . '?src=pinterest');

        $playlistId = (int)($page['primary_playlist_instance_id'] ?? 0);
        $playlistSlug = trim((string)($page['primary_playlist_slug'] ?? ''));
        if ($playlistId > 0) {
            $pathId = $playlistSlug !== '' ? $playlistSlug : (string)$playlistId;
            $playerPath = '/p/' . rawurlencode($pathId);
            $srcValue = $this->cleanSrc($src);
            if ($srcValue !== '') {
                $playerPath .= '?src=' . rawurlencode($srcValue);
            }
            $page['player_path'] = $playerPath;
            $page['player_url'] = $this->absoluteUrl($playerPath);
        } else {
            $page['player_path'] = null;
            $page['player_url'] = null;
        }

        $featuredRelPath = trim((string)($page['featured_pin_rel_path'] ?? ''));
        $page['featured_pin_url'] = $featuredRelPath !== '' ? $this->absoluteUrl('/' . ltrim($featuredRelPath, '/')) : null;
        return $page;
    }

    private function normalizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return substr($value, 0, 160);
    }

    private function absoluteUrl(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            return $path;
        }
        return $base . '/' . ltrim($path, '/');
    }

    private function cleanSrc(?string $src): string
    {
        $src = strtolower(trim((string)$src));
        return preg_match('/^[a-z0-9_-]{1,40}$/', $src) ? $src : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string)($value ?? ''));
        return $string !== '' ? $string : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }
}
