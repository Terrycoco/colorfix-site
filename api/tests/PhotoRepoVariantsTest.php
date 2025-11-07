<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php'; // must yield $pdo = new PDO(...)

use App\Repos\PdoPhotoRepository;

$out = ['timestamp' => date('c')];
try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Missing $pdo from /api/db.php');
    }

    // Detect column set so we don't reference non-existent fields
    $cols = $pdo->query("SHOW COLUMNS FROM photos_variants")->fetchAll(PDO::FETCH_COLUMN, 0);
    $have = array_flip(array_map('strtolower', $cols));

    // Ensure UNIQUE exists on (photo_id,kind,role)
    $idxRows = $pdo->query("SHOW INDEX FROM photos_variants")->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($idxRows as $row) {
        if ((int)($row['Non_unique'] ?? 1) === 0) {
            $keys[$row['Key_name']][] = strtolower((string)$row['Column_name']);
        }
    }
    $hasUnique = false;
    foreach ($keys as $colsForKey) {
        $norm = $colsForKey; sort($norm);
        if ($norm === ['kind','photo_id','role']) { $hasUnique = true; break; }
    }
    if (!$hasUnique) {
        $out['ok'] = false;
        $out['error'] = 'Missing UNIQUE index on photos_variants(photo_id,kind,role)';
        $out['suggest_migration'] = "ALTER TABLE photos_variants ADD UNIQUE uniq_photo_kind_role (photo_id, kind, role);";
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Create temp photo
    $assetId = 'PHO_TEST_' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare("INSERT INTO photos (asset_id, created_at) VALUES (:aid, NOW())")
        ->execute([':aid' => $assetId]);
    $photoId = (int)$pdo->lastInsertId();
    if ($photoId <= 0) throw new RuntimeException('Failed to create test photo');

    // Overwrite test
    $repo = new PdoPhotoRepository($pdo);
    $kind = 'prepared';
    $role = ''; // normalized (NOT NULL default '')

    $repo->upsertVariant($photoId, $kind, $role, "/photos/test/$assetId/prepared/base-v1.jpg", 1600, 1067);
    $repo->upsertVariant($photoId, $kind, $role, "/photos/test/$assetId/prepared/base-v2.jpg", 2000, 1333);

    // Build a SELECT that only includes columns that exist
    $selectCols = ['id','photo_id','kind','role','path','width','height'];
    if (isset($have['created_at'])) $selectCols[] = 'created_at';
    if (isset($have['updated_at'])) $selectCols[] = 'updated_at';

    $sql = sprintf(
        "SELECT %s FROM photos_variants
         WHERE photo_id = :pid AND kind = :kind AND role = :role",
        implode(',', $selectCols)
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pid'=>$photoId, ':kind'=>$kind, ':role'=>$role]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out['rows_found'] = count($rows);
    $out['row'] = $rows[0] ?? null;

    $ok = count($rows) === 1
        && isset($rows[0]['path'])
        && (string)$rows[0]['path'] === "/photos/test/$assetId/prepared/base-v2.jpg";

    $out['ok'] = $ok;

    // Cleanup
    $pdo->prepare("DELETE FROM photos_variants WHERE photo_id = :pid")->execute([':pid'=>$photoId]);
    $pdo->prepare("DELETE FROM photos WHERE id = :pid")->execute([':pid'=>$photoId]);

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['where'] = 'PhotoRepoVariantsTest:catch';
    $out['error'] = $e->getMessage();
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
