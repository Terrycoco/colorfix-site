<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use PDO;
use Throwable;

/** Read-only resource selector data. Expose through an admin-authenticated API doorbell. */
final class RexResourceOptionsEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'GET only']);
            return;
        }

        // Explicit whitelist. Adjust mappings to match your real schema; never interpolate request values into SQL.
        $resources = [
            'playlist' => ['table' => 'playlists', 'title' => 'title'],
            'playlists' => ['table' => 'playlists', 'title' => 'title'],
            'palette' => ['table' => 'project_palettes', 'title' => 'title'],
            'project_palette' => ['table' => 'project_palettes', 'title' => 'title'],
            'document' => ['table' => 'project_documents', 'title' => 'title'],
            'project_document' => ['table' => 'project_documents', 'title' => 'title'],
            'project' => ['table' => 'projects', 'title' => 'title'],
        ];
        $type = strtolower(trim((string)($_GET['resource_type'] ?? '')));
        if (!isset($resources[$type])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported resource type: ' . $type]);
            return;
        }

        try {
            $table = $resources[$type]['table'];
            $title = $resources[$type]['title'];
            // Identifiers above are hardcoded, not user-provided.
            $stmt = $pdo->query("SELECT id, COALESCE(NULLIF(TRIM(`{$title}`), ''), 'Untitled') AS title FROM `{$table}` ORDER BY title ASC, id ASC");
            $items = array_map(static fn(array $row): array => [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);
            error_log('REX resource options: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Resource lookup failed; check table/title mapping.']);
        }
    }
}
