<?php
namespace App\Services;

use App\Repos\PdoPhotoRepository;
use App\Repos\PdoColorRepository;
use App\Repos\PdoMaskBlendSettingRepository;
use App\Entities\AppliedPalette;
use RuntimeException;

class PhotoRenderingService
{
    private const ROLE_PRIORITY = [
        'body'       => 10,
        'stucco'     => 15,
        'siding'     => 20,
        'brick'      => 25,
        'trim'       => 40,
        'fascia'     => 50,
        'bellyband'  => 60,
        'windowtrim' => 70,
        'doortrim'   => 75,
        'accent'     => 80,
        'frontdoor'  => 90,
        'door'       => 95,
        'gutter'     => 96,
        'railing'    => 97,
        'hardware'   => 98,
        'light'      => 99,
    ];

    private array $roleStatsCache = []; // [photoId => [role => statsRow]]

    public function __construct(
        private PdoPhotoRepository $repo,
        private \PDO $pdo,
        private ?PdoColorRepository $colorRepo = null,
        private ?PdoMaskBlendSettingRepository $maskBlendRepo = null
    ) {
        $this->colorRepo ??= new PdoColorRepository($this->pdo);
        $this->maskBlendRepo ??= new PdoMaskBlendSettingRepository($this->pdo);
    }

    /* =======================================================================
     * PUBLIC API
     * =======================================================================
     */

    /**
     * Apply a role=>HEX6 map to an asset's prepared base and save a render.
     * $mode: 'colorize' (Lab, keeps shading) | 'flat' (RGB tint)
     * $alpha: 0..1 (global multiplier; per-pixel alpha comes from PNG mask)
     * $lmix:  0..1 pull of base L* toward target L* (only used for 'colorize')
     */
public function renderApplyMap(
    string $assetId,
    array $hexMap,
    string $mode = 'colorize',   // 'colorize' | 'flat' (kept for fallback)
    float $alpha = 0.90,
    array $overlayOverrides = [],
    ?string $outputRelPath = null
): array {
    // 1) Locate prepared + masks for this asset
    $photo = $this->repo->getPhotoByAssetId($assetId);
    if (!$photo) throw new \RuntimeException("Unknown asset_id: {$assetId}");
    $pid      = (int)$photo['id'];
    $variants = $this->repo->listVariants($pid);

    $preparedRel = '';
    $textureOverlayRel = '';
    $masksByRole = [];
    foreach ($variants as $v) {
        $kind = (string)($v['kind'] ?? '');
        if ($kind === 'prepared_base') {
            $preparedRel = (string)$v['path'];
        } elseif ($kind === 'prepared') {
            $role = (string)($v['role'] ?? '');
            if ($role === '' || strtolower($role) === 'base') {
                $preparedRel = (string)$v['path'];
            }
        } elseif ($kind === 'mask' || str_starts_with($kind, 'mask')) {
            $role = (string)($v['role'] ?? '');
            if ($role !== '') {
                $masksByRole[$role] = [
                    'path'     => (string)$v['path'],
                    'overlay'  => $this->extractOverlaySettings($v),
                    'original_texture' => isset($v['original_texture']) && $v['original_texture'] !== ''
                        ? (string)$v['original_texture']
                        : null,
                ];
            }
        } elseif ($kind === 'texture') {
            $textureOverlayRel = (string)$v['path'];
        }
    }
    if ($preparedRel === '') throw new \RuntimeException("No prepared_base found for {$assetId}");

    // 2) Open prepared
    $preparedAbs = $this->absPath($preparedRel);
    $baseIm      = $this->openImage($preparedAbs);
    if (!$baseIm) throw new \RuntimeException("Unable to open prepared image");

    $W = imagesx($baseIm);
    $H = imagesy($baseIm);
    imagesavealpha($baseIm, true);
    imagealphablending($baseIm, false); // we write final pixels

    // Optional texture overlay image (same dimensions as prepared base)
    $textureIm = null;
    if ($textureOverlayRel !== '') {
        $texAbs = $this->absPath($textureOverlayRel);
        $tmpTex = $this->openImage($texAbs);
        if ($tmpTex && imagesx($tmpTex) === $W && imagesy($tmpTex) === $H) {
            imagesavealpha($tmpTex, true);
            imagealphablending($tmpTex, true);
            $textureIm = $tmpTex;
        } elseif ($tmpTex) {
            imagedestroy($tmpTex);
        }
    }
    $useTextureOverlay = $textureIm !== null;

    $useFlat = ($mode === 'flat');
    $blendSettingsByRole = [];
    $shadowSettingsByRole = [];

    // 3) Composite each role (sorted so higher-priority masks render last)
    $orderedRoles = $this->sortRoleKeys(array_keys($hexMap));

    foreach ($orderedRoles as $role) {
        $hex6raw = $hexMap[$role];
        $hex6 = strtoupper(trim((string)$hex6raw));
        if (!preg_match('/^[0-9A-F]{6}$/', $hex6)) continue;
        $maskMeta = $masksByRole[$role] ?? null;
        if (!$maskMeta || empty($maskMeta['path'])) continue;

        $maskAbs = $this->absPath($maskMeta['path']);
        $maskIm  = $this->openImage($maskAbs);
        if (!$maskIm) continue;

        $mW = imagesx($maskIm); $mH = imagesy($maskIm);
        if ($mW !== $W || $mH !== $H) {
            imagedestroy($maskIm);
            throw new \RuntimeException("mask size mismatch ({$mW}x{$mH} vs {$W}x{$H})");
        }
        imagesavealpha($maskIm, true);
        imagealphablending($maskIm, true);

        // Target color (both RGB & Lab)
        [$rT, $gT, $bT] = $this->hexToRgb($hex6);
        [$Lt, $at, $bt] = $this->rgbToLab($rT, $gT, $bT);

        $alphaForRole = $this->clamp01($alpha);

        $overlaySource = $overlayOverrides[$role] ?? ($maskMeta['overlay'] ?? null);
        $maskBlend = $this->resolveMaskBlend($overlaySource, $Lt);
        $blendSettingsByRole[$role] = $maskBlend;
        $shadowSettingsByRole[$role] = $this->extractShadowSettings($overlaySource);
        $modeLower = strtolower($maskBlend['mode'] ?? '');
        $isFlatPaint = $modeLower === 'flatpaint';
        $isOriginalMode = $modeLower === 'original';
        if ($isOriginalMode) {
            imagedestroy($maskIm);
            continue;
        }
        $applyTextureLater = $useTextureOverlay && !$isFlatPaint;
        $directBlendOpacity = $applyTextureLater ? 1.0 : $maskBlend['opacity'];

        // 4) Per-pixel
        if ($useFlat) {
            $paintRgb = [$this->clamp255($rT), $this->clamp255($gT), $this->clamp255($bT)];
        } else {
            $paintRgb = $this->labToRgb($Lt, $at, $bt);
            $paintRgb = [
                $this->clamp255($paintRgb[0]),
                $this->clamp255($paintRgb[1]),
                $this->clamp255($paintRgb[2]),
            ];
        }

        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $m  = imagecolorat($maskIm, $x, $y);
                $ma = ($m & 0x7F000000) >> 24;
                if ($ma >= 127) continue;
                $coverage = 1.0 - ($ma / 127.0);
                if ($coverage <= 0) continue;
                // Full coverage paint layer (just respects mask feathering)
                [$rPaintBase, $gPaintBase, $bPaintBase] = $paintRgb;

                if ($coverage < 1.0) {
                    $p  = imagecolorat($baseIm, $x, $y);
                    $r0 = ($p >> 16) & 0xFF; $g0 = ($p >> 8) & 0xFF; $b0 = $p & 0xFF;
                    $rPaintBase = $this->clamp255($r0 * (1.0 - $coverage) + $rPaintBase * $coverage);
                    $gPaintBase = $this->clamp255($g0 * (1.0 - $coverage) + $gPaintBase * $coverage);
                    $bPaintBase = $this->clamp255($b0 * (1.0 - $coverage) + $bPaintBase * $coverage);
                }

                $colPaint = imagecolorallocatealpha($baseIm, $rPaintBase, $gPaintBase, $bPaintBase, 0);
                imagesetpixel($baseIm, $x, $y, $colPaint);
            }
        }

        imagedestroy($maskIm);
    }

        if ($useTextureOverlay && $textureIm) {
            $this->applyTextureOverlays($baseIm, $textureIm, $masksByRole, $blendSettingsByRole, $W, $H);
            imagedestroy($textureIm);
        }

        $this->applyShadowAdjustments($baseIm, $masksByRole, $shadowSettingsByRole, $blendSettingsByRole, $W, $H);

    // 5) Save render
    $outRel = $outputRelPath ? $this->normalizeRelPath($outputRelPath) : $this->buildRenderRelPath($assetId, $hexMap);
    $outAbs = $this->absPath($outRel, true);
    imagejpeg($baseIm, $outAbs, 90);
    imagedestroy($baseIm);

    return [
        'ok'              => true,
        'render_rel_path' => $outRel,
        'render_url'      => $this->absUrl($outRel),
        'width'           => $W,
        'height'          => $H,
    ];
}

    private function sortRoleKeys(array $keys): array
    {
        $unique = array_values(array_unique($keys));
        usort($unique, function ($a, $b) {
            $pa = $this->rolePriority($a);
            $pb = $this->rolePriority($b);
            if ($pa === $pb) {
                return strcmp((string)$a, (string)$b);
            }
            return $pa <=> $pb;
        });
        return $unique;
    }

    private function rolePriority(string $role): int
    {
        $key = strtolower($role);
        return self::ROLE_PRIORITY[$key] ?? 1000;
    }







// Per-color (optionally per-role) calibration for ΔL = raw_dL * k + b
    private function getDeltaLCalib(string $role, string $hex6, ?int $colorId): array {
        // Defaults = neutral (no change)
        $k = 1.00; $b = 0.0;

        // Temporary guardrails you can tweak immediately:
    // Push “pure black” darker, lift “pure white” a touch
    if (!$colorId) {
        if ($hex6 === '000000') { $k = 1.30; $b = -3.0; }
        if ($hex6 === 'FFFFFF') { $k = 1.10; $b = +2.0; }
    }

    // When you’re ready, swap this for a repo lookup:
    // if ($colorId) { [$k,$b] = $this->calibRepo->getCalibForColor($colorId, $role); }

        return [$k, $b];
    }

    /** Returns [detailKeep, alphaCap, mode] per target color. */
    private function perColorDetailControl(string $hex6, float $Lt, string $role): array {
        $hex6 = strtoupper($hex6);
        $role = strtolower($role);

        $detailKeep = 0.0;
        $alphaCap   = 1.0;
        $mode       = 'offset'; // 'offset' adds contrast, 'mix' blends original

        $isFineDetail = in_array($role, ['trim','shutters','frontdoor','gutter','windowtrim','fascia'], true);

        // Heuristic tiers by perceived lightness; override with explicit map if needed.
        if ($Lt >= 92.0) {
            $detailKeep = $isFineDetail ? 0.08 : 0.03;
            $alphaCap   = $isFineDetail ? 0.98 : 1.0;
            $mode = 'mix';
        } elseif ($Lt >= 82.0) {
            $detailKeep = $isFineDetail ? 0.10 : 0.04;
            $alphaCap   = 0.93;
            $mode = 'mix';
        } elseif ($Lt <= 8.0) {
            $detailKeep = $isFineDetail ? 0.04 : 0.02;
            $alphaCap   = 1.0;
            $mode = 'offset';
        } elseif ($Lt <= 20.0) {
            $detailKeep = $isFineDetail ? 0.05 : 0.03;
            $alphaCap   = 0.94;
            $mode = 'offset';
        }

        // Wide surfaces (body/stucco/etc.) need extra grit when going very light.
        $bodyRoles = ['body','stucco','siding','brick','stone','bodymain'];
        if (in_array($role, $bodyRoles, true) && $Lt >= 88.0) {
            $detailKeep = max($detailKeep, 0.12);
            $alphaCap   = min($alphaCap, 0.90);
            $mode       = 'mix';
        }

        // Exact per-color overrides (edit as needed)
        $overrides = [
            'FFFFFF' => ['detailKeep' => 0.02, 'alphaCap' => 1.0, 'mode' => 'mix'],
            'FFF9F0' => ['detailKeep' => 0.03, 'alphaCap' => 0.99, 'mode' => 'mix'],
            'F0EFEB' => ['detailKeep' => 0.04, 'alphaCap' => 0.99, 'mode' => 'mix'],
            '000000' => ['detailKeep' => 0.0, 'alphaCap' => 1.0, 'mode' => 'offset'],
        ];
        if (isset($overrides[$hex6])) {
            $detailKeep = $overrides[$hex6]['detailKeep'];
            $alphaCap   = $overrides[$hex6]['alphaCap'];
            if (isset($overrides[$hex6]['mode'])) $mode = $overrides[$hex6]['mode'];
        }

        return [$this->clamp01($detailKeep), $this->clamp01($alphaCap), $mode];
    }

// Per-photo/role one-off offset for prepared quirks (defaults to 0.0)
private function getPhotoRoleOffset(int $photoId, string $role): float {
    // Later: return $this->calibRepo->getPhotoRoleOffset($photoId, $role);
    return 0.0;
}

// Alias to match older/newer helper names
private function averageLUnderMask(\GdImage $baseIm, \GdImage $maskIm): float {
    return $this->maskedMeanLightness($baseIm, $maskIm);
}

    private function percentile(array $values, float $fraction): float {
        $count = count($values);
        if ($count === 0) return 0.0;
        $index = ($count - 1) * max(0.0, min(1.0, $fraction));
        $lower = (int)floor($index);
    $upper = (int)ceil($index);
    if ($lower === $upper) return (float)$values[$lower];
    $weight = $index - $lower;
    return (float)$values[$lower] * (1.0 - $weight) + (float)$values[$upper] * $weight;
}

    public function measureMaskStats(string $preparedAbsPath, string $maskAbsPath): ?array {
        $baseIm = $this->openImage($preparedAbsPath);
        $maskIm = $this->openImage($maskAbsPath);
        if (!$baseIm || !$maskIm) return null;
        $W = imagesx($baseIm); $H = imagesy($baseIm);
        if ($W !== imagesx($maskIm) || $H !== imagesy($maskIm)) {
            imagedestroy($baseIm);
            imagedestroy($maskIm);
            return null;
        }
        imagesavealpha($maskIm, true);
        imagealphablending($maskIm, true);

        $step = max(1, (int)floor(min($W, $H) / 400));
        $sum = 0.0;
        $wSum = 0.0;
        $values = [];
        $pxCovered = 0.0;
        $areaPerSample = $step * $step;

        for ($y = 0; $y < $H; $y += $step) {
            for ($x = 0; $x < $W; $x += $step) {
                $m = imagecolorat($maskIm, $x, $y);
                $ma = ($m & 0x7F000000) >> 24;
                $coverage = 1.0 - ($ma / 127.0);
                if ($coverage <= 0.0) continue;

                $p = imagecolorat($baseIm, $x, $y);
                $r0 = ($p >> 16) & 0xFF; $g0 = ($p >> 8) & 0xFF; $b0 = $p & 0xFF;
                [$L0] = $this->rgbToLab($r0, $g0, $b0);

                $sum  += $L0 * $coverage;
                $wSum += $coverage;
                $values[] = $L0;
                $pxCovered += $coverage * $areaPerSample;
            }
        }

        imagedestroy($baseIm);
        imagedestroy($maskIm);

        if ($wSum <= 0.0 || !$values) return null;
        sort($values);

        return [
            'l_avg' => $sum / $wSum,
            'l_p10' => $this->percentile($values, 0.10),
            'l_p90' => $this->percentile($values, 0.90),
            'px_covered' => (int)round($pxCovered),
        ];
    }

    public function recalcLmForRoles(string $assetId, ?array $roles = null): array {
        $photo = $this->repo->getPhotoByAssetId($assetId);
        if (!$photo) throw new RuntimeException("asset not found: {$assetId}");
        $photoId = (int)$photo['id'];
        $variants = $this->repo->listVariants($photoId);
        $preparedRel = '';
        $masks = [];
        foreach ($variants as $v) {
            $kind = (string)$v['kind'];
            if ($kind === 'prepared_base' || ($kind === 'prepared' && ((string)$v['role'] === '' || strtolower((string)$v['role']) === 'base'))) {
                $preparedRel = (string)$v['path'];
            } elseif ($kind === 'masks' && !empty($v['role']) && !empty($v['path'])) {
                $masks[] = [
                    'role' => (string)$v['role'],
                    'path' => (string)$v['path'],
                ];
            }
        }
        if ($preparedRel === '') throw new RuntimeException("No prepared_base found for {$assetId}");
        $preparedAbs = $this->absPath($preparedRel);
        if (!is_file($preparedAbs)) throw new RuntimeException("Prepared base missing on disk for {$assetId}");
        $preparedBytes = (int)@filesize($preparedAbs);

        $roleFilter = null;
        if ($roles) {
            $roleFilter = array_fill_keys(array_map('strtolower', array_map('trim', $roles)), true);
        }

        $updated = [];
        foreach ($masks as $mask) {
            $role = $mask['role'];
            if ($roleFilter && !isset($roleFilter[strtolower($role)])) continue;
            $maskAbs = $this->absPath($mask['path']);
            if (!is_file($maskAbs)) continue;
            $stats = $this->measureMaskStats($preparedAbs, $maskAbs);
            if (!$stats) continue;
            $this->repo->upsertMaskStats(
                $photoId,
                $role,
                $preparedRel,
                $mask['path'],
                $preparedBytes,
                (int)@filesize($maskAbs),
                $stats['l_avg'],
                $stats['l_p10'],
                $stats['l_p90'],
                $stats['px_covered']
            );
            $updated[] = $role;
        }
        return $updated;
    }

    private function applyTextureOverlays(
        \GdImage $baseIm,
        \GdImage $textureIm,
        array $masksByRole,
        array $blendSettingsByRole,
        int $W,
        int $H
    ): void {
        $orderedRoles = $this->sortRoleKeys(array_keys($blendSettingsByRole));
        foreach ($orderedRoles as $role) {
            $meta = $masksByRole[$role] ?? null;
            if (!$meta) continue;
            $blend = $blendSettingsByRole[$role] ?? null;
            if (!$blend) continue;
            if (isset($blend['mode'])) {
                $modeLower = strtolower($blend['mode']);
                if ($modeLower === 'flatpaint' || $modeLower === 'original') continue;
            }
            $opacity = $this->clamp01($blend['opacity'] ?? 0.0);
            if ($opacity <= 0.0) continue;
            $mode = $blend['mode'] ?? 'softlight';
            if ($mode === 'colorize') $mode = 'softlight';

            $maskAbs = $this->absPath($meta['path']);
            $maskIm = $this->openImage($maskAbs);
            if (!$maskIm) continue;
            if (imagesx($maskIm) !== $W || imagesy($maskIm) !== $H) {
                imagedestroy($maskIm);
                continue;
            }
            imagesavealpha($maskIm, true);
            imagealphablending($maskIm, true);

            for ($y = 0; $y < $H; $y++) {
                for ($x = 0; $x < $W; $x++) {
                    $m  = imagecolorat($maskIm, $x, $y);
                    $ma = ($m & 0x7F000000) >> 24;
                    if ($ma >= 127) continue;
                    $coverage = $this->clamp01((1.0 - ($ma / 127.0)) * $opacity);
                    if ($coverage <= 0) continue;

                    $p  = imagecolorat($baseIm, $x, $y);
                    $r0 = ($p >> 16) & 0xFF; $g0 = ($p >> 8) & 0xFF; $b0 = $p & 0xFF;

                    $tex = imagecolorat($textureIm, $x, $y);
                    $tr = ($tex >> 16) & 0xFF;
                    $tg = ($tex >> 8) & 0xFF;
                    $tb = $tex & 0xFF;
                    $gray = (int)round(0.2126 * $tr + 0.7152 * $tg + 0.0722 * $tb);
                    $overlayRgb = [$gray, $gray, $gray];

                    [$rBlend, $gBlend, $bBlend] = $this->applyBlendMode([$r0, $g0, $b0], $overlayRgb, $mode);
                    $r = $this->clamp255($r0 * (1.0 - $coverage) + $rBlend * $coverage);
                    $g = $this->clamp255($g0 * (1.0 - $coverage) + $gBlend * $coverage);
                    $b = $this->clamp255($b0 * (1.0 - $coverage) + $bBlend * $coverage);

                    $col = imagecolorallocatealpha($baseIm, $r, $g, $b, 0);
                    imagesetpixel($baseIm, $x, $y, $col);
                }
            }

            imagedestroy($maskIm);
        }
    }

    private function applyShadowAdjustments(
        \GdImage $baseIm,
        array $masksByRole,
        array $shadowSettingsByRole,
        array $blendSettingsByRole,
        int $W,
        int $H
    ): void {
        $orderedRoles = $this->sortRoleKeys(array_keys($shadowSettingsByRole));
        foreach ($orderedRoles as $role) {
            $shadow = $shadowSettingsByRole[$role];
            $maskMeta = $masksByRole[$role] ?? null;
            if (!$maskMeta || empty($maskMeta['path'])) continue;
            $blendForRole = $blendSettingsByRole[$role] ?? null;
            if (isset($blendForRole['mode']) && strtolower($blendForRole['mode']) === 'original') continue;
            $offset = isset($shadow['l_offset']) ? (float)$shadow['l_offset'] : 0.0;
            $tintRgb = $shadow['tint_rgb'] ?? null;
            $tintOpacity = $this->clamp01($shadow['tint_opacity'] ?? 0.0);
            if (abs($offset) < 0.01 && (!$tintRgb || $tintOpacity <= 0.0)) continue;

            $maskAbs = $this->absPath($maskMeta['path']);
            $maskIm = $this->openImage($maskAbs);
            if (!$maskIm) continue;
            if (imagesx($maskIm) !== $W || imagesy($maskIm) !== $H) {
                imagedestroy($maskIm);
                continue;
            }
            imagesavealpha($maskIm, true);
            imagealphablending($maskIm, true);

            for ($y = 0; $y < $H; $y++) {
                for ($x = 0; $x < $W; $x++) {
                    $m  = imagecolorat($maskIm, $x, $y);
                    $ma = ($m & 0x7F000000) >> 24;
                    if ($ma >= 127) continue;
                    $coverage = 1.0 - ($ma / 127.0);
                    if ($coverage <= 0) continue;

                    $p  = imagecolorat($baseIm, $x, $y);
                    $r0 = ($p >> 16) & 0xFF; $g0 = ($p >> 8) & 0xFF; $b0 = $p & 0xFF;
                    [$L0, $a0, $b0Lab] = $this->rgbToLab($r0, $g0, $b0);
                    $L1 = $this->clamp($L0 + $offset, 0.0, 100.0);
                    $rgbAdjusted = $this->labToRgb($L1, $a0, $b0Lab);
                    $r = $this->clamp255($rgbAdjusted[0]);
                    $g = $this->clamp255($rgbAdjusted[1]);
                    $b = $this->clamp255($rgbAdjusted[2]);

                    if ($tintRgb && $tintOpacity > 0) {
                        $r = $this->clamp255($r * (1.0 - $tintOpacity) + $tintRgb[0] * $tintOpacity);
                        $g = $this->clamp255($g * (1.0 - $tintOpacity) + $tintRgb[1] * $tintOpacity);
                        $b = $this->clamp255($b * (1.0 - $tintOpacity) + $tintRgb[2] * $tintOpacity);
                    }

                    $col = imagecolorallocatealpha($baseIm, $r, $g, $b, 0);
                    imagesetpixel($baseIm, $x, $y, $col);
                }
            }

            imagedestroy($maskIm);
        }
    }






    /**
     * Render one role, optionally using a prior composite as the base.
     * (thin wrapper over renderApplyMap)
     */
    public function renderSingleRole(
        string $assetId,
        string $role,
        string $hex6,
        string $mode = 'colorize',
        float $alpha = 0.90,
        string $baseUrlOrRel = '' // currently ignored; we always start from prepared for correctness
    ): array {
        $hex6 = strtoupper(trim($hex6));
        if (!preg_match('/^[0-9A-F]{6}$/', $hex6)) throw new RuntimeException("invalid hex6");
        $role = trim($role);
        if ($role === '') throw new RuntimeException("role required");
        return $this->renderApplyMap($assetId, [$role => $hex6], $mode, $alpha, []);
    }

    private function extractOverlaySettings(array $variantRow): array {
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
            'l_offset'     => $this->normalizeShadowOffset($variantRow['overlay_shadow_l_offset'] ?? null),
            'tint_hex'     => $this->normalizeShadowTint($variantRow['overlay_shadow_tint'] ?? null),
            'tint_opacity' => $this->normalizeShadowTintOpacity($variantRow['overlay_shadow_tint_opacity'] ?? null),
        ];
        return $out;
    }

    private function resolveMaskBlend(?array $overlayMeta, float $Lt): array {
        $candidates = $this->pickOverlayTiersByLightness($Lt);
        $row = [];
        if (is_array($overlayMeta)) {
            foreach ($candidates as $tier) {
                if (isset($overlayMeta[$tier]) && (is_array($overlayMeta[$tier]))) {
                    $row = $overlayMeta[$tier];
                    break;
                }
            }
        }
        $mode = $this->normalizeOverlayMode($row['mode'] ?? null) ?? 'colorize';
        $opacity = $this->normalizeOverlayOpacity($row['opacity'] ?? null);
        if ($opacity === null) $opacity = 0.0;
        return [
            'mode' => $mode,
            'opacity' => $this->clamp01($opacity),
        ];
    }

    public function renderAppliedPalette(AppliedPalette $palette, ?string $outputRelPath = null): array
    {
        $hexMap = [];
        $overrides = [];

        foreach ($palette->entries as $entry) {
            $entry = $this->applyLatestMaskSettings($palette, $entry);
            $colorId = (int)($entry['color_id'] ?? 0);
            $role = trim((string)($entry['mask_role'] ?? ''));
            if ($colorId <= 0 || $role === '') continue;
            $color = $this->colorRepo->getRowById($colorId);
            if (!$color) continue;
            $hex = strtoupper((string)($color['hex6'] ?? ''));
            if (!preg_match('/^[0-9A-F]{6}$/', $hex)) continue;
            $hexMap[$role] = $hex;
            $overrides[$role] = $this->buildOverlayOverride($entry);
        }

        if (!$hexMap) {
            throw new RuntimeException('Applied palette has no usable entries.');
        }

        return $this->renderApplyMap($palette->assetId, $hexMap, 'colorize', 0.9, $overrides, $outputRelPath);
    }

    public function cacheAppliedPalette(AppliedPalette $palette): array
    {
        $renderRel = sprintf('/photos/rendered/ap_%d.jpg', $palette->id);
        $result = $this->renderAppliedPalette($palette, $renderRel);
        $thumbRel = sprintf('/photos/rendered/ap_%d-thumb.jpg', $palette->id);
        $thumbMeta = $this->createThumbnailFromRel($result['render_rel_path'], $thumbRel, 720, 480);
        return [
            'render_rel_path' => $result['render_rel_path'],
            'render_url' => $result['render_url'],
            'thumb_rel_path' => $thumbMeta ? $thumbRel : null,
            'thumb_url' => $thumbMeta ? $this->absUrl($thumbRel) : null,
            'width' => $result['width'] ?? null,
            'height' => $result['height'] ?? null,
            'thumb_width' => $thumbMeta['width'] ?? null,
            'thumb_height' => $thumbMeta['height'] ?? null,
        ];
    }

    private function buildOverlayOverride(array $entry): array
    {
        $mode = $entry['blend_mode'] ?? 'colorize';
        $opacity = isset($entry['blend_opacity']) ? (float)$entry['blend_opacity'] : 0.0;
        $shadow = [
            'l_offset' => $entry['lightness_offset'] ?? 0,
            'tint_hex' => $entry['tint_hex'] ?? null,
            'tint_opacity' => $entry['tint_opacity'] ?? 0,
        ];
        $tiers = ['dark','medium','light'];
        $out = [];
        foreach ($tiers as $tier) {
            $out[$tier] = [
                'mode' => $mode,
                'opacity' => $opacity,
            ];
        }
        $out['_shadow'] = $shadow;
        return $out;
    }

    private function applyLatestMaskSettings(AppliedPalette $palette, array $entry): array
    {
        if (!$this->maskBlendRepo) {
            return $entry;
        }
        $recipe = null;
        if (!empty($entry['mask_setting_id'])) {
            $recipe = $this->maskBlendRepo->findById((int)$entry['mask_setting_id']);
        }
        if (!$recipe && !empty($entry['color_id'])) {
            $recipe = $this->maskBlendRepo->findForAssetMaskColor(
                $palette->assetId,
                (string)($entry['mask_role'] ?? ''),
                (int)$entry['color_id']
            );
        }
        if (!$recipe) {
            return $entry;
        }
        $entry['blend_mode'] = $recipe['blend_mode'] ?? $entry['blend_mode'];
        $entry['blend_opacity'] = $recipe['blend_opacity'] ?? $entry['blend_opacity'];
        $entry['lightness_offset'] = $recipe['shadow_l_offset'] ?? $entry['lightness_offset'];
        $entry['tint_hex'] = $recipe['shadow_tint_hex'] ?? $entry['tint_hex'];
        $entry['tint_opacity'] = $recipe['shadow_tint_opacity'] ?? $entry['tint_opacity'];
        return $entry;
    }

    private function pickOverlayTiersByLightness(float $Lt): array {
        $primary = 'medium';
        if ($Lt <= 45.0) $primary = 'dark';
        elseif ($Lt >= 88.0) $primary = 'light';
        $order = [$primary];
        if ($primary !== 'medium') $order[] = 'medium';
        if ($primary !== 'light') $order[] = 'light';
        if ($primary !== 'dark') $order[] = 'dark';
        return array_unique($order);
    }

    private function normalizeOverlayMode($mode): ?string {
        if (!is_string($mode)) return null;
        $m = strtolower(trim($mode));
        $allowed = ['colorize','hardlight','softlight','overlay','multiply','screen','luminosity','flatpaint','original'];
        return in_array($m, $allowed, true) ? $m : null;
    }

    private function normalizeOverlayOpacity($val): ?float {
        if ($val === '' || $val === null) return null;
        $num = (float)$val;
        if (!is_finite($num)) return null;
        return $this->clamp01($num);
    }

    private function normalizeShadowOffset($val): float {
        if ($val === null || $val === '') return 0.0;
        $num = (float)$val;
        if (!is_finite($num)) $num = 0.0;
        return $this->clamp($num, -50.0, 50.0);
    }

    private function normalizeShadowTint($val): ?string {
        if (!is_string($val)) return null;
        $trim = strtoupper(trim($val));
        $trim = ltrim($trim, '#');
        if (strlen($trim) === 3) {
            $trim = $trim[0].$trim[0].$trim[1].$trim[1].$trim[2].$trim[2];
        }
        if (!preg_match('/^[0-9A-F]{6}$/', $trim)) return null;
        return '#'.$trim;
    }

    private function normalizeShadowTintOpacity($val): float {
        if ($val === null || $val === '') return 0.0;
        $num = (float)$val;
        if (!is_finite($num)) $num = 0.0;
        return $this->clamp01($num);
    }

    private function extractShadowSettings(?array $overlayMeta): array {
        $raw = is_array($overlayMeta) ? ($overlayMeta['_shadow'] ?? null) : null;
        $offset = $this->normalizeShadowOffset($raw['l_offset'] ?? null);
        $tintHex = $this->normalizeShadowTint($raw['tint_hex'] ?? null);
        $tintOpacity = $this->normalizeShadowTintOpacity($raw['tint_opacity'] ?? null);
        $tintRgb = null;
        if ($tintHex) {
            $rgb = $this->hexToRgb(ltrim($tintHex, '#'));
            $tintRgb = [$rgb[0], $rgb[1], $rgb[2]];
        }
        return [
            'l_offset' => $offset,
            'tint_rgb' => $tintRgb,
            'tint_opacity' => $tintOpacity,
        ];
    }

    /* =======================================================================
     * SUPPORT: per-role stats + auto params
     * =======================================================================
     */


/** Fast alpha-weighted mean L* under a mask (sub-sampled grid for speed) */
    private function maskedMeanLightness(\GdImage $baseIm, \GdImage $maskIm): float {
        $W = imagesx($baseIm); $H = imagesy($baseIm);
        $step = max(1, (int)floor(min($W, $H) / 400)); // ~160k samples worst-case
    $sum = 0.0; $wSum = 0.0;

    for ($y = 0; $y < $H; $y += $step) {
        for ($x = 0; $x < $W; $x += $step) {
            $m  = imagecolorat($maskIm, $x, $y);
            $ma = ($m & 0x7F000000) >> 24;          // 0..127 (127 transparent)
            $w  = 1.0 - ($ma / 127.0);              // 0..1
            if ($w <= 0.0) continue;

            $p  = imagecolorat($baseIm, $x, $y);
            $r0 = ($p >> 16) & 0xFF; $g0 = ($p >> 8) & 0xFF; $b0 = $p & 0xFF;
            [$L0] = $this->rgbToLab($r0, $g0, $b0);
            $sum  += $L0 * $w;
            $wSum += $w;
        }
    }
    return $wSum > 0.0 ? ($sum / $wSum) : 50.0;
}






    private function getRoleStatsByRole(int $photoId): array {
        if (!isset($this->roleStatsCache[$photoId])) {
            $by = [];
            try {
                $rows = $this->repo->getRoleStats($photoId);
                foreach ($rows as $r) {
                    $role = (string)$r['role'];
                    $by[$role] = $r;
                }
            } catch (\Throwable $e) {
                $by = [];
            }
            $this->roleStatsCache[$photoId] = $by;
        }
        return $this->roleStatsCache[$photoId];
    }

    /**
     * Pick lmix/alpha per role (stable defaults if stats missing).
     */
    private function autoParamsForRole(
        int $photoId,
        string $role,
        float $Lt,
        float $fallbackLmix = 0.30,
        float $fallbackAlpha = 0.92
    ): array {
        $stats = $this->getRoleStatsByRole($photoId);
        $Lb    = isset($stats[$role]['l_avg01']) ? (float)$stats[$role]['l_avg01'] : null;

        if ($Lb === null || $Lb <= 0.0) {
            $magGuess = abs($Lt - 60.0);
            $lmixGuess = min(1.0, max(0.18, 0.18 + 0.015 * $magGuess));
            $alphaGuess = ($Lt >= 92.0 || $Lt <= 8.0) ? 0.98 : $fallbackAlpha;
            return [
                'lmix' => $this->clamp01($lmixGuess),
                'alpha' => $this->clamp01($alphaGuess),
                'Lb' => null,
            ];
        }

        $d   = $Lt - $Lb;
        $mag = abs($d);

        // Scale pull strength by how different the target is
        $lmix = 0.18 + 0.015 * $mag;          // ΔL 30 → ~0.63
        if ($mag >= 35.0) $lmix = min(1.0, $lmix + 0.20);
        $lmix = $this->clamp01(max($fallbackLmix, $lmix));

        // Whites and blacks can go nearly full strength
        if ($Lt >= 90.0 && $mag >= 20.0) $lmix = min(1.0, max($lmix, 0.85));
        if ($Lt <= 10.0 && $mag >= 20.0) $lmix = min(1.0, max($lmix, 0.90));

        $alpha = 0.82 + min(0.15, $mag * 0.005);
        if ($mag >= 35.0) $alpha = 1.0;
        if ($Lt >= 92.0 || $Lt <= 8.0) $alpha = max($alpha, 0.98);
        $alpha = $this->clamp01(max($fallbackAlpha, $alpha));

        return ['lmix' => $lmix, 'alpha' => $alpha, 'Lb' => $Lb];
    }

    /* =======================================================================
     * PATHS / IO
     * =======================================================================
     */

    /** doc-root + rel; mkdirs if $ensureDir is true */
    private function absPath(string $rel, bool $ensureDir = false): string {
        $doc = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
        $rel = '/' . ltrim($rel, '/');
        $abs = $doc . $rel;
        if ($ensureDir) {
            $dir = dirname($abs);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
        }
        return $abs;
    }

    private function normalizeRelPath(string $rel): string {
        return '/' . ltrim($rel, '/');
    }

    /** http(s)://host + rel */
    private function absUrl(string $rel): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/' . ltrim($rel, '/');
    }

    /** Open PNG/JPG by extension */
    private function openImage(string $absPath) {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if ($ext === 'png')  return imagecreatefrompng($absPath);
        if ($ext === 'jpg' || $ext === 'jpeg') return imagecreatefromjpeg($absPath);
        return @imagecreatefromstring(@file_get_contents($absPath));
    }

    /** Create renders/<file>.jpg name that encodes roles+hex for cache-busting */
    private function buildRenderRelPath(string $assetId, array $hexMap): string {
        $slug = [];
        foreach ($hexMap as $role => $hex) {
            $role = preg_replace('~[^a-z0-9]+~i', '', (string)$role);
            $hex  = strtoupper(preg_replace('~[^0-9A-F]~i', '', (string)$hex));
            if ($role && $hex) $slug[] = "{$role}-{$hex}";
        }
        $slugStr = implode('_', $slug);
        $stamp   = date('Ymd_His');
        $file    = "{$assetId}_" . ($slugStr ?: 'map') . "_{$stamp}.jpg";
        return "/temp-renders/{$assetId}/{$file}";
    }

    private function createThumbnailFromRel(string $sourceRel, string $targetRel, int $maxWidth = 720, int $maxHeight = 720): ?array
    {
        $srcAbs = $this->absPath($sourceRel);
        if (!is_file($srcAbs)) return null;
        $src = $this->openImage($srcAbs);
        if (!$src) return null;
        $width = imagesx($src);
        $height = imagesy($src);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($src);
            return null;
        }
        $scale = min(1.0, $maxWidth / $width, $maxHeight / $height);
        $targetAbs = $this->absPath($targetRel, true);

        if ($scale >= 1.0) {
            if ($targetAbs !== $srcAbs) {
                @copy($srcAbs, $targetAbs);
            }
            imagedestroy($src);
            return ['width' => $width, 'height' => $height];
        }

        $newW = max(1, (int)round($width * $scale));
        $newH = max(1, (int)round($height * $scale));
        $thumb = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagejpeg($thumb, $targetAbs, 88);
        imagedestroy($thumb);
        imagedestroy($src);
        return ['width' => $newW, 'height' => $newH];
    }

    /* =======================================================================
     * COLOR / MATH HELPERS
     * =======================================================================
     */

    private function clamp255($v): int {
        $v = (int)round($v);
        if ($v < 0)   return 0;
        if ($v > 255) return 255;
        return $v;
    }
    private function clamp01($v): float {
        if (!is_finite($v)) return 0.0;
        if ($v < 0.0) return 0.0;
        if ($v > 1.0) return 1.0;
        return $v;
    }
    private function clamp(float $v, float $lo, float $hi): float { return max($lo, min($hi, $v)); }

    private function hexToRgb(string $hex6): array {
        return [hexdec(substr($hex6,0,2)), hexdec(substr($hex6,2,2)), hexdec(substr($hex6,4,2))];
    }

    // sRGB D65 <-> Lab
    private function rgbToLab(int $r, int $g, int $b): array {
        // sRGB -> linear
        $sr = $r / 255; $sg = $g / 255; $sb = $b / 255;
        $lr = ($sr <= 0.04045) ? ($sr / 12.92) : pow(($sr + 0.055) / 1.055, 2.4);
        $lg = ($sg <= 0.04045) ? ($sg / 12.92) : pow(($sg + 0.055) / 1.055, 2.4);
        $lb = ($sb <= 0.04045) ? ($sb / 12.92) : pow(($sb + 0.055) / 1.055, 2.4);
        // linear -> XYZ
        $X = 0.4124564*$lr + 0.3575761*$lg + 0.1804375*$lb;
        $Y = 0.2126729*$lr + 0.7151522*$lg + 0.0721750*$lb;
        $Z = 0.0193339*$lr + 0.1191920*$lg + 0.9503041*$lb;
        // normalize white
        $X/=0.95047; $Y/=1.00000; $Z/=1.08883;
        $f = function($t){ return $t > 0.008856 ? pow($t, 1/3) : (7.787*$t + 16/116); };
        $fx = $f($X); $fy = $f($Y); $fz = $f($Z);
        $L = (116*$fy - 16);
        $a = 500*($fx - $fy);
        $b2= 200*($fy - $fz);
        return [$L, $a, $b2];
    }

    private function labToRgb(float $L, float $a, float $b): array {
        // Lab -> XYZ
        $fy = ($L + 16) / 116;
        $fx = $fy + ($a / 500);
        $fz = $fy - ($b / 200);
        $invf = function($t){ $t3=$t*$t*$t; return $t3 > 0.008856 ? $t3 : (($t - 16/116)/7.787); };
        $X = 0.95047 * $invf($fx);
        $Y = 1.00000 * $invf($fy);
        $Z = 1.08883 * $invf($fz);
        // XYZ -> linear RGB
        $lr =  3.2404542*$X + (-1.5371385)*$Y + (-0.4985314)*$Z;
        $lg = -0.9692660*$X +  1.8760108*$Y +  0.0415560*$Z;
        $lb =  0.0556434*$X + (-0.2040259)*$Y +  1.0572252*$Z;
        // linear -> sRGB
        $c = function($u){
            $u = max(0.0, min(1.0, $u));
            return ($u <= 0.0031308) ? (12.92*$u) : (1.055*pow($u, 1/2.4) - 0.055);
        };
        $r = (int)round($c($lr)*255);
        $g = (int)round($c($lg)*255);
        $b2= (int)round($c($lb)*255);
        return [$this->clamp255($r), $this->clamp255($g), $this->clamp255($b2)];
    }

    private function applyBlendMode(array $baseRgb, array $topRgb, ?string $mode): array {
        $mode = strtolower($mode ?? 'hardlight');
        switch ($mode) {
            case 'colorize':
                return $topRgb;
            case 'multiply':
                return [
                    $this->blendMultiplyChannel($baseRgb[0], $topRgb[0]),
                    $this->blendMultiplyChannel($baseRgb[1], $topRgb[1]),
                    $this->blendMultiplyChannel($baseRgb[2], $topRgb[2]),
                ];
            case 'screen':
                return [
                    $this->blendScreenChannel($baseRgb[0], $topRgb[0]),
                    $this->blendScreenChannel($baseRgb[1], $topRgb[1]),
                    $this->blendScreenChannel($baseRgb[2], $topRgb[2]),
                ];
            case 'overlay':
                return [
                    $this->blendOverlayChannel($baseRgb[0], $topRgb[0]),
                    $this->blendOverlayChannel($baseRgb[1], $topRgb[1]),
                    $this->blendOverlayChannel($baseRgb[2], $topRgb[2]),
                ];
            case 'softlight':
                return [
                    $this->softLight($baseRgb[0], $topRgb[0]),
                    $this->softLight($baseRgb[1], $topRgb[1]),
                    $this->softLight($baseRgb[2], $topRgb[2]),
                ];
            case 'luminosity':
                [$Lb, $ab, $bb] = $this->rgbToLab($baseRgb[0], $baseRgb[1], $baseRgb[2]);
                [$Lt] = $this->rgbToLab($topRgb[0], $topRgb[1], $topRgb[2]);
                return $this->labToRgb($Lt, $ab, $bb);
            case 'hardlight':
            default:
                return [
                    $this->blendHardLightChannel($baseRgb[0], $topRgb[0]),
                    $this->blendHardLightChannel($baseRgb[1], $topRgb[1]),
                    $this->blendHardLightChannel($baseRgb[2], $topRgb[2]),
                ];
        }
    }

    private function blendMultiplyChannel(int $base, int $top): int {
        return $this->clamp255(($base * $top) / 255);
    }

    private function blendScreenChannel(int $base, int $top): int {
        return $this->clamp255(255 - ((255 - $base) * (255 - $top) / 255));
    }

    private function blendOverlayChannel(int $base, int $top): int {
        $b = $base / 255.0;
        $s = $top / 255.0;
        $out = ($b < 0.5)
            ? (2 * $b * $s)
            : (1 - 2 * (1 - $b) * (1 - $s));
        return $this->clamp255($out * 255.0);
    }

    private function blendHardLightChannel(int $base, int $top): int {
        $b = $base / 255.0;
        $s = $top / 255.0;
        $out = ($s < 0.5)
            ? (2 * $b * $s)
            : (1 - 2 * (1 - $b) * (1 - $s));
        return $this->clamp255($out * 255.0);
    }

    // Optional soft-light channel blend (kept for experiments)
    private function softLight(int $b, int $s): int {
        $B = $b/255.0; $S = $s/255.0;
        $out = ($S < 0.5)
            ? (2*$B*$S + $B*$B*(1-2*$S))
            : (2*$B*(1-$S) + sqrt($B)*(2*$S-1));
        return $this->clamp255($out*255.0);
    }
}
