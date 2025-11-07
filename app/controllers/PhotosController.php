<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repos\PdoPhotoRepository;
use App\Services\PhotoRenderingService;
use App\Services\PhotosUploadService;
use PDO;
use RuntimeException;

class PhotosController
{
    public function __construct(private PDO $pdo) {}

    /** GET asset details: repaired/prepared URLs, prepared_tiers, masks[], and precomputed Lm per role. */
    public function getAsset(array $q): array
    {
        $assetId = trim((string)($q['asset_id'] ?? ''));
        if ($assetId === '') throw new RuntimeException('asset_id required');

        $repo  = new PdoPhotoRepository($this->pdo);
        $photo = $repo->getPhotoByAssetId($assetId);
        if (!$photo) throw new RuntimeException("asset not found: $assetId");

        $variants = $repo->listVariants((int)$photo['id']);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $origin = $scheme . '://' . $host;

        $toUrl = function (string $rel) use ($origin): string {
            $rel = trim($rel);
            if ($rel === '') return '';
            if (preg_match('~^https?://~i', $rel)) return $rel;
            return $origin . '/' . ltrim($rel, '/');
        };

        // Support both legacy and new variant naming
        $repairedUrl   = null;
        $preparedUrl   = null; // legacy single
        $preparedTiers = ['dark' => null, 'medium' => null, 'light' => null];
        $masks         = [];

        foreach ($variants as $v) {
            $kind = (string)$v['kind'];
            $role = (string)($v['role'] ?? '');
            $rel  = (string)$v['path'];

            // Repaired base — legacy and new
            if (($kind === 'repaired_base') || ($kind === 'repaired' && $role === '')) {
                $repairedUrl = $toUrl($rel);
            }

            // Prepared base — legacy and new
            if (($kind === 'prepared_base') || ($kind === 'prepared' && $role === '')) {
                $preparedUrl = $toUrl($rel);
            }

            // Prepared tiers (new)
            if ($kind === 'prepared' && in_array($role, ['dark','medium','light'], true)) {
                $preparedTiers[$role] = $toUrl($rel);
            }

            // Masks — support both "mask:<role>" (legacy) and kind='masks' role='<role>'
            if ($kind === 'masks' && $role !== '') {
                $masks[] = ['role' => $role, 'url' => $toUrl($rel)];
            } elseif (str_starts_with($kind, 'mask:')) {
                $roleLegacy = (string)($v['role'] ?? substr($kind, 5));
                $masks[] = ['role' => $roleLegacy, 'url' => $toUrl($rel)];
            }
        }

        // Map stats → precomputed[role].Lm
        $precomputed = [];
        foreach ($repo->getRoleStats((int)$photo['id']) as $row) {
            $precomputed[(string)$row['role']] = ['Lm' => (float)$row['l_avg01']];
        }

        return [
            'asset_id'       => (string)$photo['asset_id'],
            'width'          => (int)$photo['width'],
            'height'         => (int)$photo['height'],
            'repaired_url'   => $repairedUrl,
            'prepared_url'   => $preparedUrl,     // legacy single, may be null
            'prepared_tiers' => $preparedTiers,   // {dark|null, medium|null, light|null}
            'masks'          => $masks,
            'precomputed'    => $precomputed ?: null,
        ];
    }

    /** GET search: by tags (AND) + free text; returns absolute thumb URLs. */
    public function search(array $q): array
    {
        $tags   = (array)($q['tags'] ?? []);
        $qtext  = (string)($q['q'] ?? '');
        $limit  = max(1, min(100, (int)($q['limit'] ?? 24)));
        $offset = max(0, (int)($q['offset'] ?? 0));

        $repo = new PdoPhotoRepository($this->pdo);
        $res  = $repo->searchAssets($tags, $qtext, $limit, $offset);

        // Build absolute URL base from this request (https/http + host)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $origin = $scheme . '://' . $host;

        $items = [];
        foreach ((array)($res['items'] ?? []) as $row) {
            $rel = (string)($row['thumb_rel_path'] ?? '');
            $items[] = [
                'asset_id'       => (string)$row['asset_id'],
                'title'          => (string)($row['title'] ?? ''),
                'thumb_rel_path' => $rel,
                'thumb_url'      => $rel !== '' && !preg_match('~^https?://~i', $rel)
                                    ? $origin . '/' . ltrim($rel, '/')
                                    : $rel,
                'tags'           => (array)($row['tags'] ?? []),
            ];
        }

        return [
            'items' => $items,
            'total' => (int)($res['total'] ?? 0),
        ];
    }

    /** POST recalc Lm stats for roles; service reads files, repo persists. */
    public function recalcLm(array $post): array
    {
        $assetId = trim((string)($post['asset_id'] ?? ''));
        if ($assetId === '') throw new RuntimeException('asset_id required');

        $roles = $post['roles'] ?? null;
        if ($roles !== null && !is_array($roles)) {
            throw new RuntimeException('roles must be an array of strings');
        }

        $repo = new PdoPhotoRepository($this->pdo);
        $svc  = new PhotoRenderingService($repo);
        $updated = $svc->recalcLmForRoles($assetId, $roles ?: null);

        return ['ok' => true, 'asset_id' => $assetId, 'updated' => $updated];
    }

    /**
     * POST upload: supports legacy prepared_base + masks[], and NEW prepared trio:
     *   prepared_dark | prepared_medium | prepared_light
     *
     * Saves under: /photos/YYYY/YYYY-MM/ASSET_ID/{prepared,masks,thumb,renders}
     */
    public function upload(array $post, array $files): array
    {
        $doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $photosRoot = $doc . '/photos';

        // Helpers
        $mkpath = function(string $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: $dir");
            }
        };
        $probe = function(string $path): array {
            $i = @getimagesize($path);
            if (!$i) throw new RuntimeException("Not an image: $path");
            return ['w'=>(int)$i[0],'h'=>(int)$i[1],'mime'=>(string)($i['mime'] ?? ''),'bytes'=>(int)@filesize($path)];
        };
        $roleFrom = function(string $name): string {
            $base = strtolower(pathinfo($name, PATHINFO_FILENAME));
            $r = preg_replace('/[^a-z0-9\-]+/', '-', $base);
            return $r === '' ? 'role' : $r;
        };
        $relPath = function(string $abs) use ($photosRoot): string {
            return str_starts_with($abs, $photosRoot) ? substr($abs, strlen($photosRoot)) : $abs;
        };
        $makeAssetId = function(): string {
            $r = random_bytes(4);
            return 'PHO_' . substr(strtoupper(base_convert(bin2hex($r), 16, 36)), 0, 6);
        };

        $assetIdIn = trim((string)($post['asset_id'] ?? ''));
        $style     = $post['style']     ?? null;
        $verdict   = $post['verdict']   ?? null;
        $status    = $post['status']    ?? null;
        $lighting  = $post['lighting']  ?? null;
        $rights    = $post['rights']    ?? null;
        $tagsCsv   = trim((string)($post['tags'] ?? ''));

        $hasPreparedSingle = isset($files['prepared_base']) && $files['prepared_base']['error'] === UPLOAD_ERR_OK;
        $hasMasks          = isset($files['masks']) && is_array($files['masks']['name']);

        $hasDark   = !empty($files['prepared_dark'])   && $files['prepared_dark']['error']   === UPLOAD_ERR_OK;
        $hasMedium = !empty($files['prepared_medium']) && $files['prepared_medium']['error'] === UPLOAD_ERR_OK;
        $hasLight  = !empty($files['prepared_light'])  && $files['prepared_light']['error']  === UPLOAD_ERR_OK;

        if (!$assetIdIn && !$hasPreparedSingle && !$hasMasks && !$hasDark && !$hasMedium && !$hasLight) {
            throw new RuntimeException('Upload requires prepared_base and/or prepared_dark/medium/light and/or masks (or provide existing asset_id).');
        }

        $repo = new PdoPhotoRepository($this->pdo);
        $svc  = new PhotosUploadService($repo, $photosRoot);

        // Resolve/create photo
        if ($assetIdIn !== '') {
            $photo = $repo->getPhotoByAssetId($assetIdIn);
            if (!$photo) throw new RuntimeException("asset_id not found: {$assetIdIn}");
            $assetId = (string)$photo['asset_id'];
            $photoId = (int)$photo['id'];
        } else {
            $assetId = $makeAssetId();
            $insert  = $repo->createPhotoShell($assetId, $style ?: null, $verdict ?: null, $status ?: null, $lighting ?: null, $rights ?: null);
            $photo   = $repo->getPhotoById((int)$insert['id']);
            $photoId = (int)$photo['id'];
        }

        // Folder /photos/YYYY/YYYY-MM/ASSET_ID
        $now = new \DateTime('now');
        $y   = $now->format('Y');
        $ym  = $now->format('Y-m');
        $assetDir = "{$photosRoot}/{$y}/{$ym}/{$assetId}";
        $mkpath("{$assetDir}/prepared");
        $mkpath("{$assetDir}/masks");
        $mkpath("{$assetDir}/thumb");
        $mkpath("{$assetDir}/renders");

        $touched = [];
        $baseW = (int)($photo['width'] ?? 0);
        $baseH = (int)($photo['height'] ?? 0);
        $setSizeIfEmpty = function(int $w, int $h) use (&$baseW, &$baseH, $repo, $photoId) {
            if (!$baseW || !$baseH) {
                $baseW = $w; $baseH = $h;
                $repo->updatePhotoSize($photoId, $baseW, $baseH);
            }
        };

        // ----- NEW: accept any of the trio via service -----
        if ($hasDark) {
            $res = $svc->savePreparedTier($photoId, 'dark', $files['prepared_dark']);
            $touched[] = ['kind'=>'prepared', 'role'=>'dark', 'w'=>$res['width'], 'h'=>$res['height']];
            if ($res['width'] && $res['height']) $setSizeIfEmpty((int)$res['width'], (int)$res['height']);
        }
        if ($hasMedium) {
            $res = $svc->savePreparedTier($photoId, 'medium', $files['prepared_medium']);
            $touched[] = ['kind'=>'prepared', 'role'=>'medium', 'w'=>$res['width'], 'h'=>$res['height']];
            if ($res['width'] && $res['height']) $setSizeIfEmpty((int)$res['width'], (int)$res['height']);
        }
        if ($hasLight) {
            $res = $svc->savePreparedTier($photoId, 'light', $files['prepared_light']);
            $touched[] = ['kind'=>'prepared', 'role'=>'light', 'w'=>$res['width'], 'h'=>$res['height']];
            if ($res['width'] && $res['height']) $setSizeIfEmpty((int)$res['width'], (int)$res['height']);
        }

        // ----- Legacy single prepared_base (optional) -----
        if ($hasPreparedSingle) {
            $res = $svc->saveBase($photoId, 'prepared', $files['prepared_base']);
            $touched[] = ['kind'=>'prepared', 'role'=>'', 'w'=>$res['width'], 'h'=>$res['height']];
            if ($res['width'] && $res['height']) $setSizeIfEmpty((int)$res['width'], (int)$res['height']);
        }

        // ----- masks[] (use service; role from filename) -----
        if ($hasMasks) {
            $count = count($files['masks']['name']);
            if (!$baseW || !$baseH) throw new RuntimeException('Upload prepared image(s) first; masks must match size.');
            for ($i=0; $i<$count; $i++) {
                if (($files['masks']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $tmp  = $files['masks']['tmp_name'][$i];
                $name = $files['masks']['name'][$i];
                if (!is_uploaded_file($tmp)) continue;

                $role = $roleFrom($name);

                // Repackage this single file into a $_FILES-like array for the service
                $one = [
                    'tmp_name' => $tmp,
                    'name'     => $name,
                    'type'     => 'image/png',
                    'error'    => UPLOAD_ERR_OK,
                    'size'     => @filesize($tmp) ?: null,
                ];

                $res = $svc->saveMask($photoId, $role, $one);
                $touched[] = ['kind'=>'masks', 'role'=>$role, 'w'=>$res['width'], 'h'=>$res['height']];
            }
        }

        // optional tags
        if ($tagsCsv !== '') {
            $tags = array_values(array_filter(array_map('trim', explode(',', $tagsCsv))));
            foreach ($tags as $t) $repo->addTag($photoId, $t);
        }

        return [
            'ok'        => true,
            'asset_id'  => $assetId,
            'photo_id'  => $photoId,
            'base_size' => ['w'=>$baseW, 'h'=>$baseH],
            'touched'   => $touched,
        ];
    }

    // GET: photo-render-color.php?asset_id=...&role=...&hex=...&mode=...&alpha=...&base=...
    public function renderColor(array $q): array
    {
        if (!class_exists('Imagick')) throw new \RuntimeException('Imagick not available');

        $assetId = trim((string)($q['asset_id'] ?? ''));
        $role    = trim((string)($q['role'] ?? ''));
        $hex     = (string)($q['hex'] ?? '');
        $mode    = strtolower((string)($q['mode'] ?? 'match-lightness')); // 'match-lightness' | 'softlight' etc.
        $alpha   = (float)($q['alpha'] ?? 0.9);
        $base    = trim((string)($q['base'] ?? '')); // absolute or site-relative URL (optional)

        if ($assetId === '' || $role === '' || $hex === '') {
            throw new \RuntimeException('asset_id, role, hex required');
        }

        $repo = new PdoPhotoRepository($this->pdo);
        $svc  = new PhotoRenderingService($repo, $this->pdo);

        return $svc->renderSingleRole($assetId, $role, $hex, $mode, $alpha, $base);
    }

    public function renderApply(array $payload): array
    {
        $assetId = trim((string)($payload['asset_id'] ?? ''));
        $map     = $payload['map'] ?? [];
        $mode    = strtolower((string)($payload['mode'] ?? 'match-lightness')); // match-lightness|softlight|keep-lightness
        $alpha   = (float)($payload['alpha'] ?? 0.9);

        if ($assetId === '' || !is_array($map) || !$map) {
            throw new \RuntimeException('asset_id and non-empty map are required');
        }

        // Normalize hex (role -> 6-digit hex, no #)
        $hexMap = [];
        foreach ($map as $role => $hexIn) {
            $r = trim((string)$role);
            $h = strtoupper(trim((string)$hexIn));
            $h = preg_replace('/[^0-9A-F]/i', '', $h);
            if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
            if ($r === '' || strlen($h) !== 6) continue;
            $hexMap[$r] = $h;
        }
        if (!$hexMap) throw new \RuntimeException('map contains no valid role→hex entries');

        $repo = new PdoPhotoRepository($this->pdo);
        $svc  = new PhotoRenderingService($repo, $this->pdo);

        $res = $svc->renderApplyMap($assetId, $hexMap, $mode, $alpha);

        // add absolute URL for convenience
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!empty($res['render_rel_path'])) {
            $rel = (string)$res['render_rel_path'];
            $res['render_url'] = preg_match('~^https?://~i', $rel) ? $rel : ($scheme.'://'.$host.'/'.ltrim($rel, '/'));
        }
        return $res;
    }
}
