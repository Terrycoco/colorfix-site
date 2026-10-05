<?php
declare(strict_types=1);

namespace App\PROJECTS\Repos;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class PdoProjectPhotoRepository
{
    private ?bool $schemaAvailable = null;
    private ?bool $libraryPaletteAvailable = null;
    private ?bool $roomColumnAvailable = null;

    public function __construct(private PDO $pdo) {}

    public function schemaAvailable(): bool
    {
        if ($this->schemaAvailable !== null) {
            return $this->schemaAvailable;
        }
        try {
            $this->pdo->query('SELECT project_id, photo_library_id, `use`, zoom, main, `before`, sort_order FROM project_photos WHERE 1 = 0');
            return $this->schemaAvailable = true;
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S02', '42S22'], true) || str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), 'no such column')) {
                return $this->schemaAvailable = false;
            }
            throw $e;
        }
    }

    public function libraryOwnsPalette(): bool
    {
        if ($this->libraryPaletteAvailable !== null) { return $this->libraryPaletteAvailable; }
        try {
            $this->pdo->query('SELECT palette_id FROM photo_library WHERE 1 = 0');
            $this->pdo->query('SELECT room_id FROM project_photos WHERE 1 = 0');
            return $this->libraryPaletteAvailable = true;
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S22', 'HY000'], true)) { return $this->libraryPaletteAvailable = false; }
            throw $e;
        }
    }

    public function rooms(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT rooms FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        return json_decode($stmt->fetchColumn() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }

    public function roomsAssignable(): bool
    {
        if ($this->roomColumnAvailable !== null) { return $this->roomColumnAvailable; }
        try {
            $this->pdo->query('SELECT room_id FROM project_photos WHERE 1 = 0');
            return $this->roomColumnAvailable = true;
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['42S22', 'HY000'], true)) { return $this->roomColumnAvailable = false; }
            throw $e;
        }
    }

    private function paletteRoomId(int $projectId, int $paletteId): ?string
    {
        $palettes = $this->palettes($projectId);
        foreach ($palettes as $palette) {
            if ((int)$palette['saved_palette_id'] !== $paletteId) { continue; }
            foreach ($this->rooms($projectId) as $room) {
                if ($room['name'] === trim((string)$palette['area_label'])) { return $room['id']; }
            }
        }
        return null;
    }

    public function revision(int $projectId): string
    {
        if (!$this->schemaAvailable()) {
            return '';
        }
        $roomColumn = $this->roomsAssignable() ? ', room_id' : '';
        $sql = $this->libraryOwnsPalette()
            ? 'SELECT pp.photo_library_id, pp.`use`, pl.palette_id, pp.room_id, pp.zoom, pp.main, pp.`before`, pp.sort_order FROM project_photos pp JOIN photo_library pl ON pl.photo_library_id = pp.photo_library_id WHERE pp.project_id = ? ORDER BY pp.sort_order, pp.photo_library_id'
            : "SELECT photo_library_id, `use`, palette_id{$roomColumn}, zoom, main, `before`, sort_order FROM project_photos WHERE project_id = ? ORDER BY sort_order, photo_library_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ? hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) : '';
    }

    public function palettes(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pp.project_palette_id, pp.saved_palette_id, pp.area_label,
                    sp.display_title, sp.nickname
             FROM project_palettes pp
             JOIN saved_palettes sp ON sp.id = pp.saved_palette_id
             WHERE pp.project_id = ? ORDER BY pp.order_index, pp.project_palette_id'
        );
        $stmt->execute([$projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listForProject(int $projectId): array
    {
        $paletteColumn = $this->libraryOwnsPalette() ? ', pl.palette_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT pp.*{$paletteColumn}, pl.rel_path, COALESCE(NULLIF(pl.ai_alt_text, ''), pl.alt_text) AS alt_text,
                    pl.updated_at AS photo_updated_at
             FROM project_photos pp JOIN photo_library pl ON pl.photo_library_id = pp.photo_library_id
             WHERE pp.project_id = ? ORDER BY pp.sort_order, pp.photo_library_id"
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $index => &$row) {
            $row['role'] = (int)$row['before'] ? 'before' : ((int)$row['main'] ? 'full' : 'zoom');
            $row = $this->photoPayload($row);
        }
        return $rows;
    }

    public function availablePhotos(int $projectId): array
    {
        $photos = $this->revision($projectId) === ''
            ? $this->legacyPhotos($projectId) : $this->listForProject($projectId);
        $playlistPhotos = $this->resolvePhotos($this->playlistSources($projectId), []);
        $playlistIds = array_fill_keys(array_column($playlistPhotos, 'photo_library_id'), true);
        foreach ($photos as &$photo) {
            $photo['from_playlist'] = isset($playlistIds[$photo['photo_library_id']]);
        }
        unset($photo);
        $seen = array_fill_keys(array_column($photos, 'photo_library_id'), true);
        foreach ($playlistPhotos as $photo) {
            if (isset($seen[$photo['photo_library_id']])) { continue; }
            $photo['from_playlist'] = true;
            $photo['use'] = false;
            $photo['sort_order'] = $photo['order_index'] = count($photos);
            $photos[] = $photo;
            $seen[$photo['photo_library_id']] = true;
        }
        return $photos;
    }

    private function playlistSources(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.project_id, pi.photo_library_id, pi.image_url AS rel_path, pi.saved_palette_id,
                    CASE WHEN LOWER(pi.analyzer_role) = 'before' THEN 'before' ELSE 'zoom' END AS role,
                    pi.order_index AS sort_order
             FROM playlist_items pi JOIN playlists p ON p.playlist_id = pi.playlist_id
             WHERE p.project_id = ?
             ORDER BY p.playlist_id, pi.order_index, pi.playlist_item_id"
        );
        $stmt->execute([$projectId]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt = $this->pdo->prepare(
            'SELECT hero_image_id AS photo_library_id, hero_image_url AS rel_path
             FROM playlists WHERE project_id = ? ORDER BY playlist_id'
        );
        $stmt->execute([$projectId]);
        return array_merge($sources, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Stage legacy selections without changing any published viewer until Save. */
    public function legacyPhotos(int $projectId): array
    {
        $palettes = $this->palettes($projectId);
        $paletteLinks = [];
        foreach ($palettes as $palette) {
            $paletteLinks[(int)$palette['saved_palette_id']] = (int)$palette['saved_palette_id'];
        }
        $stmt = $this->pdo->prepare(
            "SELECT pp.project_id, pvp.photo_library_id, pvp.rel_path, pvp.photo_type AS role,
                    pv.saved_palette_id, pvp.order_index AS sort_order
             FROM palette_viewer_photos pvp JOIN palette_viewers pv ON pv.palette_viewer_id = pvp.palette_viewer_id
             JOIN project_palettes pp ON pp.saved_palette_id = pv.saved_palette_id
             WHERE pp.project_id = ? AND pv.is_active = 1
             ORDER BY CASE LOWER(pv.format) WHEN 'client' THEN 0 WHEN 'concept' THEN 1 ELSE 2 END,
                      pvp.order_index, pvp.palette_viewer_photo_id"
        );
        $stmt->execute([$projectId]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sources = array_merge($sources, $this->playlistSources($projectId));
        return $this->resolvePhotos($sources, $paletteLinks);
    }

    private function resolvePhotos(array $sources, array $paletteLinks): array
    {
        $paletteColumn = $this->libraryOwnsPalette() ? ', palette_id' : '';
        $resolveId = $this->pdo->prepare("SELECT photo_library_id, rel_path, ai_alt_text, alt_text, updated_at{$paletteColumn} FROM photo_library WHERE photo_library_id = ?");
        $resolvePath = $this->pdo->prepare("SELECT photo_library_id, rel_path, ai_alt_text, alt_text, updated_at{$paletteColumn} FROM photo_library WHERE rel_path = ? ORDER BY photo_library_id LIMIT 1");
        $photos = [];
        foreach ($sources as $source) {
            $path = preg_replace('/[?#].*$/', '', trim((string)($source['rel_path'] ?? ''))) ?? '';
            if (str_starts_with($path, 'http')) {
                $path = (string)(parse_url($path, PHP_URL_PATH) ?? $path);
            }
            $libraryId = (int)($source['photo_library_id'] ?? 0);
            $resolve = $libraryId > 0 ? $resolveId : $resolvePath;
            $resolve->execute([$libraryId > 0 ? $libraryId : $path]);
            $library = $resolve->fetch(PDO::FETCH_ASSOC);
            if (!$library) {
                continue;
            }
            $id = (int)$library['photo_library_id'];
            if (!isset($photos[$id])) {
                $photos[$id] = $this->photoPayload([
                    'photo_library_id' => $id,
                    'rel_path' => $library['rel_path'],
                    'alt_text' => $library['ai_alt_text'] ?: $library['alt_text'],
                    'photo_updated_at' => $library['updated_at'],
                    'role' => $source['role'] ?? 'zoom',
                    'sort_order' => count($photos),
                    'palette_id' => $this->libraryOwnsPalette() ? $library['palette_id'] : ($paletteLinks[(int)($source['saved_palette_id'] ?? 0)] ?? null),
                    'room_id' => $this->roomsAssignable() ? $this->paletteRoomId((int)($source['project_id'] ?? 0), (int)($source['saved_palette_id'] ?? 0)) : null,
                    'use' => true,
                ]);
            }
            if (strtolower((string)($source['role'] ?? '')) === 'before') {
                $photos[$id]['role'] = $photos[$id]['photo_type'] = 'before';
            }
            if (!$this->libraryOwnsPalette() && !$photos[$id]['palette_id']) {
                $photos[$id]['palette_id'] = $paletteLinks[(int)($source['saved_palette_id'] ?? 0)] ?? null;
            }
        }
        $hasMain = [];
        foreach ($photos as &$photo) {
            $id = $photo['palette_id'];
            if ($photo['role'] === 'full' && isset($hasMain[$id])) {
                $photo['role'] = $photo['photo_type'] = 'zoom';
            }
            if ($photo['role'] === 'full') {
                $hasMain[$id] = true;
            }
        }
        unset($photo);
        foreach ($photos as &$photo) {
            if ($photo['role'] !== 'before' && $photo['palette_id'] && !isset($hasMain[$photo['palette_id']])) {
                $photo['role'] = $photo['photo_type'] = 'full';
                $hasMain[$photo['palette_id']] = true;
            }
            $photo['main'] = $photo['role'] === 'full';
            $photo['zoom'] = $photo['role'] === 'zoom';
            $photo['before'] = $photo['role'] === 'before';
            $photo['use'] = $photo['palette_id'] !== null || ($photo['role'] === 'before' && $photo['room_id'] !== null);
        }
        return array_values($photos);
    }

    public function save(int $projectId, array $rows, string $revision): array
    {
        if (!$this->schemaAvailable()) {
            throw new RuntimeException('Project photo migration has not been installed.');
        }
        $project = $this->pdo->prepare('SELECT id FROM projects WHERE id = ?');
        $project->execute([$projectId]);
        if (!$project->fetchColumn()) {
            throw new InvalidArgumentException('Project not found.');
        }
        $allowed = array_map('intval', array_column($this->palettes($projectId), 'saved_palette_id'));
        $libraryOwnsPalette = $this->libraryOwnsPalette();
        $roomsAssignable = $this->roomsAssignable();
        $roomIds = $roomsAssignable ? array_column($this->rooms($projectId), 'id') : [];
        $seen = [];
        $mainCounts = [];
        $afterCounts = [];
        $library = $this->pdo->prepare('SELECT photo_library_id FROM photo_library WHERE photo_library_id = ?');
        foreach ($rows as &$row) {
            if (!is_array($row)) { throw new InvalidArgumentException('Invalid photo.'); }
            $id = (int)($row['photo_library_id'] ?? 0);
            $library->execute([$id]);
            if ($id <= 0 || isset($seen[$id]) || !$library->fetchColumn()) {
                throw new InvalidArgumentException('Every project photo must have a unique, valid Photo Library ID.');
            }
            $seen[$id] = true;
            $flags = array_map(static fn(string $key): int => (int)(bool)($row[$key] ?? false), ['main', 'zoom', 'before']);
            if (array_sum($flags) !== 1) {
                throw new InvalidArgumentException('Choose Main, Zoom, or Before for every photo.');
            }
            $paletteId = (int)($row['palette_id'] ?? 0);
            $roomId = $roomsAssignable ? trim((string)($row['room_id'] ?? '')) : '';
            if ($roomsAssignable && $roomId !== '' && !in_array($roomId, $roomIds, true)) {
                throw new InvalidArgumentException('Photo areas must belong to this project.');
            }
            if ($paletteId && !in_array($paletteId, $allowed, true)) {
                throw new InvalidArgumentException('Photo palettes must belong to this project.');
            }
            if ($roomsAssignable) {
                $row['room_id'] = $roomId ?: ($paletteId ? $this->paletteRoomId($projectId, $paletteId) : null);
                if ($flags[2]) { $paletteId = 0; }
                if (!empty($row['use']) && $flags[2] && !$row['room_id']) {
                    throw new InvalidArgumentException('Choose an area for each enabled Before photo.');
                }
                if (!empty($row['use']) && !$flags[2] && !$paletteId) {
                    throw new InvalidArgumentException('Choose a palette for each enabled Main or Zoom photo.');
                }
            }
            if (!empty($row['use']) && $paletteId) {
                if (!$flags[2]) { $afterCounts[$paletteId] = ($afterCounts[$paletteId] ?? 0) + 1; }
                if ($flags[0]) { $mainCounts[$paletteId] = ($mainCounts[$paletteId] ?? 0) + 1; }
            }
            $row['palette_id'] = $paletteId ?: null;
        }
        unset($row);
        foreach ($afterCounts as $id => $count) {
            if (($mainCounts[$id] ?? 0) !== 1) {
                throw new InvalidArgumentException('Each room/palette with Main or Zoom photos needs exactly one Main photo.');
            }
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            // Serialize saves and compare the current rows without a separate revision table.
            $lock = $this->pdo->prepare('SELECT id FROM projects WHERE id = ?' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$projectId]);
            if ($libraryOwnsPalette) {
                $ids = array_keys($seen);
                sort($ids, SORT_NUMERIC);
                $photoLock = $this->pdo->prepare('SELECT photo_library_id FROM photo_library WHERE photo_library_id = ?' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                foreach ($ids as $id) { $photoLock->execute([$id]); $photoLock->fetchColumn(); }
            }
            if ($this->revision($projectId) !== $revision) {
                throw new RuntimeException('These changes were not saved because the project photos were updated in another window.', 409);
            }
            $delete = $this->pdo->prepare('DELETE FROM project_photos WHERE project_id = ?');
            $delete->execute([$projectId]);
            $assignmentColumn = $libraryOwnsPalette ? 'room_id' : 'palette_id';
            $extraRoomColumn = $roomsAssignable && !$libraryOwnsPalette ? ', room_id' : '';
            $extraRoomValue = $roomsAssignable && !$libraryOwnsPalette ? ', ?' : '';
            $insert = $this->pdo->prepare("INSERT INTO project_photos (project_id, photo_library_id, `use`, {$assignmentColumn}, zoom, main, `before`, sort_order{$extraRoomColumn}) VALUES (?, ?, ?, ?, ?, ?, ?, ?{$extraRoomValue})");
            $updateLibrary = $libraryOwnsPalette ? $this->pdo->prepare('UPDATE photo_library SET palette_id = ?, has_palette = ?, updated_at = CURRENT_TIMESTAMP WHERE photo_library_id = ?') : null;
            $otherRole = $libraryOwnsPalette ? $this->pdo->prepare('SELECT `before` FROM project_photos WHERE photo_library_id = ? AND project_id <> ?') : null;
            foreach (array_values($rows) as $index => $row) {
                if ($libraryOwnsPalette) {
                    $otherRole->execute([(int)$row['photo_library_id'], $projectId]);
                    foreach ($otherRole->fetchAll(PDO::FETCH_COLUMN) as $before) {
                        if ((bool)$before !== (bool)($row['before'] ?? false)) {
                            throw new InvalidArgumentException('A shared photo cannot be Before in one project and After in another.');
                        }
                    }
                    $updateLibrary->execute([$row['palette_id'], $row['palette_id'] ? 1 : 0, (int)$row['photo_library_id']]);
                }
                $values = [$projectId, (int)$row['photo_library_id'], (int)(bool)($row['use'] ?? false),
                    $libraryOwnsPalette ? $row['room_id'] : $row['palette_id'], (int)(bool)($row['zoom'] ?? false), (int)(bool)($row['main'] ?? false), (int)(bool)($row['before'] ?? false), $index];
                if ($roomsAssignable && !$libraryOwnsPalette) { $values[] = $row['room_id']; }
                $insert->execute($values);
            }
            if ($ownsTransaction) { $this->pdo->commit(); }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
        return $this->listForProject($projectId);
    }

    public function viewerProjectId(int $pvId): int
    {
        $stmt = $this->pdo->prepare('SELECT project_id, saved_palette_id FROM palette_viewers WHERE palette_viewer_id = ?');
        $stmt->execute([$pvId]);
        $viewer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$viewer) { return 0; }
        if ((int)($viewer['project_id'] ?? 0) > 0) { return (int)$viewer['project_id']; }
        $paletteId = (int)($viewer['saved_palette_id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT DISTINCT project_id FROM project_palettes WHERE saved_palette_id = ?');
        $stmt->execute([$paletteId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) { return count($ids) === 1 ? (int)$ids[0] : 0; }
        $stmt = $this->pdo->prepare(
            "SELECT p.project_id FROM playlist_items pi JOIN playlists p ON p.playlist_id = pi.playlist_id
             WHERE pi.saved_palette_id = ? AND p.project_id > 0
             UNION
             SELECT p.project_id FROM rex_reservations child
             JOIN rex_reservation_links l ON l.child_reservation_id = child.id AND l.relationship_key = 'viewer'
             JOIN rex_reservations parent ON parent.id = l.parent_reservation_id AND parent.resolver_key = 'playlist_experience'
             JOIN playlists p ON p.playlist_id = parent.resource_id
             WHERE child.resource_type = 'palette_viewer' AND child.resource_id = ? AND p.project_id > 0"
        );
        $stmt->execute([$paletteId, $pvId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : 0;
    }

    /** Project viewer photos must be enabled; null preserves standalone viewers' own photo source. */
    public function forPalette(int $projectId, int $savedPaletteId, bool $painter = false): ?array
    {
        if ($projectId <= 0) { return null; }
        if (!$this->schemaAvailable()) { return []; }
        $final = $this->pdo->prepare('SELECT 1 FROM project_palettes WHERE project_id = ? AND saved_palette_id = ? AND is_final = 1 LIMIT 1');
        $final->execute([$projectId, $savedPaletteId]);
        if (!$final->fetchColumn()) { return []; }
        $roomId = $this->roomsAssignable() ? $this->paletteRoomId($projectId, $savedPaletteId) : null;
        return array_values(array_filter($this->listForProject($projectId),
            static fn(array $photo): bool => $photo['use'] && ($photo['role'] === 'before'
                ? !$painter && ($roomId !== null ? $photo['room_id'] === $roomId : $photo['palette_id'] === $savedPaletteId)
                : $photo['palette_id'] === $savedPaletteId)
        ));
    }

    public function forViewer(int $pvId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT saved_palette_id, format FROM palette_viewers WHERE palette_viewer_id = ?');
        $stmt->execute([$pvId]);
        $viewer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$viewer) { return []; }
        $projectId = $this->viewerProjectId($pvId);
        if ($projectId > 0) {
            return $this->forPalette($projectId, (int)($viewer['saved_palette_id'] ?? 0), strtolower((string)$viewer['format']) === 'painter');
        }
        // An unresolved project association must not fall back to viewer-owned images.
        $linked = $this->pdo->prepare(
            "SELECT 1 FROM project_palettes WHERE saved_palette_id = ?
             UNION SELECT 1 FROM playlist_items pi JOIN playlists p ON p.playlist_id = pi.playlist_id
                 WHERE pi.saved_palette_id = ? AND p.project_id > 0
             UNION SELECT 1 FROM rex_reservations child
                 JOIN rex_reservation_links l ON l.child_reservation_id = child.id AND l.relationship_key = 'viewer'
                 JOIN rex_reservations parent ON parent.id = l.parent_reservation_id AND parent.resolver_key = 'playlist_experience'
                 JOIN playlists p ON p.playlist_id = parent.resource_id
                 WHERE child.resource_type = 'palette_viewer' AND child.resource_id = ? AND p.project_id > 0
             LIMIT 1"
        );
        $paletteId = (int)($viewer['saved_palette_id'] ?? 0);
        $linked->execute([$paletteId, $paletteId, $pvId]);
        return $linked->fetchColumn() ? [] : null;
    }

    private function photoPayload(array $row): array
    {
        $role = strtolower(trim((string)($row['role'] ?? 'zoom')));
        $role = in_array($role, ['main', 'full'], true) ? 'full' : ($role === 'before' ? 'before' : 'zoom');
        $path = trim((string)($row['rel_path'] ?? ''));
        $stamp = strtotime((string)($row['photo_updated_at'] ?? ''));
        $url = $path !== '' && $stamp ? $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . $stamp : $path;
        return [
            'photo_library_id' => (int)$row['photo_library_id'],
            'role' => $role, 'photo_type' => $role,
            'sort_order' => (int)($row['sort_order'] ?? 0), 'order_index' => (int)($row['sort_order'] ?? 0),
            'palette_id' => $this->roomsAssignable() && $role === 'before' ? null : (!empty($row['palette_id']) ? (int)$row['palette_id'] : null),
            'room_id' => $row['room_id'] ?? null,
            'use' => (bool)($row['use'] ?? false),
            'main' => $role === 'full', 'zoom' => $role === 'zoom', 'before' => $role === 'before',
            'rel_path' => $path, 'image_url' => $url, 'url' => $url,
            'alt_text' => $row['alt_text'] ?? null,
            'caption' => $role === 'before' ? 'Before' : null,
        ];
    }
}
