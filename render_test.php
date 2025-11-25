<?php
declare(strict_types=1);
require __DIR__ . '/api/autoload.php';

use App\Services\PhotoRenderingService;
use App\Repos\PdoPhotoRepository;

class StubPhotoRepo extends PdoPhotoRepository {
    public function __construct() {}
    public function getPhotoByAssetId(string $assetId): ?array {
        if ($assetId !== 'PHO_YNYZ5Z') {
            return null;
        }
        return ['id' => 1, 'asset_id' => $assetId];
    }
    public function listVariants(int $photoId): array {
        return [
            ['kind' => 'prepared_base', 'role' => null, 'path' => 'TestPhotos/PHO_YNYZ5Z_prepared_1600.jpg'],
            ['kind' => 'mask', 'role' => 'body', 'path' => 'TestPhotos/body.png'],
            ['kind' => 'mask', 'role' => 'trim', 'path' => 'TestPhotos/trim.png'],
            ['kind' => 'mask', 'role' => 'gutter', 'path' => 'TestPhotos/gutter.png'],
            ['kind' => 'mask', 'role' => 'shutters', 'path' => 'TestPhotos/shutters.png'],
            ['kind' => 'mask', 'role' => 'frontdoor', 'path' => 'TestPhotos/frontdoor.png'],
        ];
    }
    public function getRoleStats(int $photoId): array {
        return [];
    }
}

$repo = new StubPhotoRepo();
$pdo = new PDO('sqlite::memory:');
$svc = new PhotoRenderingService($repo, $pdo);
$result = $svc->renderApplyMap('PHO_YNYZ5Z', [
    'body' => 'D9C4B6',
    'trim' => 'F0EFEF',
    'accent' => '51312D',
    'frontdoor' => '8A4E3A',
]);
var_export($result);
