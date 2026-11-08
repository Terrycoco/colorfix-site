<?php
declare(strict_types=1);

namespace App\PHOTOS\Repos;

use PDO;
use RuntimeException;

/** Reference inventory for lifecycle operations, not upload/replacement. */
final class PdoPhotoLifecycleRepository
{
    // Include compatibility consumers while their endpoints and tables remain supported.
    private const IDS = [
        'playlist_items' => ['photo_library_id'],
        'playlists' => ['hero_image_id'],
        'playlist_sets' => ['cover_photo_library_id'],
        'project_photos' => ['photo_library_id'],
        'palette_viewer_photos' => ['photo_library_id'],
        'playlist_instance_set_items' => ['photo_library_id'],
        'articles' => ['hero_asset_id', 'hero_mobile_asset_id'],
        'article_sections' => ['asset_id'],
        'photo_group_items' => ['photo_library_id'],
        'photos_artwork' => ['photo_library_id'],
        'photo_alt_text_jobs' => ['photo_library_id'],
        'asset_library' => ['legacy_photo_library_id'],
    ];
    private const PATHS = [
        'playlist_items' => ['image_url'], 'playlists' => ['hero_image_url'],
        'palette_viewer_photos' => ['rel_path'],
        'playlist_instances' => ['intro_image_url', 'share_image_url'],
        'playlist_instance_set_items' => ['photo_url'],
        'asset_library' => ['rel_path'], 'items' => ['image_url'], 'sql_queries' => ['image_url'],
        'pub_assets' => ['file_path', 'url', 'thumbnail_file_path', 'thumbnail_url'],
    ];
    // Temporary retirement interlock only. These tables are never reassigned or written.
    private const RETIRED = ['saved_palette_set_photos','saved_palette_photos'];
    private ?array $schema = null;
    public function __construct(private PDO $pdo) {}

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM photo_library WHERE photo_library_id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function schema(): array
    {
        if ($this->schema !== null) { return $this->schema; }
        $schema = [];
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $rows = $this->pdo->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $schema[$r['TABLE_NAME']]['columns'][] = $r['COLUMN_NAME'];
                if ($r['COLUMN_KEY'] === 'PRI') { $schema[$r['TABLE_NAME']]['pk'][] = $r['COLUMN_NAME']; }
            }
        } else {
            foreach ($this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                foreach ($this->pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $schema[$table]['columns'][] = $r['name'];
                    if ($r['pk']) { $schema[$table]['pk'][] = $r['name']; }
                }
            }
        }
        // A new FK consumer must be deliberately supported before lifecycle mutations can proceed.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $refs = $this->pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='photo_library'");
            foreach ($refs as $r) {
                if (!in_array($r['TABLE_NAME'], self::RETIRED, true) && !in_array($r['COLUMN_NAME'], self::IDS[$r['TABLE_NAME']] ?? [], true)) {
                    throw new \DomainException('Unsupported Photo Library dependency: ' . $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']);
                }
            }
        }
        return $this->schema = $schema;
    }

    private function quote(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new RuntimeException('Invalid database identifier'); }
        return '`' . $name . '`';
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->schema()[$table]['columns'] ?? [], true);
    }

    public static function path(string $value): string
    {
        if (str_starts_with($value, 'photo:')) {
            $value = explode('|', $value, 2)[1] ?? '';
        }
        $path = (string)(parse_url(trim($value), PHP_URL_PATH) ?: '');
        if ($path === '') { return ''; }
        $parts = [];
        foreach (explode('/', rawurldecode($path)) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { array_pop($parts); } else { $parts[] = $part; }
        }
        return '/' . implode('/', $parts);
    }

    private static function sameFile(string $value, string $path, ?string $root): bool
    {
        if (!$root || $value === '' || $path === '') { return false; }
        $root = rtrim($root, '/');
        $expected = realpath($root . $path);
        $candidate = str_starts_with($value, $root . '/') ? $value : $root . self::path($value);
        return $expected && realpath($candidate) === $expected;
    }

    private static function matches(string $value, int $id, string $path): bool
    {
        if ($id > 0 && preg_match('/^photo:' . $id . '(?:\||$)/D', $value)) { return true; }
        return $path !== '' && self::path($value) === $path;
    }

    private function matchingPaths(string $table, string $column, int $id, string $path): array
    {
        // Use bound LIKE candidates; verify exact normalized paths before accepting a match.
        $where = [$this->quote($column) . ' = ?', $this->quote($column) . ' LIKE ?'];
        $args = ['photo:' . $id, 'photo:' . $id . '|%'];
        if ($path !== '') {
            $where[] = $this->quote($column) . " LIKE ? ESCAPE '!'";
            $args[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], basename($path)) . '%';
        }
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->quote($table) . ' WHERE ' . implode(' OR ', $where));
        $stmt->execute($args);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn(array $r): bool => self::matches((string)$r[$column], $id, $path)));
    }

    private function usage(string $table, array $row, string $column): array
    {
        $key = $this->schema()[$table]['pk'][0] ?? null;
        return ['usage_type' => $table, 'ref_id' => $key ? (int)$row[$key] : null,
            'ref_title' => (string)($row['title'] ?? $row['display_title'] ?? ''),
            'label' => $table . ' / ' . $column, 'detail' => 'Linked record must be retained or reassigned',
            'admin_path' => '/admin/photo-library'];
    }

    /** Adds dependencies missing from the historical UNION, without hiding core-table query failures. */
    public function supplementalUsages(array $photo): array
    {
        $id = (int)$photo['photo_library_id'];
        $path = self::path((string)($photo['rel_path'] ?? ''));
        $result = [];
        foreach (['photos_artwork', 'photo_alt_text_jobs', 'asset_library'] as $table) {
            foreach (self::IDS[$table] as $column) {
                if (!$this->hasColumn($table, $column)) { continue; }
                $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->quote($table) . ' WHERE ' . $this->quote($column) . ' = ?');
                $stmt->execute([$id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $result[] = $this->usage($table, $row, $column); }
            }
        }
        foreach (['asset_library', 'items', 'sql_queries', 'pub_assets', 'playlist_instance_set_items'] as $table) {
            foreach (self::PATHS[$table] as $column) {
                if (!$this->hasColumn($table, $column)) { continue; }
                foreach ($this->matchingPaths($table, $column, $id, $path) as $row) { $result[] = $this->usage($table, $row, $column); }
            }
        }
        foreach ($this->retirementDependencies($id, $path) as [$table,$row]) { $result[]=$this->usage($table,$row,'Retired relationship awaiting approved database cleanup'); }
        foreach ($this->assetMetadataMatches($id, $path) as $row) { $result[] = $this->usage('asset_library', $row, 'metadata_json'); }
        foreach ($this->sourceRows($id) as [$table, $row]) { $result[] = $this->usage($table, $row, 'source_id'); }
        foreach ($this->productionInputs($id, $path) as [$table, $row]) { $result[] = $this->usage($table, $row, 'production inputs'); }
        if (!empty($photo['palette_id'])) {
            $result[] = ['usage_type' => 'saved_palette_main', 'ref_id' => (int)$photo['palette_id'],
                'ref_title' => '', 'label' => 'Main photo for saved palette #' . $photo['palette_id'],
                'detail' => 'Unlink the direct palette association before deleting', 'admin_path' => '/admin/saved-palettes'];
        }
        return $result;
    }

    private function assetMetadataMatches(int $id, string $path, ?string $root = null): array
    {
        if (!$this->hasColumn('asset_library', 'metadata_json')) { return []; }
        $result = [];
        $stmt = $this->pdo->query('SELECT * FROM asset_library WHERE metadata_json IS NOT NULL');
        foreach ($stmt as $row) {
            $meta = json_decode((string)$row['metadata_json'], true);
            foreach (['', 'before', 'after'] as $side) {
                $ref = $side === '' ? $meta : ($meta[$side] ?? []);
                $pathMatches = false;
                foreach (['rel_path', 'file_path'] as $key) {
                    $value = (string)($ref[$key] ?? '');
                    $pathMatches = $pathMatches || ($path !== '' && self::path($value) === $path) || self::sameFile($value, $path, $root);
                }
                if (($id > 0 && (int)($ref['photo_library_id'] ?? 0) === $id) || $pathMatches) {
                    $result[] = $row;
                    break;
                }
            }
        }
        return $result;
    }

    /** Database-only reassignment; the service owns the transaction and conflicts. */
    public function reassign(int $from, int $to, string $oldPath, string $newPath): void
    {
        foreach ($this->sourceRows($from) as [$table, $row]) { $this->updateRow($table, $row, ['source_id' => $to]); }
        foreach (self::IDS as $table => $columns) {
            foreach ($columns as $column) {
                if (!$this->hasColumn($table, $column)) { continue; }
                $sql = 'UPDATE ' . $this->quote($table) . ' SET ' . $this->quote($column) . ' = ? WHERE ' . $this->quote($column) . ' = ?';
                $this->pdo->prepare($sql)->execute([$to, $from]);
            }
        }
        foreach (self::PATHS as $table => $columns) {
            foreach ($columns as $column) {
                if (!$this->hasColumn($table, $column)) { continue; }
                foreach ($this->matchingPaths($table, $column, $from, $oldPath) as $row) {
                    $old = (string)$row[$column];
                    $value = str_starts_with($old, 'photo:') ? 'photo:' . $to : $newPath;
                    $suffix = '';
                    $cached = explode('|', $old, 2)[1] ?? $old;
                    if (preg_match('/[?#].*$/s', $cached, $match)) { $suffix = $match[0]; }
                    if (str_starts_with($old, 'photo:') && str_contains($old, '|')) { $value .= '|' . $newPath . $suffix; }
                    elseif (!str_starts_with($old, 'photo:')) { $value .= $suffix; }
                    $this->updateRow($table, $row, [$column => $value]);
                }
            }
        }
        foreach ($this->assetMetadataMatches($from, $oldPath) as $row) {
            $meta = json_decode((string)$row['metadata_json'], true);
            if ((int)($meta['photo_library_id'] ?? 0) === $from) { $meta['photo_library_id'] = $to; }
            foreach (['rel_path', 'file_path'] as $key) {
                if ($oldPath !== '' && self::path((string)($meta[$key] ?? '')) === $oldPath) { $meta[$key] = $newPath; }
            }
            foreach (['before', 'after'] as $side) {
                if ((int)($meta[$side]['photo_library_id'] ?? 0) === $from) { $meta[$side]['photo_library_id'] = $to; }
                foreach (['rel_path', 'file_path'] as $key) {
                    if ($oldPath !== '' && self::path((string)($meta[$side][$key] ?? '')) === $oldPath) { $meta[$side][$key] = $newPath; }
                }
            }
            $this->updateRow('asset_library', $row, ['metadata_json' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
    }

    private function updateRow(string $table, array $row, array $fields): void
    {
        $keys = $this->schema()[$table]['pk'] ?? [];
        // Old compatibility fixtures may have no primary key. Matching the original row is safe and bounded.
        if (!$keys) { $keys = array_keys($row); }
        $sets = []; $where = []; $args = [];
        foreach ($fields as $key => $value) { $sets[] = $this->quote($key) . ' = ?'; $args[] = $value; }
        foreach ($keys as $key) {
            $where[] = $this->quote($key) . ($row[$key] === null ? ' IS NULL' : ' = ?');
            if ($row[$key] !== null) { $args[] = $row[$key]; }
        }
        $this->pdo->prepare('UPDATE ' . $this->quote($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $where))->execute($args);
    }

    public function assertMergeConflicts(array $from, array $to): void
    {
        if ($this->retirementDependencies((int)$from['photo_library_id'], self::path((string)$from['rel_path']))) {
            throw new \DomainException('Photo has retired palette-photo relationships awaiting approved database cleanup. No legacy records were migrated or deleted.');
        }
        if ($this->productionInputs((int)$from['photo_library_id'], self::path((string)$from['rel_path']))) {
            throw new \DomainException('Photo is referenced by filed PUB production inputs. Resolve or replace those inputs through PUB before merging.');
        }
        if (trim((string)$to['rel_path']) === '') { throw new \DomainException('Keeper photo has no image path'); }
        if (!empty($from['palette_id']) && !empty($to['palette_id']) && (int)$from['palette_id'] !== (int)$to['palette_id']) {
            throw new \DomainException('Photos have conflicting direct palette associations. Unlink one explicitly before merging.');
        }
        foreach (['photos_artwork', 'photo_alt_text_jobs', 'asset_library'] as $table) {
            $col = self::IDS[$table][0];
            if (!$this->hasColumn($table, $col)) { continue; }
            $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT ' . $this->quote($col) . ') FROM ' . $this->quote($table) . ' WHERE ' . $this->quote($col) . ' IN (?, ?)');
            $stmt->execute([(int)$from['photo_library_id'], (int)$to['photo_library_id']]);
            if ((int)$stmt->fetchColumn() > 1) { throw new \DomainException('Conflicting ' . $table . ' records. Resolve both records explicitly before merging.'); }
        }
        foreach (['project_photos' => 'project_id', 'photo_group_items' => 'group_id'] as $table => $group) {
            if (!$this->hasColumn($table, $group)) { continue; }
            $stmt = $this->pdo->prepare('SELECT ' . $this->quote($group) . ' FROM ' . $this->quote($table) . ' WHERE photo_library_id IN (?, ?) GROUP BY ' . $this->quote($group) . ' HAVING COUNT(DISTINCT photo_library_id) > 1');
            $stmt->execute([(int)$from['photo_library_id'], (int)$to['photo_library_id']]);
            if ($stmt->fetchColumn() !== false) { throw new \DomainException('Both photos have a ' . $table . ' association in the same group. Resolve the conflicting associations before merging.'); }
        }
        if (!empty($from['asset_library_id']) && !empty($to['asset_library_id']) && (int)$from['asset_library_id'] !== (int)$to['asset_library_id']) {
            throw new \DomainException('Photos have different asset associations. Resolve them explicitly before merging.');
        }
    }

    private function retirementDependencies(int $id, string $path): array
    {
        $refs=[];
        foreach (self::RETIRED as $table) {
            $rows=[];
            if ($id > 0 && $this->hasColumn($table,'photo_library_id')) {
                $stmt=$this->pdo->prepare('SELECT * FROM '.$this->quote($table).' WHERE photo_library_id=?');
                $stmt->execute([$id]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if ($path !== '' && $this->hasColumn($table,'rel_path')) { $rows=array_merge($rows,$this->matchingPaths($table,'rel_path',$id,$path)); }
            foreach (array_unique(array_map('serialize',$rows)) as $row) { $refs[]=[$table,unserialize($row,['allowed_classes'=>false])]; }
        }
        return $refs;
    }

    private function sourceRows(int $id): array
    {
        if ($id <= 0) { return []; }
        $rows = [];
        foreach (['asset_library', 'pub_assets'] as $table) {
            if (!$this->hasColumn($table, 'source_type') || !$this->hasColumn($table, 'source_id')) { continue; }
            $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->quote($table) . " WHERE source_type = 'photo_library' AND source_id = ?");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $rows[] = [$table, $row]; }
        }
        return $rows;
    }

    /** Filed render inputs are owned by PUB; guard them rather than invalidate production signatures. */
    private function productionInputs(int $id, string $path, ?string $root = null): array
    {
        $matches = [];
        $contains = function ($value) use (&$contains, $id, $path, $root): bool {
            if (!is_array($value)) { return false; }
            foreach ($value as $key => $item) {
                if ($key === 'photo_library_id' && $id > 0 && (int)$item === $id) { return true; }
                if (is_string($item) && (self::matches($item, $id, $path) || self::sameFile($item, $path, $root))) { return true; }
                if (is_array($item) && $contains($item)) { return true; }
            }
            return false;
        };
        foreach (['pub_asset_orders' => 'ingredients', 'pub_video_jobs' => 'props_json', 'pub_render_jobs' => 'props_json'] as $table => $column) {
            if (!$this->hasColumn($table, $column)) { continue; }
            foreach ($this->pdo->query('SELECT * FROM ' . $this->quote($table) . ' WHERE ' . $this->quote($column) . ' IS NOT NULL') as $row) {
                if ($contains(json_decode((string)$row[$column], true))) { $matches[] = [$table, $row]; }
            }
        }
        return $matches;
    }

    public function detachAsset(int $assetId): int
    {
        if (!$this->hasColumn('photo_library', 'asset_library_id')) { return 0; }
        $stmt = $this->pdo->prepare('UPDATE photo_library SET asset_library_id = NULL WHERE asset_library_id = ?');
        $stmt->execute([$assetId]);
        return $stmt->rowCount();
    }

    public function fileReferences(string $path, ?string $root = null): array
    {
        $refs = [];
        foreach (['photo_library' => ['rel_path'], ...self::PATHS, ...array_fill_keys(self::RETIRED,['rel_path'])] as $table => $columns) {
            foreach ($columns as $column) {
                if (!$this->hasColumn($table, $column)) { continue; }
                $stmt = $this->pdo->query('SELECT * FROM ' . $this->quote($table) . ' WHERE ' . $this->quote($column) . ' IS NOT NULL');
                foreach ($stmt as $row) {
                    $value = (string)$row[$column];
                    $sameFile = self::sameFile($value, $path, $root);
                    if (self::matches($value, 0, $path) || $sameFile) { $refs[] = $this->usage($table, $row, $column); }
                }
            }
        }
        foreach ($this->assetMetadataMatches(0, $path, $root) as $row) { $refs[] = $this->usage('asset_library', $row, 'metadata_json'); }
        foreach ($this->productionInputs(0, $path, $root) as [$table, $row]) { $refs[] = $this->usage($table, $row, 'production inputs'); }
        return $refs;
    }
}
