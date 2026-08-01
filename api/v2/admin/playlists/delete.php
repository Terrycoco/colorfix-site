<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $sql = <<<SQL
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'table' => $table,
        'column' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$playlistId = isset($payload['playlist_id']) ? (int)$payload['playlist_id'] : 0;
if ($playlistId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM playlist_instances WHERE playlist_id = :playlist_id');
    $stmt->execute(['playlist_id' => $playlistId]);
    $instanceCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM playlist_instance_set_items WHERE playlist_id = :playlist_id');
    $stmt->execute(['playlist_id' => $playlistId]);
    $setItemCount = (int)$stmt->fetchColumn();

    if ($instanceCount === 0 && $setItemCount === 0) {
        $stmt = $pdo->prepare('DELETE FROM playlist_items WHERE playlist_id = :playlist_id');
        $stmt->execute(['playlist_id' => $playlistId]);

        $stmt = $pdo->prepare('DELETE FROM playlists WHERE playlist_id = :playlist_id');
        $stmt->execute(['playlist_id' => $playlistId]);
        $pdo->commit();
        respond(['ok' => true, 'action' => 'deleted']);
    }

    $playlistFields = [
        'is_active = 0',
        'is_public = 0',
    ];
    if (columnExists($pdo, 'playlists', 'is_retired')) {
        $playlistFields[] = 'is_retired = 1';
    }
    if (columnExists($pdo, 'playlists', 'retired_at')) {
        $playlistFields[] = 'retired_at = NOW()';
    }
    $stmt = $pdo->prepare(
        'UPDATE playlists SET ' . implode(', ', $playlistFields) . ' WHERE playlist_id = :playlist_id'
    );
    $stmt->execute(['playlist_id' => $playlistId]);

    $instanceFields = ['is_active = 0'];
    if (columnExists($pdo, 'playlist_instances', 'is_retired')) {
        $instanceFields[] = 'is_retired = 1';
    }
    if (columnExists($pdo, 'playlist_instances', 'retired_at')) {
        $instanceFields[] = 'retired_at = NOW()';
    }
    $stmt = $pdo->prepare(
        'UPDATE playlist_instances SET ' . implode(', ', $instanceFields) . ' WHERE playlist_id = :playlist_id'
    );
    $stmt->execute(['playlist_id' => $playlistId]);

    if (columnExists($pdo, 'playlist_instance_set_items', 'playlist_id')) {
        $stmt = $pdo->prepare('DELETE FROM playlist_instance_set_items WHERE playlist_id = :playlist_id');
        $stmt->execute(['playlist_id' => $playlistId]);
    }

    if (columnExists($pdo, 'playlist_instance_set_items', 'playlist_instance_id')) {
        $stmt = $pdo->prepare(
            'DELETE psi
               FROM playlist_instance_set_items psi
               JOIN playlist_instances pi
                 ON pi.playlist_instance_id = psi.playlist_instance_id
              WHERE pi.playlist_id = :playlist_id'
        );
        $stmt->execute(['playlist_id' => $playlistId]);
    }

    $pdo->commit();
    respond([
        'ok' => true,
        'action' => 'retired',
        'message' => 'Playlist is referenced, so it was retired instead of physically deleted.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
