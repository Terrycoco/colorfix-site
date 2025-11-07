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
        ?string $rightsStatus
    ): array {
        $sql = "INSERT INTO " . self::T_PHOTOS . "
                (asset_id, style_primary, verdict, status, lighting, rights_status, width, height, created_at)
                VALUES (:asset_id, :style_primary, :verdict, :status, :lighting, :rights_status, 0, 0, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':asset_id'      => $assetId,
            ':style_primary' => $stylePrimary,
            ':verdict'       => $verdict,
            ':status'        => $status,
            ':lighting'      => $lighting,
            ':rights_status' => $rightsStatus,
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

    /** Upsert a variant (UNIQUE(photo_id,kind,role)). Exact columns from photos_variants. */
    public function upsertVariant(
        int $photoId,
        string $kind,
        ?string $role,
        string $path,
        ?string $mime = null,
        ?int $bytes = null,
        ?int $width = null,
        ?int $height = null
    ): void {
        $roleNorm = $role ?? '';
        $sql = "
            INSERT INTO " . self::T_VARIANTS . "
                (photo_id, kind, role, path, mime, bytes, width, height, created_at, updated_at)
            VALUES
                (:photo_id, :kind, :role, :path, :mime, :bytes, :width, :height, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                path   = VALUES(path),
                mime   = VALUES(mime),
                bytes  = VALUES(bytes),
                width  = VALUES(width),
                height = VALUES(height),
                updated_at = NOW()
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':photo_id' => $photoId,
            ':kind'     => $kind,
            ':role'     => $roleNorm,
            ':path'     => $path,
            ':mime'     => $mime,
            ':bytes'    => $bytes,
            ':width'    => $width,
            ':height'   => $height,
        ]);
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
        ?int $height
    ): void {
        $this->upsertVariant($photoId, $kind, $role, $path, $mime, $bytes, $width, $height);
    }

    /** Return all variants for a photo (exact columns). */
    public function listVariants(int $photoId): array
    {
        $sql = "SELECT id, photo_id, kind, role, path, mime, bytes, width, height, created_at, updated_at
                FROM " . self::T_VARIANTS . "
                WHERE photo_id = :pid
                ORDER BY kind, role";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pid' => $photoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

    $sqlRows = "
        SELECT p.id, p.asset_id, p.style_primary AS title,
               vthumb.path AS thumb_rel_path
        FROM " . self::T_PHOTOS . " p
        LEFT JOIN " . self::T_VARIANTS . " vthumb
          ON vthumb.photo_id = p.id AND vthumb.kind = 'thumb'
        WHERE p.id IN (" . implode(',', $idPh) . ")
        ORDER BY p.id DESC";
    $stmt = $this->pdo->prepare($sqlRows);
    foreach ($idParams as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sqlPrep = "SELECT photo_id, path FROM " . self::T_VARIANTS . "
                WHERE kind = 'prepared_base' AND photo_id IN (" . implode(',', $idPh) . ")";
    $stmtP = $this->pdo->prepare($sqlPrep);
    foreach ($idParams as $k => $v) $stmtP->bindValue($k, $v, PDO::PARAM_INT);
    $stmtP->execute();
    $prepRows = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $prepByPhoto = [];
    foreach ($prepRows as $pr) $prepByPhoto[(int)$pr['photo_id']] = (string)$pr['path'];

    $tagsBy = $this->getTagsForPhotoIds($ids);

    $items = [];
    foreach ($rows as $r) {
        $pid = (int)$r['id'];
        $thumb = $r['thumb_rel_path'] ?: ($prepByPhoto[$pid] ?? '');
        $items[] = [
            'asset_id'       => (string)$r['asset_id'],
            'title'          => (string)($r['title'] ?? ''),
            'thumb_rel_path' => $thumb,
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
