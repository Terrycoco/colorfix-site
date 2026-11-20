<?php
declare(strict_types=1);

namespace App\ARTICLES\Endpoints;

use App\ARTICLES\Repos\PdoArticleRepository;
use App\ARTICLES\Services\ArticleService;
use App\ARTWORK\Repos\PdoArtworkRepository;
use App\PALETTES\Repos\PdoSavedPaletteRepository;
use App\PALETTES\Services\PaletteService;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Admin-only read-only ZIP export. ARTICLES owns draft exchange, not PUB. */
final class ArticleAIExportEndpoint
{
    public static function handle(PDO $pdo): void
    {
        $path = null;
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                header('Allow: GET');
                http_response_code(405);
                throw new RuntimeException('GET required.');
            }
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            if (!$id || $id <= 0) {
                http_response_code(400);
                throw new RuntimeException('Valid article id required.');
            }
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('PHP ZipArchive is not installed.');
            }
            $article = (new ArticleService(new PdoArticleRepository($pdo)))->getArticle((int)$id);
            if (!$article) {
                http_response_code(404);
                throw new RuntimeException('Article not found.');
            }
            if (strtolower((string)($article['status'] ?? '')) !== 'draft') {
                http_response_code(409);
                throw new RuntimeException('Only draft articles can be exported for AI editing.');
            }

            // Playlist editorial type, not ARTICLES.article.type ('colorfix').
            $stmt = $pdo->prepare('SELECT playlist_id, editorial_type FROM playlists WHERE article_id = :id ORDER BY playlist_id LIMIT 2');
            $stmt->execute(['id' => $id]);
            $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($sources) !== 1 || (string)$sources[0]['editorial_type'] !== 'artwork') {
                http_response_code(422);
                throw new RuntimeException('Artwork AI export requires exactly one linked artwork playlist.');
            }
            $playlistId = (int)$sources[0]['playlist_id'];
            $appRoot = realpath(dirname(__DIR__, 3));
            if ($appRoot === false) throw new RuntimeException('Application root not found.');
            $instructions = __DIR__ . '/../AI/Instructions/Artwork.md';
            if (!is_readable($instructions)) {
                throw new RuntimeException('ARTICLES/AI/Instructions/Artwork.md is not installed.');
            }

            $photos = new PdoPhotoLibraryRepository($pdo);
            $artworks = new PdoArtworkRepository($pdo);
            $paletteRepo = new PdoSavedPaletteRepository($pdo);
            $paletteService = new PaletteService($pdo);
            $images = [];
            $imageInfo = [];
            $paletteIds = [];
            $sections = [];
            $originalImages = [];
            foreach ($article['sections'] ?? [] as $section) {
                if (!is_array($section)) continue;
                $clean = self::fields($section, [
                    'id', 'sort_order', 'kind', 'heading', 'heading_level',
                    'body', 'caption', 'asset_id', 'palette_id'
                ]);
                $photoId = (int)($clean['asset_id'] ?? 0);
                if ($photoId > 0) {
                    $image = self::includePhoto($photoId, $photos, $appRoot, $images, $imageInfo);
                    $clean['image_file'] = $image;
                    $artwork = $artworks->findByPhotoId($photoId);
                    if (is_array($artwork)) {
                        $originalImages[$photoId] = $artwork;
                    }
                }
                $paletteId = (int)($clean['palette_id'] ?? 0);
                if ($paletteId > 0) $paletteIds[$paletteId] = true;
                $sections[] = $clean;
            }
            foreach (['hero_asset_id', 'hero_mobile_asset_id'] as $key) {
                $photoId = (int)($article[$key] ?? 0);
                if ($photoId > 0) self::includePhoto($photoId, $photos, $appRoot, $images, $imageInfo);
            }
            if ($images === []) throw new RuntimeException('Article has no Photo Library images.');
            if ($originalImages === []) throw new RuntimeException('No original ARTWORK image was found among the article sections.');

            $paletteData = [];
            foreach (array_keys($paletteIds) as $paletteId) {
                $record = $paletteRepo->getSavedPaletteById((int)$paletteId);
                if (!$record) throw new RuntimeException('Referenced palette #' . $paletteId . ' not found.');
                $paletteData[] = [
                    'palette_id' => (int)$paletteId,
                    'palette' => $record,
                    'swatches' => $paletteService->getSwatches((int)$paletteId),
                    'members' => $paletteRepo->getMembersForPalette((int)$paletteId),
                ];
            }
            if ($paletteData === []) throw new RuntimeException('Artwork article has no palette section.');

            $editable = self::fields($article, [
                'id', 'title', 'slug', 'dek', 'meta_description',
            ]);
            $editable['sections'] = $sections;
            $context = [
                'editorial_type' => 'artwork',
                'source_playlist_id' => $playlistId,
                'artwork' => array_values($originalImages),
                'photos' => $imageInfo,
            ];
            $path = tempnam(sys_get_temp_dir(), 'article-ai-');
            if ($path === false) throw new RuntimeException('Temporary ZIP allocation failed.');
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create ZIP.');
            }
            try {
                self::addJSON($zip, 'article.json', $editable);
                self::addJSON($zip, 'palette.json', $paletteData);
                self::addJSON($zip, 'source-context.json', $context);
                $zip->addFile($instructions, 'instructions.md');
                foreach ($images as $filename => $file) {
                    if (!$zip->addFile($file, $filename)) {
                        throw new RuntimeException('Failed to attach image ' . $filename);
                    }
                }
            } finally {
                $zip->close();
            }
            $size = filesize($path);
            if (!$size) throw new RuntimeException('Export ZIP is empty.');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="article-ai-' . $id . '.zip"');
            header('Content-Length: ' . $size);
            header('Cache-Control: private, no-store');
            readfile($path);
        } catch (Throwable $e) {
            error_log('[ArticleAIExport] ' . $e);
            if (!headers_sent()) {
                if (http_response_code() < 400) http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');
            }
            echo 'Article AI export failed: ' . $e->getMessage();
        } finally {
            if ($path !== null && is_file($path)) @unlink($path);
        }
    }

    private static function fields(array $value, array $fields): array
    {
        return array_intersect_key($value, array_flip($fields));
    }

    private static function addJSON(ZipArchive $zip, string $filename, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$zip->addFromString($filename, $json)) throw new RuntimeException('Could not add ' . $filename);
    }

    /** The photo's stored path comes from PHOTOS; never trust client-provided paths. */
    private static function includePhoto(int $id, PdoPhotoLibraryRepository $repo, string $root, array &$images, array &$metadata): string
    {
        $row = $repo->findById($id);
        if (!$row) throw new RuntimeException('Photo Library image #' . $id . ' does not exist.');
        $relative = trim((string)($row['rel_path'] ?? ''));
        if ($relative === '' || str_contains($relative, '://')) {
            throw new RuntimeException('Photo #' . $id . ' has no local image path.');
        }
        $resolved = realpath($root . '/' . ltrim($relative, '/'));
        if (!$resolved || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) || !is_file($resolved) || !is_readable($resolved)) {
            throw new RuntimeException('Cannot read Photo Library image #' . $id);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($resolved);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Unsupported photograph format for #' . $id),
        };
        $filename = 'images/' . $id . '.' . $ext;
        $images[$filename] = $resolved;
        $metadata[$id] = [
            'photo_library_id' => $id,
            'file' => $filename,
            'title' => $row['title'] ?? null,
            'alt_text' => $row['alt_text'] ?? null,
            'source_type' => $row['source_type'] ?? null,
        ];
        return $filename;
    }
}
