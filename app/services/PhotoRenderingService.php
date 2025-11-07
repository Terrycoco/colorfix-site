<?php
namespace App\Services;

use App\Repos\PdoPhotoRepository;
use RuntimeException;

class PhotoRenderingService
{
    private array $roleStatsCache = []; // [photoId => [role => statsRow]]

    public function __construct(
        private PdoPhotoRepository $repo,
        private \PDO $pdo
    ) {}

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
    float $alpha = 0.90
): array {
    // 1) Locate prepared + masks for this asset
    $photo = $this->repo->getPhotoByAssetId($assetId);
    if (!$photo) throw new \RuntimeException("Unknown asset_id: {$assetId}");
    $pid      = (int)$photo['id'];
    $variants = $this->repo->listVariants($pid);

    $preparedRel = '';
    $masksByRole = [];
    foreach ($variants as $v) {
        $kind = (string)($v['kind'] ?? '');
        if ($kind === 'prepared_base') {
            $preparedRel = (string)$v['path'];
        } elseif ($kind === 'mask' || str_starts_with($kind, 'mask')) {
            $role = (string)($v['role'] ?? '');
            if ($role !== '') $masksByRole[$role] = (string)$v['path'];
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

    $useFlat = ($mode === 'flat');

    // 3) Composite each role
    foreach ($hexMap as $role => $hex6raw) {
        $hex6 = strtoupper(trim((string)$hex6raw));
        if (!preg_match('/^[0-9A-F]{6}$/', $hex6)) continue;
        if (empty($masksByRole[$role]))     continue;

        $maskAbs = $this->absPath($masksByRole[$role]);
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

        $auto     = $this->autoParamsForRole($pid, $role, $Lt);
        $lmix     = $this->clamp01($auto['lmix'] ?? 0.35);
        $alphaForRole = $this->clamp01($auto['alpha'] ?? $alpha);
        $LbAuto   = $auto['Lb'] ?? null;
        [$detailKeep, $alphaCap] = $this->perColorDetailControl($hex6, $Lt);

        [$kCal, $bCal] = $this->getDeltaLCalib($role, $hex6, null);

        // Measure the prepared base directly for safety (handles fresh uploads)
        $LbRole = $this->averageLUnderMask($baseIm, $maskIm);
        $LbRef  = $LbAuto ?? $LbRole ?? 60.0;

        $deltaAgainstRef = abs($Lt - $LbRef);
        if ($deltaAgainstRef >= 35.0) {
            $lmix         = min(1.0, $lmix + 0.25);
            $alphaForRole = min(1.0, $alphaForRole + 0.15);
        }

        $effectiveAlpha = $this->clamp01($alphaForRole * $alpha * $alphaCap);

        // 4) Per-pixel
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                // Mask alpha (GD: 0..127, 127 transparent)
                $m  = imagecolorat($maskIm, $x, $y);
                $ma = ($m & 0x7F000000) >> 24;
                if ($ma >= 127) continue;

                $coverage = $this->clamp01((1.0 - ($ma / 127.0)) * $effectiveAlpha);

                // Base pixel
                $p  = imagecolorat($baseIm, $x, $y);
                $r0 = ($p >> 16) & 0xFF; $g0 = ($p >> 8) & 0xFF; $b0 = $p & 0xFF;

                if ($useFlat) {
                    $r1 = $rT; $g1 = $gT; $b1 = $bT;
                } else {
                    // Base Lab
                    [$L0, $a0, $b0lab] = $this->rgbToLab($r0, $g0, $b0);
                    $deltaL             = ($Lt - $L0) * $lmix;
                    $deltaL             = $deltaL * $kCal + $bCal;
                    $Lnew               = $this->clamp($L0 + $deltaL, -1.0, 101.0);

                    // Allow intentional pushes to full white/black without banding
                    if ($Lt >= 95.0) $Lnew = min(100.0, max($Lnew, 96.5));
                    if ($Lt <= 5.0)  $Lnew = max(0.0,  min($Lnew,  3.5));

                    if ($detailKeep > 0.0) {
                        $Lnew = ($Lnew * (1.0 - $detailKeep)) + ($L0 * $detailKeep);
                    }

                    // Back to RGB
                    [$r1, $g1, $b1] = $this->labToRgb($Lnew, $at, $bt);
                }

                // Blend
                $r = $this->clamp255($r0 * (1.0 - $coverage) + $r1 * $coverage);
                $g = $this->clamp255($g0 * (1.0 - $coverage) + $g1 * $coverage);
                $b = $this->clamp255($b0 * (1.0 - $coverage) + $b1 * $coverage);

                $col = imagecolorallocatealpha($baseIm, $r, $g, $b, 0);
                imagesetpixel($baseIm, $x, $y, $col);
            }
        }

        imagedestroy($maskIm);
    }

    // 5) Save render
    $outRel = $this->buildRenderRelPath($assetId, $hexMap);
    $outAbs = $this->absPath($outRel, true);
    imagejpeg($baseIm, $outAbs, 90);
    imagedestroy($baseIm);

    return [
        'ok'              => true,
        'render_rel_path' => $outRel,
        'render_url'      => $this->absUrl($outRel),
    ];
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

    /** Returns [detailKeep, alphaCap] per target color (higher detailKeep => more base shading retained). */
    private function perColorDetailControl(string $hex6, float $Lt): array {
        $hex6 = strtoupper($hex6);

        $detailKeep = 0.0;
        $alphaCap   = 1.0;

        // Heuristic tiers by perceived lightness; override with explicit map if needed.
        if ($Lt >= 92.0) {
            $detailKeep = 0.28;
            $alphaCap   = 0.88;
        } elseif ($Lt >= 82.0) {
            $detailKeep = 0.18;
            $alphaCap   = 0.93;
        } elseif ($Lt <= 8.0) {
            $detailKeep = 0.25;
            $alphaCap   = 0.90;
        } elseif ($Lt <= 20.0) {
            $detailKeep = 0.15;
            $alphaCap   = 0.94;
        }

        // Exact per-color overrides (edit as needed)
        $overrides = [
            'FFFFFF' => ['detailKeep' => 0.30, 'alphaCap' => 0.85],
            'FFF9F0' => ['detailKeep' => 0.26, 'alphaCap' => 0.88],
            '000000' => ['detailKeep' => 0.28, 'alphaCap' => 0.88],
        ];
        if (isset($overrides[$hex6])) {
            $detailKeep = $overrides[$hex6]['detailKeep'];
            $alphaCap   = $overrides[$hex6]['alphaCap'];
        }

        return [$this->clamp01($detailKeep), $this->clamp01($alphaCap)];
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
        return $this->renderApplyMap($assetId, [$role => $hex6], $mode, $alpha, 0.35);
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
        $yy   = date('Y');
        $ym   = date('Y-m');
        $slug = [];
        foreach ($hexMap as $role => $hex) {
            $role = preg_replace('~[^a-z0-9]+~i', '', (string)$role);
            $hex  = strtoupper(preg_replace('~[^0-9A-F]~i', '', (string)$hex));
            if ($role && $hex) $slug[] = "{$role}-{$hex}";
        }
        $slugStr = implode('_', $slug);
        $stamp   = date('Ymd_His');
        $file    = "{$assetId}_" . ($slugStr ?: 'map') . "_{$stamp}.jpg";
        return "/photos/{$yy}/{$ym}/{$assetId}/renders/{$file}";
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

    // Optional soft-light channel blend (kept for experiments)
    private function softLight(int $b, int $s): int {
        $B = $b/255.0; $S = $s/255.0;
        $out = ($S < 0.5)
            ? (2*$B*$S + $B*$B*(1-2*$S))
            : (2*$B*(1-$S) + sqrt($B)*(2*$S-1));
        return $this->clamp255($out*255.0);
    }
}
