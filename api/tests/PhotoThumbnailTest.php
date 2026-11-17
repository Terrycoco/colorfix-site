<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';

use App\PHOTOS\Services\PhotoThumbnailService;
use App\PHOTOS\Endpoints\PhotoThumbnailEndpoint;

// Only generated images and in-memory SQLite; no production bootstrap/files.
$root = sys_get_temp_dir() . '/colorfix-thumb-' . bin2hex(random_bytes(6));
mkdir($root . '/photos', 0755, true);
$checks = 0;
$expect = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
};
ob_start();
try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY,rel_path TEXT,updated_at TEXT, show_in_gallery INTEGER DEFAULT 1)');
    $pdo->exec('CREATE TABLE photo_crops (photo_crop_id INTEGER PRIMARY KEY,photo_library_id INTEGER,crop_type TEXT,crop_x REAL,crop_y REAL,crop_width REAL,crop_height REAL,created_at TEXT,updated_at TEXT)');
    $image = imagecreatetruecolor(400, 600);
    foreach ([[0,199,255,0,0],[200,399,0,255,0],[400,599,0,0,255]] as [$y1,$y2,$r,$g,$b]) {
        imagefilledrectangle($image, 0, $y1, 399, $y2, imagecolorallocate($image,$r,$g,$b));
    }
    imagepng($image, $root . '/photos/source.png');
    imagedestroy($image);
    $originalHash = hash_file('sha256', $root . '/photos/source.png');
    $pdo->exec("INSERT INTO photo_library (photo_library_id,rel_path,updated_at) VALUES (746,'/photos/source.png','2026-11-10')");
    $service = new PhotoThumbnailService($pdo);
    $uncropped = $service->get(746, 200, 80, $root);
    $expect(array_slice(getimagesize($uncropped['path']),0,2) === [200,300] && !$uncropped['is_custom'], 'No saved crop preserves original aspect ratio');
    $pdo->exec("INSERT INTO photo_crops VALUES (1,746,'swatch',0,0,1,0.533333,NULL,NULL)");
    $cropped = $service->get(746, 200, 80, $root);
    $expect(array_slice(getimagesize($cropped['path']),0,2) === [200,160] && $cropped['is_custom'], 'Saved 5:4 crop is rendered');
    $decoded = imagecreatefromstring(file_get_contents($cropped['path']));
    $top = imagecolorsforindex($decoded,imagecolorat($decoded,100,5));
    $bottom = imagecolorsforindex($decoded,imagecolorat($decoded,100,155));
    $expect($top['red'] > 220 && $top['blue'] < 20, 'Saved upper framing retains the top subject');
    $expect($bottom['green'] > 220 && $bottom['blue'] < 20, 'Crop excludes pixels below its boundary');
    imagedestroy($decoded);
    $expect($service->get(746,200,80,$root)['path'] === $cropped['path'], 'Identical crop reuses existing cache');
    $raw = $service->get(746,200,80,$root,'original');
    $expect(array_slice(getimagesize($raw['path']),0,2) === [200,300] && !$raw['is_custom'], 'Full-composition display explicitly bypasses every gallery crop');
    $pdo->exec('UPDATE photo_crops SET crop_y=0.466667 WHERE photo_crop_id=1');
    $changed = $service->get(746,200,80,$root);
    $expect($changed['path'] !== $cropped['path'], 'Crop edit invalidates cache even without photo updated_at change');
    $decoded = imagecreatefromstring(file_get_contents($changed['path']));
    $bottom = imagecolorsforindex($decoded,imagecolorat($decoded,100,155));
    $expect($bottom['blue'] > 220, 'Changed framing produces changed pixels');
    imagedestroy($decoded);
    $pdo->exec("INSERT INTO photo_crops VALUES (2,746,'square',0,0,1,0.666667,NULL,NULL)");
    $square = $service->get(746,200,80,$root,'square');
    $expect(array_slice(getimagesize($square['path']),0,2) === [200,200], 'Other named preset uses its own saved crop');
    $pdo->exec('DELETE FROM photo_crops WHERE photo_crop_id=1');
    $expect($service->get(746,200,80,$root)['path'] === $uncropped['path'], 'Reset returns to original thumbnail');
    $expect(hash_file('sha256',$root . '/photos/source.png') === $originalHash, 'Original bytes never change');
    foreach ([['id'=>746],['id'=>746,'crop_type'=>'original'],['id'=>999],['id'=>0],['id'=>746,'crop_type'=>'invalid']] as $query) {
        $_GET=$query;
        http_response_code(200);
        ob_start(); PhotoThumbnailEndpoint::handle($pdo,$root); $body=ob_get_clean();
        $expected = $query['id'] === 999 ? 404 : ($query['id'] === 0 || ($query['crop_type'] ?? '') === 'invalid' ? 400 : 200);
        $expect(http_response_code() === $expected, 'Existing endpoint status: '.json_encode($query));
        if ($expected === 200) { $expect(getimagesizefromstring($body) !== false, 'Endpoint returns actual image bytes'); }
    }
    ob_end_clean();
    echo "PASS: {$checks} thumbnail crop/cache/pixel/endpoint checks\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
