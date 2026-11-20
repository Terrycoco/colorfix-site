<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Website;

use App\PALETTES\PV\PVService;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\PHOTOS\Services\PhotoEditorialService;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PUB\Analyze\Sources\PlaylistSourcePreparer;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Read-only research package for editorial review, not the ARTICLES AI round-trip importer. */
final class ArticleResearchExportEndpoint
{
    public static function handle(PDO $pdo): void
    {
        $zipPath = null;
        $stage = 'validate request';
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                header('Allow: GET');
                http_response_code(405);
                throw new RuntimeException('Use GET to export article ingredients.');
            }
            $playlistId = filter_input(INPUT_GET, 'playlist_id', FILTER_VALIDATE_INT);
            if (!$playlistId || $playlistId <= 0) {
                http_response_code(400);
                throw new RuntimeException('A valid playlist_id is required.');
            }
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('PHP ZipArchive is unavailable on this server.');
            }

            $root = realpath(dirname(__DIR__, 4));
            if ($root === false) {
                throw new RuntimeException('Application root is unavailable.');
            }
            $stage = 'initialize source services';
            $preparer = new PlaylistSourcePreparer(
                new PdoPlaylistRepository($pdo),
                new PVService($pdo),
                new RexReservationRelationships(new PdoRexReservationRepository($pdo)),
                new PhotoEditorialService($pdo),
                new PdoPhotoLibraryRepository($pdo)
            );
            $stage = 'prepare playlist';
            $source = $preparer->prepare((int)$playlistId);
            if (strtolower(trim((string)($source['editorial_type'] ?? ''))) !== 'artwork') {
                http_response_code(422);
                throw new RuntimeException('This export currently requires editorial_type = artwork.');
            }
            $stage = 'initialize article analyzer';
            $analyzer = new ArticleAnalyzer($pdo);
            $stage = 'validate artwork ingredients';
            $signal = $analyzer->preflight($source);
            if (!$signal->isReady()) {
                http_response_code(422);
                throw new RuntimeException('The Article Analyzer could not validate this playlist. Check its artwork and palette relationships.');
            }
            $stage = 'assemble article ingredients';
            $result = $analyzer->analyze($source);
            $ingredients = $result['proposals'][0]['ingredients'] ?? null;
            if (!is_array($ingredients)) {
                throw new RuntimeException('The Article Analyzer returned no article ingredients.');
            }

            $stage = 'collect image files';
            // Strip internal server paths from the portable research document.
            $images = [];
            $photo = $ingredients['artwork']['photo'] ?? null;
            if (is_array($photo)) {
                self::collectPhoto($photo, 'inspiration', $root, $images);
                $ingredients['artwork']['photo'] = self::portablePhoto($photo);
            }
            foreach ($ingredients['slides'] as $index => $slide) {
                $photo = $slide['photo'] ?? null;
                if (!is_array($photo)) continue;
                self::collectPhoto($photo, 'room', $root, $images);
                $ingredients['slides'][$index]['photo'] = self::portablePhoto($photo);
            }
            if ($images === []) {
                throw new RuntimeException('There are no photograph files to include.');
            }
            $ingredients['research_images'] = array_values(array_map(
                static fn (array $image): array => [
                    'photo_library_id' => $image['id'],
                    'file' => $image['file'],
                    'role' => $image['role'],
                ], $images
            ));
            $ingredients['artwork']['image_file'] = self::imageFile(
                (int)($ingredients['artwork']['photo_library_id'] ?? 0), $images
            );
            foreach ($ingredients['slides'] as $index => $slide) {
                $photoId = (int)($slide['photo']['photo_library_id'] ?? 0);
                if ($photoId > 0) {
                    $ingredients['slides'][$index]['image_file'] = self::imageFile($photoId, $images);
                }
            }

            $stage = 'encode ingredients';
            $json = json_encode([
                'purpose' => 'ColorFix artwork article research material; not a database import file',
                'ingredients' => $ingredients,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $stage = 'build ZIP';
            $zipPath = tempnam(sys_get_temp_dir(), 'cf-artwork-');
            if ($zipPath === false) throw new RuntimeException('Could not create temporary export.');
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open research ZIP.');
            }
            try {
                $zip->addFromString('ingredients.json', $json);
                foreach ($images as $image) {
                    if (!$zip->addFile($image['path'], $image['file'])) {
                        throw new RuntimeException('Could not add image to ZIP: ' . $image['file']);
                    }
                }
            } finally {
                $zip->close();
            }

            $length = filesize($zipPath);
            if ($length === false || $length <= 0) throw new RuntimeException('Research ZIP was empty.');
            $stage = 'deliver ZIP';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="artwork-article-ingredients-' . $playlistId . '.zip"');
            header('Content-Length: ' . $length);
            header('Cache-Control: private, no-store');
            readfile($zipPath);
        } catch (Throwable $error) {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');
                if (http_response_code() < 400) http_response_code(500);
            }
            error_log('[ArticleResearchExport] ' . $stage . ': ' . $error);
            echo 'Article research export failed at: ' . $stage . '. ' . $error->getMessage();
        } finally {
            if ($zipPath !== null && is_file($zipPath)) @unlink($zipPath);
        }
    }

    /** @param array<int,array{id:int,file:string,path:string,role:string}> $images */
    private static function collectPhoto(array $photo, string $role, string $root, array &$images): void
    {
        $id = (int)($photo['photo_library_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Photograph is missing its Photo Library ID.');
        if (isset($images[$id])) return;
        $candidates = array_filter([
            $photo['file_path'] ?? null,
            $photo['raw_rel_path'] ?? null,
            $photo['rel_path'] ?? null,
        ], 'is_string');
        $path = false;
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || str_contains($candidate, '://')) continue;
            $resolved = realpath(str_starts_with($candidate, '/') ? $candidate : $root . '/' . ltrim($candidate, '/'));
            if ($resolved && is_file($resolved) && is_readable($resolved)
                && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                $path = $resolved;
                break;
            }
        }
        if ($path === false) {
            throw new RuntimeException('Cannot locate Photo Library image #' . $id . ' on the server.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Unsupported image format for photo #' . $id),
        };
        $images[$id] = [
            'id' => $id,
            'file' => 'images/' . $id . '.' . $ext,
            'path' => $path,
            'role' => $role,
        ];
    }

    private static function portablePhoto(array $photo): array
    {
        unset($photo['file_path'], $photo['raw_rel_path'], $photo['abs_path'], $photo['absolute_path']);
        return $photo;
    }

    /** @param array<int,array{id:int,file:string,path:string,role:string}> $images */
    private static function imageFile(int $id, array $images): ?string
    {
        return $images[$id]['file'] ?? null;
    }
}
