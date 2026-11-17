<?php
declare(strict_types=1);
namespace App\PHOTOS\Services;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\Repos\PdoAssetLibraryRepository;
use App\Repos\PdoClientRepository;
use App\Services\AssetLibraryService;
use App\Services\ClientService;
use App\Services\PhotoAltTextQueueService;
use App\Services\PhotoLibraryService;

use PDO;

/** Current multipart Photo Library upload. No base/variants, masks or Palette Sets. */
final class PhotoLibraryUploadService
{
    public function __construct(private PDO $pdo) {}
    public function upload(array $post, array $uploads, string $root): array
    {
        $pdo = $this->pdo;
        $sourceType = isset($post['source_type']) ? trim((string)$post['source_type']) : '';
        if (!in_array($sourceType, ['saved_palette_photo', 'progression', 'article', 'pin', 'client', 'art', 'art_inspired', 'inspiration'], true)) {
            throw new \InvalidArgumentException('source_type must be saved_palette_photo, progression, article, pin, client, art, art_inspired, or inspiration');
        }

        if ($sourceType === 'saved_palette_photo') {
            if (!empty($post['create_new_set']) || !empty($post['set_id'])) { throw new \InvalidArgumentException('Palette Sets are retired. Save an independent palette or use the current Palette Viewer editor.'); }
            if (($post['photo_type'] ?? 'full') !== 'full') { throw new \InvalidArgumentException('Before and Zoom photos belong to the current Palette Viewer editor.'); }
            if (!empty($post['replace_photo_id'])) { throw new \InvalidArgumentException('Use Photo Library replacement with a photo_library_id.'); }
        }

        if (empty($uploads['photos'])) {
            throw new \InvalidArgumentException('photos[] required');
        }

        $paletteId = $sourceType === 'saved_palette_photo' ? (int)($post['palette_id'] ?? 0) : 0;
        if ($sourceType === 'saved_palette_photo' && ($paletteId <= 0 || !(new \App\Repos\PdoSavedPaletteRepository($pdo))->getSavedPaletteById($paletteId))) { throw new \InvalidArgumentException('Valid palette_id required'); }
        $files = $uploads['photos'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        if ($count === 0) {
            throw new \InvalidArgumentException('No files uploaded');
        }

        if ($paletteId > 0) {
            if ($count !== 1) { throw new \InvalidArgumentException('Upload one Main photo per palette. Other views belong to the Palette Viewer.'); }
            if ((new PdoPhotoLibraryRepository($pdo))->listForPalette($paletteId)) { throw new \InvalidArgumentException('Palette already has a Main photo. Use Photo Library replacement or save an independent palette.'); }
        }

        $seriesRaw = trim((string)($post['series'] ?? ''));
        $series = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $seriesRaw);
        if ($series === '') {
            $series = date('Ymd');
        }

        if ($paletteId > 0) { $series = (string)$paletteId; }

        $titlePrefix = trim((string)($post['title_prefix'] ?? ''));
        $tags = trim((string)($post['tags'] ?? ''));
        $altText = trim((string)($post['alt_text'] ?? ''));
        $permissionStatus = trim((string)($post['photo_permission_status'] ?? ''));
        $showInGallery = !empty($post['show_in_gallery']);
        $hasPalette = !empty($post['has_palette']);
        $clientName = trim((string)($post['client_name'] ?? ''));
        $clientEmail = trim((string)($post['client_email'] ?? ''));
        $clientId = null;
        $clientFolder = '';

        $docRoot = rtrim($root, '/');
        $folderBase = match ($sourceType) {
            'saved_palette_photo' => 'uploads/saved-palettes',
            'article' => 'articles',
            'pin' => 'pins',
            'client' => 'clients',
            'art' => 'art',
            default => 'progressions',
        };
        if ($sourceType === 'client') {
            if ($clientEmail === '') {
                throw new \InvalidArgumentException('client_email required for client uploads');
            }
            $clientService = new ClientService(new PdoClientRepository($pdo));
            $client = $clientService->findOrCreateByEmail($clientEmail, $clientName !== '' ? $clientName : null);
            $clientId = (int)$client['id'];
            $folderLabelRaw = $client['name'] !== '' ? $client['name'] : $client['email'];
            $folderLabel = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower((string)$folderLabelRaw));
            $folderLabel = trim((string)$folderLabel, '-');
            if ($folderLabel === '') {
                $folderLabel = 'client';
            }
            $clientFolder = 'client-' . $clientId . '-' . $folderLabel;
            $series = $clientFolder;
            if ($titlePrefix === '') {
                $titlePrefix = $client['name'] !== '' ? $client['name'] : $client['email'];
            }
        }

        $photosRoot = $docRoot . '/photos/' . $folderBase . '/' . $series;
        if (!is_dir($photosRoot) && !mkdir($photosRoot, 0775, true) && !is_dir($photosRoot)) {
            throw new \RuntimeException('Failed to create upload directory');
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $added = [];

        $repo = new PdoPhotoLibraryRepository($pdo);
        $library = new PhotoLibraryService($repo);
        $assetLibrary = new AssetLibraryService(new PdoAssetLibraryRepository($pdo), '');
        $altTextQueue = PhotoAltTextQueueService::fromPdo($pdo);

        for ($i = 0; $i < $count; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = $files['tmp_name'][$i] ?? '';
            $orig = $files['name'][$i] ?? 'photo';
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }

            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                continue;
            }

            $info = @getimagesize($tmp);
            if (!$info) {
                continue;
            }

            $slug = bin2hex(random_bytes(6));
            $filename = "{$sourceType}_{$series}_{$slug}.{$ext}";
            $absPath = $photosRoot . '/' . $filename;
            if (!move_uploaded_file($tmp, $absPath)) {
                continue;
            }

            $relPath = "/photos/{$folderBase}/{$series}/{$filename}";
            $baseName = pathinfo($orig, PATHINFO_FILENAME);
            $title = $titlePrefix !== '' ? trim($titlePrefix . ' ' . $baseName) : $baseName;

            $libraryId = $library->createStandalone($sourceType, $relPath, [
                'source_id' => $clientId,
                'client_id' => $clientId,
                'title' => $title,
                'tags' => $tags !== '' ? $tags : null,
                'alt_text' => $altText !== '' ? $altText : null,
                'photo_permission_status' => $permissionStatus,
                'show_in_gallery' => $showInGallery ? 1 : 0,
                'has_palette' => $hasPalette ? 1 : 0,
            ]);

            if ($libraryId > 0) {
                if ($paletteId > 0) { (new PhotoPaletteAssociationService($pdo))->attach($libraryId,$paletteId); }
                $asset = $assetLibrary->upsertAssetForPhoto($libraryId, $relPath, [
                    'asset_kind' => 'image',
                    'mime_type' => $info['mime'] ?? null,
                    'title' => $title,
                    'tags' => $tags !== '' ? $tags : null,
                    'alt_text' => $altText !== '' ? $altText : null,
                    'note' => 'Uploaded via Photo Library',
                    'source_type' => 'photo_library',
                    'source_id' => $libraryId,
                    'client_id' => $clientId,
                    'width' => isset($info[0]) ? (int)$info[0] : null,
                    'height' => isset($info[1]) ? (int)$info[1] : null,
                    'file_size_bytes' => is_file($absPath) ? filesize($absPath) : null,
                    'checksum' => is_file($absPath) ? hash_file('sha256', $absPath) : null,
                    'metadata_json' => [
                        'photo_library_id' => $libraryId,
                        'upload_source_type' => $sourceType,
                        'upload_series' => $series,
                    ],
                    'is_inactive' => 0,
                    'is_retired' => 0,
                ]);
                if (!empty($asset['asset_library_id'])) {
                    $repo->update($libraryId, ['asset_library_id' => (int)$asset['asset_library_id']]);
                }
                if ($altText === '') {
                    $altTextQueue->enqueue($libraryId);
                }
                $added[] = [
                    'id' => $libraryId,
                    'saved_palette_id' => $paletteId > 0 ? $paletteId : null,
                    'photo_type' => 'full',
                    'photo_library_id' => $libraryId,
                    'asset_library_id' => isset($asset['asset_library_id']) ? (int)$asset['asset_library_id'] : null,
                    'rel_path' => $relPath,
                    'title' => $title,
                    'client_id' => $clientId,
                ];
            }
        }

        if (!$added) {
            throw new \InvalidArgumentException('No valid images uploaded');
        }

        return ['ok' => true, 'items' => $added, 'photos' => $added];
    }
}
