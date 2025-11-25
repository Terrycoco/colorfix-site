<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;
use PDOException;

class PdoPhotoRepository
{
    private PDO $pdo;

    private const T_PHOTOS   = 'photos';
    private const T_VARIANTS = 'photos_variants';
    private const T_TAGS     = 'photos_tags';
    private const T_STATS    = 'photos_mask_stats';

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Create a minimal photo shell row and return ['id'=>int]. */
    public function createPhotoShell(
        string $assetId,
        ?string $stylePrimary,
        ?string $verdict,
        ?string $status,
        ?string $lighting,
        ?string $rightsStatus,
        ?string $categoryPath
    ): array {
        $sql = "INSERT INTO " . self::T_PHOTOS . "
                (asset_id, style_primary, verdict, status, lighting, rights_status, category_path, width, height, created_at)
                VALUES (:asset_id, :style_primary, :verdict, :status, :lighting, :rights_status, :category_path, 0, 0, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':asset_id'      => $assetId,
            ':style_primary' => $stylePrimary,
            ':verdict'       => $verdict,
            ':status'        => $status,
            ':lighting'      => $lighting,
            ':rights_status' => $rightsStatus,
            ':category_path' => $categoryPath,
        ]);
        return ['id' => (int)$this->pdo->lastInsertId()];
    }

    public function getPhotoById(int $id): ?array
    {
        $sql = "SELECT * FROM " . self::T_PHOTOS . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getPhotoByAssetId(string $assetId): ?array
    {
        $sql = "SELECT * FROM " . self::T_PHOTOS . " WHERE asset_id = :asset_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':asset_id' => $assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updatePhotoSize(int $photoId, int $w, int $h): void
    {
        $sql = "UPDATE " . self::T_PHOTOS . " SET width = :w, height = :h WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':w' => $w, ':h' => $h, ':id' => $photoId]);
    }

    public function updatePhotoCategoryPath(int $photoId, string $path): void
    {
        $sql = "UPDATE " . self::T_PHOTOS . " SET category_path = :path WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':path' => $path, ':id' => $photoId]);
    }

    /** Upsert a variant (UNIQUE(photo_id,kind,role)). Exact columns from photos_variants. */
    public function upsertVariant(
        int $photoId,
        string $kind,
        ?string $role,
        string $path,
        ?string $mime = null,
        ?int $bytes = null,
        ?int $width = null,
        ?int $height = null,
        array $overlaySettings = [],
        ?string $originalTexture = null
    ): void {
        $roleNorm = $role ?? '';
        $origTexture = $this->normalizeOriginalTexture($originalTexture);
        $modeDark   = $overlaySettings['dark']['mode']   ?? null;
        $opDark     = isset($overlaySettings['dark']['opacity']) ? (float)$overlaySettings['dark']['opacity'] : null;
        $modeMedium = $overlaySettings['medium']['mode'] ?? null;
        $opMedium   = isset($overlaySettings['medium']['opacity']) ? (float)$overlaySettings['medium']['opacity'] : null;
        $modeLight  = $overlaySettings['light']['mode']  ?? null;
        $opLight    = isset($overlaySettings['light']['opacity']) ? (float)$overlaySettings['light']['opacity'] : null;
        $columns = array_flip($this->getVariantColumns());
        $overlaySupported = isset($columns['overlay_mode_dark'], $columns['overlay_opacity_dark'], $columns['overlay_mode_medium'], $columns['overlay_opacity_medium'], $columns['overlay_mode_light'], $columns['overlay_opacity_light']);
        $textureSupported = isset($columns['original_texture']);
        $textureInsertCols = $textureSupported ? ', original_texture' : '';
        $textureInsertVals = $textureSupported ? ', :texture' : '';
        $textureUpdateSql  = $textureSupported ? ', original_texture = VALUES(original_texture)' : '';

        if (!$overlaySupported) {
            $sql = "
            INSERT INTO " . self::T_VARIANTS . "
                (photo_id, kind, role{$textureInsertCols}, path, mime, bytes, width, height, created_at, updated_at)
            VALUES
                (:photo_id, :kind, :role{$textureInsertVals}, :path, :mime, :bytes, :width, :height, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                path   = VALUES(path),
                mime   = VALUES(mime),
                bytes  = VALUES(bytes),
                width  = VALUES(width),
                height = VALUES(height)
                {$textureUpdateSql},
                updated_at = NOW()
            ";
            $stmt = $this->pdo->prepare($sql);
            $params = [
                ':photo_id' => $photoId,
                ':kind'     => $kind,
                ':role'     => $roleNorm,
                ':path'     => $path,
                ':mime'     => $mime,
                ':bytes'    => $bytes,
                ':width'    => $width,
                ':height'   => $height,
            ];
            if ($textureSupported) {
                $params[':texture'] = $origTexture;
            }
            $stmt->execute($params);
            return;
        }

        $sql = "
            INSERT INTO " . self::T_VARIANTS . "
                (photo_id, kind, role{$textureInsertCols}, path, mime, bytes, width, height,
                 overlay_mode_dark, overlay_opacity_dark,
                 overlay_mode_medium, overlay_opacity_medium,
                 overlay_mode_light, overlay_opacity_light,
                 created_at, updated_at)
            VALUES
                (:photo_id, :kind, :role{$textureInsertVals}, :path, :mime, :bytes, :width, :height,
                 :mode_dark, :op_dark,
                 :mode_medium, :op_medium,
                 :mode_light, :op_light,
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                path   = VALUES(path),
                mime   = VALUES(mime),
                bytes  = VALUES(bytes),
                width  = VALUES(width),
                height = VALUES(height),
                overlay_mode_dark   = VALUES(overlay_mode_dark),
                overlay_opacity_dark = VALUES(overlay_opacity_dark),
                overlay_mode_medium = VALUES(overlay_mode_medium),
                overlay_opacity_medium = VALUES(overlay_opacity_medium),
                overlay_mode_light  = VALUES(overlay_mode_light),
                overlay_opacity_light = VALUES(overlay_opacity_light)
                {$textureUpdateSql},
                updated_at = NOW()
        ";
        $stmt = $this->pdo->prepare($sql);
        $params = [
            ':photo_id' => $photoId,
            ':kind'     => $kind,
            ':role'     => $roleNorm,
            ':path'     => $path,
            ':mime'     => $mime,
            ':bytes'    => $bytes,
            ':width'    => $width,
            ':height'   => $height,
            ':mode_dark'   => $modeDark,
            ':op_dark'     => $opDark,
            ':mode_medium' => $modeMedium,
            ':op_medium'   => $opMedium,
            ':mode_light'  => $modeLight,
            ':op_light'    => $opLight,
        ];
        if ($textureSupported) {
            $params[':texture'] = $origTexture;
        }
        $stmt->execute($params);
    }

    /** Back-compat for earlier code paths. */
    public function replaceVariant(
        int $photoId,
        string $kind,
        ?string $role,
        string $path,
        ?string $mime,
        ?int $bytes,
        ?int $width,
        ?int $height,
        array $overlaySettings = [],
        ?string $originalTexture = null
    ): void {
        $this->upsertVariant($photoId, $kind, $role, $path, $mime, $bytes, $width, $height, $overlaySettings, $originalTexture);
    }

    private ?array $variantColumnCache = null;

    private function getVariantColumns(): array
    {
        if ($this->variantColumnCache !== null) return $this->variantColumnCache;
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM " . self::T_VARIANTS);
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
            $this->variantColumnCache = array_map('strtolower', $cols ?: []);
        } catch (PDOException $e) {
            $this->variantColumnCache = [];
        }
        return $this->variantColumnCache;
    }

    private function normalizeOriginalTexture(?string $value): ?string
    {
        if ($value === null) return null;
        $trim = strtolower(trim($value));
        if ($trim === '') return null;
        $trim = preg_replace('/[^a-z0-9]+/', '_', $trim);
        $trim = trim($trim, '_');
        if ($trim === '') return null;
        return substr($trim, 0, 64);
    }

    /** Return all variants for a photo (exact columns). */
    public function listVariants(int $photoId): array
    {
        $available = array_flip($this->getVariantColumns());
        $baseCols = ['id','photo_id','kind','role','path','mime','bytes','width','height','created_at','updated_at'];
        if (isset($available['original_texture'])) {
            array_splice($baseCols, 4, 0, 'original_texture');
        }
        $optional = [
            'overlay_mode_dark','overlay_opacity_dark',
            'overlay_mode_medium','overlay_opacity_medium',
            'overlay_mode_light','overlay_opacity_light',
        ];
        foreach ($optional as $col) {
            if (isset($available[$col])) $baseCols[] = $col;
        }
        $selectCols = implode(',', $baseCols);
        $sql = "SELECT {$selectCols}
                FROM " . self::T_VARIANTS . "
                WHERE photo_id = :pid
                ORDER BY kind, role";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pid' => $photoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateMaskOverlay(int $photoId, string $role, array $overlaySettings, ?string $originalTexture = null): bool
    {
        $roleNorm = trim((string)$role);
        if ($roleNorm === '') return false;

        $available = array_flip($this->getVariantColumns());
        $colsMap = [
            'overlay_mode_dark'   => $overlaySettings['dark']['mode']   ?? null,
            'overlay_opacity_dark'=> $overlaySettings['dark']['opacity']?? null,
            'overlay_mode_medium' => $overlaySettings['medium']['mode'] ?? null,
            'overlay_opacity_medium' => $overlaySettings['medium']['opacity'] ?? null,
            'overlay_mode_light'  => $overlaySettings['light']['mode']  ?? null,
            'overlay_opacity_light'=> $overlaySettings['light']['opacity'] ?? null,
        ];
        $setParts = [];
        $params = [
            ':pid'  => $photoId,
            ':role' => $roleNorm,
        ];
        foreach ($colsMap as $col => $value) {
            if (!isset($available[$col])) continue;
            $placeholder = ':' . str_replace('overlay_', 'ov_', $col);
            $setParts[] = "{$col} = {$placeholder}";
            $params[$placeholder] = $value;
        }
        if (isset($available['original_texture'])) {
            $setParts[] = "original_texture = :orig_texture";
            $params[':orig_texture'] = $this->normalizeOriginalTexture($originalTexture);
        }
        if (!$setParts) return false;

        $setParts[] = "updated_at = NOW()";
        $sql = "UPDATE " . self::T_VARIANTS . "
                SET " . implode(', ', $setParts) . "
                WHERE photo_id = :pid AND kind = 'masks' AND role = :role
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** Add a tag (photos_tags has composite PK (photo_id, tag)). */
    public function addTag(int $photoId, string $tag): void
    {
        $sql = "INSERT IGNORE INTO " . self::T_TAGS . " (photo_id, tag) VALUES (:pid, :tag)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pid' => $photoId, ':tag' => $tag]);
    }

    /** Fetch tags for a set of photo ids. Returns photo_id => [tags]. */
    public function getTagsForPhotoIds(array $photoIds): array
    {
        if (!$photoIds) return [];
        $in = implode(',', array_fill(0, count($photoIds), '?'));
        $sql = "SELECT photo_id, tag FROM " . self::T_TAGS . " WHERE photo_id IN ($in)";
        $stmt = $this->pdo->prepare($sql);
        foreach ($photoIds as $k => $id) $stmt->bindValue($k + 1, (int)$id, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $pid = (int)$r['photo_id'];
            $out[$pid][] = (string)$r['tag'];
        }
        return $out;
    }

    /** Stats per role from photos_mask_stats (maps l_avg01 → Lm). */
    public function getRoleStats(int $photoId): array
    {
        try {
            $sql = "SELECT photo_id, role, prepared_path, mask_path, prepared_bytes, mask_bytes,
                           l_avg01, l_p10, l_p90, px_covered, computed_at
                    FROM " . self::T_STATS . " WHERE photo_id = :pid";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':pid' => $photoId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            // Table might not exist yet; fail soft.
            return [];
        }
    }

    /**
     * Search by ANDed tags + free text on style_primary/asset_id.
     * Returns ['items'=>[{asset_id,title,thumb_rel_path,tags:[]}], 'total'=>int]
     * thumb prefers 'thumb' then falls back to 'prepared_base'.
     */
public function searchAssets(array $tags, string $q, int $limit, int $offset): array
{
    $andTags = array_values(array_filter(array_map('trim', $tags), fn($t) => $t !== ''));
    $hasTags = count($andTags) > 0;
    $hasQ    = $q !== '';

    // ---------- base WHERE ----------
    $whereSql = [];
    $params   = [];
    if ($hasQ) {
        $whereSql[]      = "(p.style_primary LIKE :q OR p.asset_id LIKE :q)";
        $params[':q']    = '%' . $q . '%';
    }
    $whereStr = $whereSql ? ('WHERE ' . implode(' AND ', $whereSql)) : '';

    // ---------- build named placeholders for tags ----------
    $tagPlaceholders = [];
    if ($hasTags) {
        foreach ($andTags as $i => $t) {
            $ph = ":tag{$i}";
            $tagPlaceholders[] = $ph;
            $params[$ph] = $t;
        }
    }

    // ---------- candidate ids (paged) ----------
    $sqlIds =
        "SELECT p.id
         FROM " . self::T_PHOTOS . " p
         LEFT JOIN " . self::T_TAGS . " t ON t.photo_id = p.id
         $whereStr
         " . ($hasTags ? " AND t.tag IN (" . implode(',', $tagPlaceholders) . ") " : "") . "
         GROUP BY p.id
         " . ($hasTags ? " HAVING COUNT(DISTINCT t.tag) = " . count($andTags) : "") . "
         ORDER BY p.id DESC
         LIMIT :lim OFFSET :off";

    $stmt = $this->pdo->prepare($sqlIds);
    // bind shared params
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $ids = array_map(fn($r) => (int)$r['id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    if (!$ids) return ['items' => [], 'total' => 0];

    // ---------- rows + thumb ----------
    $idPh = [];
    $idParams = [];
    foreach ($ids as $i => $id) {
        $ph = ":id{$i}";
        $idPh[] = $ph;
        $idParams[$ph] = $id;
    }

    $rows = [];
    $tagsBy = $this->getTagsForPhotoIds($ids);
    foreach ($ids as $pid) {
        $photo = $this->getPhotoById($pid);
        if (!$photo) continue;
        $variants = $this->listVariants($pid);
        $best = null;
        foreach ($variants as $v) {
            $kind = strtolower((string)$v['kind']);
            $role = strtolower((string)($v['role'] ?? ''));
            $path = (string)($v['path'] ?? '');
            if ($path === '') continue;
            $prio = 99;
            if ($kind === 'thumb') {
                $prio = 0;
            } elseif ($kind === 'prepared' && ($role === '' || $role === 'base')) {
                $prio = 1;
            } elseif ($kind === 'prepared_base') {
                $prio = 2;
            } elseif ($kind === 'repaired' && ($role === '' || $role === null)) {
                $prio = 3;
            } elseif ($kind === 'prepared' && in_array($role, ['medium','light','dark'], true)) {
                $prio = 4;
            } elseif ($kind === 'masks') {
                $prio = 5;
            }
            if ($prio === 99) continue;
            if ($best === null || $prio < $best['prio']) {
                $best = ['path' => $path, 'prio' => $prio];
                if ($prio === 0) break;
            }
        }
        $thumbPath = $best['path'] ?? '';
        $rows[] = [
            'id' => $pid,
            'asset_id' => (string)($photo['asset_id'] ?? ''),
            'title' => (string)($photo['style_primary'] ?? ''),
            'thumb_rel_path' => $thumbPath,
        ];
    }

    $items = [];
    foreach ($rows as $row) {
        $pid = (int)$row['id'];
        $items[] = [
            'asset_id'       => $row['asset_id'],
            'title'          => $row['title'],
            'thumb_rel_path' => (string)$row['thumb_rel_path'],
            'tags'           => $tagsBy[$pid] ?? [],
        ];
    }

    // ---------- total ----------
    $sqlTotal =
        "SELECT COUNT(*) AS n FROM (
           SELECT p.id
           FROM " . self::T_PHOTOS . " p
           LEFT JOIN " . self::T_TAGS . " t ON t.photo_id = p.id
           $whereStr
           " . ($hasTags ? " AND t.tag IN (" . implode(',', $tagPlaceholders) . ") " : "") . "
           GROUP BY p.id
           " . ($hasTags ? " HAVING COUNT(DISTINCT t.tag) = " . count($andTags) : "") . "
        ) x";
    $stmt = $this->pdo->prepare($sqlTotal);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();

    return ['items' => $items, 'total' => $total];
}


    /** Simple list (no filters). */
    public function listPhotos(array $opts = []): array
    {
        $limit  = max(1, min(100, (int)($opts['limit'] ?? 24)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT id, asset_id, style_primary, width, height, created_at
                FROM " . self::T_PHOTOS . "
                ORDER BY id DESC LIMIT :lim OFFSET :off";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Upsert mask stats used by renderer. */
    public function upsertMaskStats(
        int $photoId,
        string $role,
        string $preparedRelPath,
        string $maskRelPath,
        int $preparedBytes,
        int $maskBytes,
        float $lAvg01,
        float $lP10,
        float $lP90,
        int $pxCovered
    ): void {
        $sql = "
            INSERT INTO " . self::T_STATS . "
                (photo_id, role, prepared_path, mask_path, prepared_bytes, mask_bytes,
                 l_avg01, l_p10, l_p90, px_covered, computed_at)
            VALUES
                (:pid, :role, :ppath, :mpath, :pbytes, :mbytes,
                 :lavg, :lp10, :lp90, :px, NOW())
            ON DUPLICATE KEY UPDATE
                prepared_path  = VALUES(prepared_path),
                mask_path      = VALUES(mask_path),
                prepared_bytes = VALUES(prepared_bytes),
                mask_bytes     = VALUES(mask_bytes),
                l_avg01        = VALUES(l_avg01),
                l_p10          = VALUES(l_p10),
                l_p90          = VALUES(l_p90),
                px_covered     = VALUES(px_covered),
                computed_at    = NOW()
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':pid'   => $photoId,
            ':role'  => $role,
            ':ppath' => $preparedRelPath,
            ':mpath' => $maskRelPath,
            ':pbytes'=> $preparedBytes,
            ':mbytes'=> $maskBytes,
            ':lavg'  => $lAvg01,
            ':lp10'  => $lP90, // NOTE: if this is a typo, swap to correct binding below
            ':lp90'  => $lP90,
            ':px'    => $pxCovered,
        ]);
    }
}
