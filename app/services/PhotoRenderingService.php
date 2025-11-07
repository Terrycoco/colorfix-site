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

        // ---- Auto-normalization params (per role) ----
        // Treat the role as if it had been prepped to a consistent mid-gray,
        // then paint the target color while preserving texture.
        $LbRole    = $this->averageLUnderMask($baseIm, $maskIm);  // mean L* under this role
        $Lneutral  = 60.0;   // “prepared gray” mean
        $kNorm     = 0.70;   // keep % of local contrast during normalization

        // Painting contrast keep (% deviating around the mean) – tuned by lightness
        $kPaint = ($Lt >= 85.0) ? 0.55 : (($Lt <= 30.0) ? 0.85 : 0.70);

        // 4) Per-pixel
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                // Mask alpha (GD: 0..127, 127 transparent)
                $m  = imagecolorat($maskIm, $x, $y);
                $ma = ($m & 0x7F000000) >> 24;
                if ($ma >= 127) continue;

                $coverage = $this->clamp01((1.0 - ($ma / 127.0)) * $alpha);

                // Base pixel
                $p  = imagecolorat($baseIm, $x, $y);
                $r0 = ($p >> 16) & 0xFF; $g0 = ($p >> 8) & 0xFF; $b0 = $p & 0xFF;

                if ($useFlat) {
                    $r1 = $rT; $g1 = $gT; $b1 = $bT;
                } else {
                    // Base Lab
                    [$L0, $a0, $b0lab] = $this->rgbToLab($r0, $g0, $b0);

                    // A) Normalize this role to mid-gray, preserving texture
                    // shift role mean to Lneutral; keep kNorm of local contrast
                    $offsetNorm = $Lneutral - $LbRole;
                    $Lnorm      = ($L0 + $offsetNorm) + $kNorm * ($L0 - $LbRole);

                    // B) Paint target: move mean to Lt; keep kPaint of local contrast
                    $offsetPaint = $Lt - $Lneutral;
                    $Lnew = ($Lnorm + $offsetPaint) + $kPaint * ($Lnorm - $Lneutral);

                    // Gentle caps to keep whites and blacks believable
                    if ($Lt > 85.0) $Lnew = min($Lnew, 97.0);  // protect highlights
                    if ($Lt < 30.0) $Lnew = max($Lnew,  8.0);  // avoid full crush
                    $Lnew = max(0.0, min(100.0, $Lnew));

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
        float $fallbackLmix = 0.25,
        float $fallbackAlpha = 0.90
    ): array {
        $stats = $this->getRoleStatsByRole($photoId);
        $Lb    = isset($stats[$role]['l_avg01']) ? (float)$stats[$role]['l_avg01'] : null;

        if ($Lb === null || $Lb <= 0.0) {
            return ['lmix' => $this->clamp01($fallbackLmix), 'alpha' => $this->clamp01($fallbackAlpha)];
        }

        $d   = $Lt - $Lb;
        $mag = abs($d);

        // Scale by how different the target is
        $lmix = 0.15 + 0.02 * $mag;        // ΔL 10 → ~0.35; ΔL 20 → ~0.55
        $lmix = max(0.12, min(0.55, $lmix));

        // Bright whites: avoid bleaching texture
        if ($Lt >= 85.0) $lmix = min($lmix, 0.18);
        // Very light base being darkened a lot — allow stronger pull
        if ($Lb >= 75.0 && $d < -12.0) $lmix = max($lmix, 0.40);
        // Very dark target over much lighter base — bump
        if ($Lt < 30.0 && $mag > 15.0) $lmix = max($lmix, 0.45);

        $alpha = $fallbackAlpha - ($mag >= 20.0 ? 0.05 : 0.0);
        $alpha = max(0.80, min(0.95, $alpha));

        return ['lmix' => $lmix, 'alpha' => $alpha];
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
