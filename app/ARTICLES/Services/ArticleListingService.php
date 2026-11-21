<?php
declare(strict_types=1);

namespace App\ARTICLES\Services;

use App\ARTICLES\Repos\PdoArticleRepository;
use App\PHOTOS\Services\PhotoDeliveryService;
use App\PHOTOS\Services\PhotoReplacementService;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use PDO;

/** Public, publication-filtered article previews; no draft metadata is returned. */
final class ArticleListingService
{
    public function __construct(private PDO $pdo) {}

    public function list(int $limit = 24, int $offset = 0): array
    {
        $repo = new PdoArticleRepository($this->pdo);
        $articles = $repo->listArticles(['status' => 'published'], min(48, max(1, $limit)), max(0, $offset));
        $photoIds = array_values(array_unique(array_filter(array_map(
            static fn(array $article): int => (int)($article['hero_asset_id'] ?? 0), $articles
        ))));
        $photos = (new PdoPhotoLibraryRepository($this->pdo))->findManyByIds($photoIds);

        return array_map(static function (array $article) use ($photos): array {
            $photoId = (int)($article['hero_asset_id'] ?? 0);
            $photo = $photos[$photoId] ?? null;
            return [
                'id' => (int)$article['id'],
                'title' => (string)$article['title'],
                'kicker' => (string)($article['kicker'] ?? ''),
                'dek' => (string)($article['dek'] ?? ''),
                'published_at' => $article['published_at'] ?: null,
                'url' => '/articles/' . (int)$article['id'],
                'thumbnail_url' => $photo ? PhotoDeliveryService::url($photoId, (string)$photo['rel_path'], 640, 'swatch') : null,
            ];
        }, $articles);
    }

    /** The same published featured selection used by the homepage, with a few ordered section photos. */
    public function featuredPreview(): ?array
    {
        $repo = new PdoArticleRepository($this->pdo);
        $article = $repo->getFeaturedOrLatest();
        if (!$article) return null;

        // Select the first four landscape room photographs in article order. PHOTOS
        // resolves original source paths; derivative cropping remains entirely PHOTOS-owned.
        $sectionIds = [];
        foreach ($repo->listSections((int)$article['id']) as $section) {
            if (!in_array((string)($section['kind'] ?? ''), ['image', 'image_text'], true)) continue;
            $id = (int)($section['asset_id'] ?? 0);
            if ($id > 0 && !in_array($id, $sectionIds, true)) $sectionIds[] = $id;
        }
        $heroId = (int)($article['hero_asset_id'] ?? 0);
        $lookupIds = array_values(array_unique(array_merge($sectionIds, $heroId > 0 ? [$heroId] : [])));
        $photos = (new PdoPhotoLibraryRepository($this->pdo))->findManyByIds($lookupIds);
        $paths = new PhotoReplacementService($this->pdo, (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));

        $images = [];
        foreach ($sectionIds as $id) {
            $photo = $photos[$id] ?? null;
            if (!$photo || empty($photo['rel_path']) || (string)($photo['source_type'] ?? '') === 'art') continue;
            if (!$this->isLandscapePhoto($paths, (string)$photo['rel_path'])) continue;
            $images[] = $this->previewImage($id, $photo);
            if (count($images) === 4) break;
        }

        // Only fall back to the article hero if that source is landscape too.
        if (!$images && $heroId > 0 && isset($photos[$heroId])) {
            $heroPhoto = $photos[$heroId];
            if (!empty($heroPhoto['rel_path'])
                && (string)($heroPhoto['source_type'] ?? '') !== 'art'
                && $this->isLandscapePhoto($paths, (string)$heroPhoto['rel_path'])) {
                $images[] = $this->previewImage($heroId, $heroPhoto);
            }
        }

        return [
            'id' => (int)$article['id'],
            'title' => (string)$article['title'],
            'kicker' => (string)($article['kicker'] ?? ''),
            'dek' => (string)($article['dek'] ?? ''),
            'published_at' => $article['published_at'] ?: null,
            'url' => '/articles/' . (int)$article['id'],
            'images' => $images,
        ];
    }
    /** A landscape is a property of the original photograph, not a crop preset. */
    private function isLandscapePhoto(PhotoReplacementService $paths, string $relPath): bool
    {
        $path = $paths->localPath($relPath);
        if ($path === null) return false;
        $size = @getimagesize($path);
        if (!is_array($size)) return false;
        $width = (int)($size[0] ?? 0);
        $height = (int)($size[1] ?? 0);
        if ($width <= 0 || $height <= 0) return false;

        // Match the visible orientation of JPEGs stored with camera rotation metadata.
        if (($size['mime'] ?? '') === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path, 'IFD0', true);
            $orientation = (int)($exif['IFD0']['Orientation'] ?? 1);
            if (in_array($orientation, [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        }
        return $width > $height;
    }

    private function previewImage(int $photoId, array $photo): array
    {
        return [
            'src' => PhotoDeliveryService::url($photoId, (string)$photo['rel_path'], 1280, 'yt_landscape'),
            'mobileSrc' => PhotoDeliveryService::url($photoId, (string)$photo['rel_path'], 800, 'swatch'),
            'alt' => (string)($photo['alt_text'] ?? $photo['title'] ?? ''),
        ];
    }

}
