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

        $roleStatsRows = $repo->getRoleStats((int)$photo['id']);
        $roleStatsMap = [];
        foreach ($roleStatsRows as $statRow) {
            $roleStatsMap[(string)$statRow['role']] = $statRow;
        }

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
                $masks[] = $this->buildMaskPayload($role, $rel, $v, $toUrl, $roleStatsMap[$role] ?? null);
            } elseif (str_starts_with($kind, 'mask:')) {
                $roleLegacy = (string)($v['role'] ?? substr($kind, 5));
                $masks[] = $this->buildMaskPayload($roleLegacy, $rel, $v, $toUrl, $roleStatsMap[$roleLegacy] ?? null);
            }
        }

        // Map stats → precomputed[role].Lm
        $precomputed = [];
        foreach ($roleStatsRows as $row) {
            $precomputed[(string)$row['role']] = ['Lm' => (float)$row['l_avg01']];
        }

        $tagsMap = $repo->getTagsForPhotoIds([(int)$photo['id']]);
        $tags = $tagsMap[(int)$photo['id']] ?? [];

        return [
            'photo_id'       => (int)$photo['id'],
            'asset_id'       => (string)$photo['asset_id'],
            'width'          => (int)$photo['width'],
            'height'         => (int)$photo['height'],
            'category_path'  => (string)($photo['category_path'] ?? ''),
            'repaired_url'   => $repairedUrl,
            'prepared_url'   => $preparedUrl,     // legacy single, may be null
            'prepared_tiers' => $preparedTiers,   // {dark|null, medium|null, light|null}
            'masks'          => $masks,
            'precomputed'    => $precomputed ?: null,
            'style_primary'  => (string)($photo['style_primary'] ?? ''),
            'verdict'        => (string)($photo['verdict'] ?? ''),
            'status'         => (string)($photo['status'] ?? ''),
            'lighting'       => (string)($photo['lighting'] ?? ''),
            'rights_status'  => (string)($photo['rights_status'] ?? ''),
            'tags'           => $tags,
        ];
    }

    /** GET search: by tags (AND) + free text; returns absolute thumb URLs. */
    public function search(array $q): array
    {
        $tags   = (array)($q['tags'] ?? []);
        $qtext  = (string)($q['q'] ?? '');
        $page   = max(1, (int)($q['page'] ?? 1));
        $limit  = max(1, min(100, (int)($q['limit'] ?? 24)));
        $offset = ($page - 1) * $limit;

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
            'page'  => $page,
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
        $svc  = new PhotoRenderingService($repo, $this->pdo);
        $updated = $svc->recalcLmForRoles($assetId, $roles ?: null);

        return ['ok' => true, 'asset_id' => $assetId, 'updated' => $updated];
    }

    /**
     * POST upload: supports legacy prepared_base + masks[], and NEW prepared trio:
     *   prepared_dark | prepared_medium | prepared_light
     *   texture_overlay (PNG luminance layer)
     *
     * Saves under: /photos/YYYY/YYYY-MM/ASSET_ID/{prepared,masks,thumb,renders}
     */
    public function upload(array $post, array $files): array
    {
        $doc = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__.'/../../..'), '/');
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
        $styleRaw  = trim((string)($post['style'] ?? ''));
        $style     = $styleRaw !== '' ? $styleRaw : null;
        $verdict   = $post['verdict']   ?? null;
        $status    = $post['status']    ?? null;
        $lighting  = $post['lighting']  ?? null;
        $rights    = $post['rights']    ?? null;
        $tagsCsv   = trim((string)($post['tags'] ?? ''));
        $hasIncomingStyle = $style !== null;
        $hasIncomingTags  = $tagsCsv !== '';
        $findableErrorMsg = 'Add a style or at least one tag so this photo can be found later.';
        $categoryInput = $this->sanitizeCategoryPath($post['category_path'] ?? null);

        $hasPreparedSingle = isset($files['prepared_base']) && $files['prepared_base']['error'] === UPLOAD_ERR_OK;
        $hasTexture        = isset($files['texture_overlay']) && $files['texture_overlay']['error'] === UPLOAD_ERR_OK;
        $hasMasks          = isset($files['masks']) && is_array($files['masks']['name']);

        if (!$assetIdIn && !$hasPreparedSingle) {
            throw new RuntimeException('New assets require prepared_base.');
        }
        if (!$assetIdIn && $hasTexture && !$hasPreparedSingle) {
            throw new RuntimeException('Upload the prepared base before attaching a texture overlay.');
        }
        if ($assetIdIn === '' && !$hasIncomingStyle && !$hasIncomingTags) {
            throw new RuntimeException($findableErrorMsg);
        }

        $repo = new PdoPhotoRepository($this->pdo);
        $svc  = new PhotosUploadService($repo, $photosRoot, $this->pdo);

        // Resolve/create photo
        if ($assetIdIn !== '') {
            $photo = $repo->getPhotoByAssetId($assetIdIn);
            if (!$photo) throw new RuntimeException("asset_id not found: {$assetIdIn}");
            $assetId = (string)$photo['asset_id'];
            $photoId = (int)$photo['id'];
            $categoryPath = $this->resolveCategoryPathForPhoto($photo, $repo);
            if ($categoryInput && $categoryInput !== $categoryPath) {
                $svc->moveAssetBase($assetId, $categoryPath, $categoryInput);
                $repo->updatePhotoCategoryPath($photoId, $categoryInput);
                $categoryPath = $categoryInput;
            }
            if (!$hasIncomingStyle && !$hasIncomingTags) {
                $hasExistingStyle = trim((string)($photo['style_primary'] ?? '')) !== '';
                if (!$hasExistingStyle) {
                    $existingTagsMap = $repo->getTagsForPhotoIds([$photoId]);
                    $hasExistingTags = !empty($existingTagsMap[$photoId] ?? []);
                    if (!$hasExistingTags) {
                        throw new RuntimeException($findableErrorMsg);
                    }
                }
            }
        } else {
            $assetId = $makeAssetId();
            $categoryPath = $categoryInput ?: $this->defaultCategoryPath();
            $insert  = $repo->createPhotoShell($assetId, $style ?: null, $verdict ?: null, $status ?: null, $lighting ?: null, $rights ?: null, $categoryPath);
            $photo   = $repo->getPhotoById((int)$insert['id']);
            $photoId = (int)$photo['id'];
        }
        $svc->registerAssetPath($assetId, $categoryPath);

        // Folder /photos/YYYY/YYYY-MM/ASSET_ID
        $now = new \DateTime('now');
        $y   = $now->format('Y');
        $ym  = $now->format('Y-m');
        $assetDir = "{$photosRoot}/{$y}/{$ym}/{$assetId}";
        $mkpath("{$assetDir}/prepared");
        $mkpath("{$assetDir}/masks");
        $mkpath("{$assetDir}/thumb");
        $mkpath("{$assetDir}/renders");
        $mkpath("{$assetDir}/textures");

        $touched = [];
        $baseW = (int)($photo['width'] ?? 0);
        $baseH = (int)($photo['height'] ?? 0);
        $syncPhotoSize = function(int $w, int $h) use (&$baseW, &$baseH, $repo, $photoId) {
            if ($w <= 0 || $h <= 0) return;
            if ($baseW === $w && $baseH === $h) return;
            $baseW = $w;
            $baseH = $h;
            $repo->updatePhotoSize($photoId, $baseW, $baseH);
        };

        // ----- Single prepared base -----
        if ($hasPreparedSingle) {
            $res = $svc->saveBase($photoId, 'prepared', $files['prepared_base']);
            $touched[] = ['kind'=>'prepared', 'role'=>'', 'w'=>$res['width'], 'h'=>$res['height']];
            if ($res['width'] && $res['height']) $syncPhotoSize((int)$res['width'], (int)$res['height']);
        }

        if ($hasTexture) {
            $res = $svc->saveTextureOverlay($photoId, $files['texture_overlay']);
            $touched[] = ['kind'=>'texture', 'role'=>'', 'w'=>$res['width'], 'h'=>$res['height']];
        }

        $maskModeDarkArr   = $post['mask_mode_dark'] ?? [];
        $maskModeMediumArr = $post['mask_mode_medium'] ?? [];
        $maskModeLightArr  = $post['mask_mode_light'] ?? [];
        $maskOpacityDarkArr   = $post['mask_opacity_dark'] ?? [];
        $maskOpacityMediumArr = $post['mask_opacity_medium'] ?? [];
        $maskOpacityLightArr  = $post['mask_opacity_light'] ?? [];
        $maskSlugArr          = $post['mask_slugs'] ?? [];
        $maskTextureArr       = $post['mask_original_texture'] ?? [];
        foreach ([$maskModeDarkArr, $maskModeMediumArr, $maskModeLightArr, $maskOpacityDarkArr, $maskOpacityMediumArr, $maskOpacityLightArr, $maskSlugArr] as &$arr) {
            if (!is_array($arr)) $arr = [$arr];
        }
        unset($arr);
        if (!is_array($maskTextureArr)) $maskTextureArr = [$maskTextureArr];
        $maskSettingsIndex = 0;

        // ----- masks[] (use service; role from filename) -----
            if ($hasMasks) {
                $count = count($files['masks']['name']);
                if (!$baseW || !$baseH) throw new RuntimeException('Upload prepared image(s) first; masks must match size.');
                for ($i=0; $i<$count; $i++) {
                    if (($files['masks']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $tmp  = $files['masks']['tmp_name'][$i];
                $name = $files['masks']['name'][$i];
                if (!is_uploaded_file($tmp)) continue;

                $slugOverride = $this->sanitizeMaskSlug($maskSlugArr[$maskSettingsIndex] ?? null);
                $role = $slugOverride !== null && $slugOverride !== ''
                    ? $slugOverride
                    : $roleFrom($name);

                // Validate mask dimensions against base
                $maskDims = $probe($tmp);
                if ((int)$maskDims['w'] !== $baseW || (int)$maskDims['h'] !== $baseH) {
                    throw new RuntimeException(sprintf(
                        'Mask "%s" must be %dx%d; got %dx%d',
                        $name,
                        $baseW,
                        $baseH,
                        (int)$maskDims['w'],
                        (int)$maskDims['h']
                    ));
                }

                // Repackage this single file into a $_FILES-like array for the service
                $one = [
                    'tmp_name' => $tmp,
                    'name'     => $name,
                    'type'     => 'image/png',
                    'error'    => UPLOAD_ERR_OK,
                    'size'     => @filesize($tmp) ?: null,
                ];
                $overlaySettings = [
                    'dark' => [
                        'mode' => $this->normalizeOverlayMode($maskModeDarkArr[$maskSettingsIndex] ?? null),
                        'opacity' => $this->normalizeOverlayOpacity($maskOpacityDarkArr[$maskSettingsIndex] ?? null),
                    ],
                    'medium' => [
                        'mode' => $this->normalizeOverlayMode($maskModeMediumArr[$maskSettingsIndex] ?? null),
                        'opacity' => $this->normalizeOverlayOpacity($maskOpacityMediumArr[$maskSettingsIndex] ?? null),
                    ],
                    'light' => [
                        'mode' => $this->normalizeOverlayMode($maskModeLightArr[$maskSettingsIndex] ?? null),
                        'opacity' => $this->normalizeOverlayOpacity($maskOpacityLightArr[$maskSettingsIndex] ?? null),
                    ],
                ];

                $texture = $this->normalizeMaskTexture($maskTextureArr[$maskSettingsIndex] ?? null);
                $res = $svc->saveMask($photoId, $role, $one, $overlaySettings, $texture, true);
                $touched[] = ['kind'=>'masks', 'role'=>$role, 'w'=>$res['width'], 'h'=>$res['height']];
                $maskSettingsIndex++;
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
            'category_path' => $categoryPath,
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

        $res = $svc->renderApplyMap($assetId, $hexMap, $mode, $alpha, []);

        // add absolute URL for convenience
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!empty($res['render_rel_path'])) {
            $rel = (string)$res['render_rel_path'];
            $res['render_url'] = preg_match('~^https?://~i', $rel) ? $rel : ($scheme.'://'.$host.'/'.ltrim($rel, '/'));
        }
        return $res;
    }

    private function buildOverlayPayload(array $variantRow): array
    {
        $tiers = ['dark','medium','light'];
        $out = [];
        foreach ($tiers as $tier) {
            $modeKey = "overlay_mode_{$tier}";
            $opKey   = "overlay_opacity_{$tier}";
            $out[$tier] = [
                'mode'    => $this->normalizeOverlayMode($variantRow[$modeKey] ?? null),
                'opacity' => $this->normalizeOverlayOpacity($variantRow[$opKey] ?? null),
            ];
        }
        $out['_shadow'] = [
            'l_offset' => $this->normalizeShadowOffset($variantRow['overlay_shadow_l_offset'] ?? null),
            'tint_hex' => $this->normalizeShadowTint($variantRow['overlay_shadow_tint'] ?? null),
            'tint_opacity' => $this->normalizeShadowTintOpacity($variantRow['overlay_shadow_tint_opacity'] ?? null),
        ];
        return $out;
    }

    private function buildMaskPayload(string $role, string $relPath, array $variantRow, callable $toUrl, ?array $statsRow = null): array
    {
        $url = $toUrl($relPath);
        $filename = basename($relPath) ?: $relPath;
        return [
            'role'    => $role,
            'url'     => $url,
            'path'    => $relPath,
            'filename'=> $filename,
            'overlay' => $this->buildOverlayPayload($variantRow),
            'original_texture' => $this->normalizeMaskTexture($variantRow['original_texture'] ?? null),
            'base_lightness' => $statsRow && isset($statsRow['l_avg01']) ? (float)$statsRow['l_avg01'] : null,
        ];
    }

    private function normalizeOverlayMode($mode): ?string
    {
        if (!is_string($mode)) return null;
        $m = strtolower(trim($mode));
        $allowed = ['colorize','hardlight','softlight','overlay','multiply','screen','luminosity','flatpaint','original'];
        return in_array($m, $allowed, true) ? $m : null;
    }

    private function normalizeOverlayOpacity($val): ?float
    {
        if ($val === '' || $val === null) return null;
        $num = (float)$val;
        if (!is_finite($num)) return null;
        if ($num < 0) $num = 0;
        if ($num > 1) $num = 1;
        return $num;
    }

    private function normalizeShadowOffset($val): float
    {
        if ($val === null || $val === '') return 0.0;
        $num = (float)$val;
        if (!is_finite($num)) $num = 0.0;
        if ($num < -50) $num = -50;
        if ($num > 50) $num = 50;
        return $num;
    }

    private function normalizeShadowTint($val): ?string
    {
        if (!is_string($val)) return null;
        $trim = strtoupper(trim($val));
        $trim = ltrim($trim, '#');
        if (strlen($trim) === 3) {
            $trim = $trim[0].$trim[0].$trim[1].$trim[1].$trim[2].$trim[2];
        }
        if (!preg_match('/^[0-9A-F]{6}$/', $trim)) return null;
        return '#'.$trim;
    }

    private function normalizeShadowTintOpacity($val): float
    {
        if ($val === null || $val === '') return 0.0;
        $num = (float)$val;
        if (!is_finite($num)) $num = 0.0;
        if ($num < 0) $num = 0;
        if ($num > 1) $num = 1;
        return $num;
    }

    private function sanitizeMaskSlug($slug): ?string
    {
        if (!is_string($slug)) return null;
        $trim = strtolower(trim($slug));
        $trim = preg_replace('/[^a-z0-9\-]/', '-', $trim);
        $trim = preg_replace('/-+/', '-', $trim);
        return $trim === '' ? null : $trim;
    }

    private function normalizeMaskTexture($value): ?string
    {
        if (!is_string($value)) return null;
        $trim = strtolower(trim($value));
        if ($trim === '') return null;
        $trim = str_replace([' ', '-'], '_', $trim);
        $trim = preg_replace('/[^a-z0-9_]+/', '_', $trim);
        $trim = preg_replace('/_+/', '_', $trim);
        $trim = trim($trim, '_');
        if ($trim === '') return null;
        return substr($trim, 0, 64);
    }

    private function sanitizeCategoryPath($value): ?string
    {
        if (!is_string($value)) return null;
        $trim = trim($value);
        if ($trim === '') return null;
        $trim = str_replace('\\', '/', $trim);
        $segments = array_filter(array_map(function ($seg) {
            $seg = strtolower(trim($seg));
            $seg = preg_replace('/[^a-z0-9\-]+/', '-', $seg);
            $seg = trim($seg, '-');
            return $seg;
        }, explode('/', $trim)));
        if (!$segments) return null;
        return implode('/', $segments);
    }

    private function defaultCategoryPath(): string
    {
        $y = date('Y');
        $ym = date('Y-m');
        return "{$y}/{$ym}";
    }

    private function resolveCategoryPathForPhoto(array $photo, PdoPhotoRepository $repo): string
    {
        $photoId = (int)($photo['id'] ?? 0);
        $existing = (string)($photo['category_path'] ?? '');
        if ($existing !== '') return $existing;
        $derived = $this->inferCategoryFromVariants($repo, $photoId);
        if ($derived === null) $derived = $this->defaultCategoryPath();
        if ($photoId > 0) $repo->updatePhotoCategoryPath($photoId, $derived);
        return $derived;
    }

    private function inferCategoryFromVariants(PdoPhotoRepository $repo, int $photoId): ?string
    {
        if ($photoId <= 0) return null;
        foreach ($repo->listVariants($photoId) as $variant) {
            $rel = (string)($variant['path'] ?? '');
            if (!str_starts_with($rel, '/photos/')) continue;
            $parts = explode('/', trim($rel, '/'));
            if (count($parts) >= 4) {
                return $parts[1] . '/' . $parts[2];
            }
        }
        return null;
    }
}
