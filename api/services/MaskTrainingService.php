<?php
declare(strict_types=1);

// Shared helper for training/guessing mask settings.

namespace App\Services;

use PDO;

class MaskTrainingService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Return starter tester colors with swatch details.
     */
    public function getTesterColors(): array
    {
        $sql = "SELECT tc.id, tc.color_id, tc.name, tc.note,
                       sv.hex6 AS color_hex, sv.hcl_h, sv.hcl_c, sv.hcl_l,
                       sv.brand, sv.code
                FROM tester_colors tc
                LEFT JOIN swatch_view sv ON sv.id = tc.color_id
                ORDER BY tc.id ASC";
        $stmt = $this->pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Guess settings for mask_role + color_id using nearest mask_blend_settings samples.
     */
    public function guessSettings(string $maskRole, int $colorId, int $k = 5, ?int $photoId = null, ?string $assetId = null): ?array
    {
        // Get target color H/C/L
        $cStmt = $this->pdo->prepare("SELECT hcl_h, hcl_c, hcl_l FROM swatch_view WHERE id = :id");
        $cStmt->execute([':id' => $colorId]);
        $target = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            return null;
        }

        $h = (float)($target['hcl_h'] ?? 0);
        $c = (float)($target['hcl_c'] ?? 0);
        $l = (float)($target['hcl_l'] ?? 0);

        $family = $this->roleFamily($maskRole);
        $approved = "approved = 1";

        $bestSamples = null;
        // priority 1: exact match same photo/mask/color
        if ($photoId) {
            $exact = $this->fetchSamples("photo_id = :pid AND mask_role = :role AND color_id = :cid AND {$approved}", [
                ':pid' => $photoId,
                ':role' => $maskRole,
                ':cid' => $colorId,
            ], 1);
            if ($exact) {
                return $this->formatGuessFromSample($exact[0]);
            }
        }

        $targets = [
            ['where' => $photoId ? "photo_id = :pid AND mask_role = :role AND {$approved}" : null, 'params' => [':pid' => $photoId, ':role' => $maskRole]],
            ['where' => $photoId ? "photo_id = :pid AND role_family = :fam AND {$approved}" : null, 'params' => [':pid' => $photoId, ':fam' => $family]],
            ['where' => "mask_role = :role AND {$approved}", 'params' => [':role' => $maskRole]],
            ['where' => "role_family = :fam AND {$approved}", 'params' => [':fam' => $family]],
        ];

        $best = null;
        foreach ($targets as $t) {
            if (!$t['where']) continue;
            $samples = $this->fetchSamples($t['where'], $t['params'], 500);
            if (!$samples) continue;
            $guess = $this->pickNearest($samples, $h, $c, $l, $k);
            if ($guess) {
                $best = $guess;
                $bestSamples = $samples;
                break;
            }
        }
        if (!$best || !$bestSamples) {
            // Fallback: most recent approved sample for this mask
            $latest = $this->latestApproved($maskRole, $photoId);
            if ($latest) {
                return $this->formatGuessFromSample($latest);
            }
            return $this->heuristicDefault($maskRole, null, $l);
        }

        // Compute distances and pick top k
        $scored = [];
        foreach ($bestSamples as $sample) {
            $dh = (float)$sample['target_h'] - $h;
            $dc = (float)$sample['target_c'] - $c;
            $dl = (float)$sample['target_lightness'] - $l;
            // Heavily weight lightness; penalize when sample is lighter than desired
            $dlWeight = ($sample['target_lightness'] > $l) ? 3.0 : 2.7;
            $dist = sqrt(($dh * 0.3) * ($dh * 0.3) + ($dc * 0.3) * ($dc * 0.3) + ($dl * $dlWeight) * ($dl * $dlWeight));
            $scored[] = [$dist, $sample];
        }
        usort($scored, fn($a, $b) => $a[0] <=> $b[0]);
        $neighbors = array_slice($scored, 0, $k);

        if (!count($neighbors)) {
            return $this->heuristicDefault($maskRole, null, $l);
        }

        // Try lightness interpolation within the best sample set (monotonic pattern)
        $interp = $this->interpolateByLightness($bestSamples, $l);
        if ($interp) {
            return $interp + ['neighbors_used' => 2, 'mask_role' => $maskRole, 'color_id' => $colorId];
        }

        // Aggregate guess
        $modeCounts = [];
        $sumOpacity = 0;
        $sumShadowL = 0;
        $sumShadowTintOp = 0;
        $sumWeights = 0;
        $tintHex = null;

        foreach ($neighbors as [$dist, $row]) {
            $w = 1 / (1 + $dist); // inverse distance
            $mode = $row['blend_mode'] ?: 'colorize';
            $modeCounts[$mode] = ($modeCounts[$mode] ?? 0) + $w;

            $sumOpacity += $w * (float)($row['blend_opacity'] ?? 0.5);
            $sumShadowL += $w * (float)($row['shadow_l_offset'] ?? 0);
            $sumShadowTintOp += $w * (float)($row['shadow_tint_opacity'] ?? 0);
            if (!$tintHex && !empty($row['shadow_tint_hex'])) {
                $tintHex = $row['shadow_tint_hex'];
            }
            $sumWeights += $w;
        }

        arsort($modeCounts);
        $bestMode = array_key_first($modeCounts);

        $opacityGuess = $sumWeights ? $sumOpacity / $sumWeights : null;
        // Keep multiply strong for non-dark targets; only step opacity down when the target is very dark.
        if (($bestMode ?? 'multiply') === 'multiply' && $opacityGuess !== null) {
            if ($l >= 20 && $opacityGuess < 0.9) {
                $opacityGuess = 0.9;
            }
        }

        return [
            'mask_role' => $maskRole,
            'color_id' => $colorId,
            'blend_mode' => $bestMode ?? 'multiply',
            'blend_opacity' => $opacityGuess,
            'shadow_l_offset' => $sumWeights ? $sumShadowL / $sumWeights : null,
            'shadow_tint_hex' => $tintHex,
            'shadow_tint_opacity' => $sumWeights ? $sumShadowTintOp / $sumWeights : null,
            'neighbors_used' => count($neighbors),
        ];
    }

    private function roleFamily(string $maskRole): string
    {
        $r = strtolower($maskRole);
        if (str_contains($r, 'body') || str_contains($r, 'stucco') || str_contains($r, 'siding')) return 'body';
        if (str_contains($r, 'trim') || str_contains($r, 'fascia') || str_contains($r, 'shutter') || str_contains($r, 'garage') || str_contains($r, 'door') || str_contains($r, 'window')) return 'trim';
        return 'accent';
    }

    private function fetchSamples(string $where, array $params, int $limit): array
    {
        $sql = "
            SELECT mbs.*,
                   CASE
                     WHEN mbs.mask_role LIKE '%body%' OR mbs.mask_role LIKE '%stucco%' OR mbs.mask_role LIKE '%siding%' THEN 'body'
                     WHEN mbs.mask_role LIKE '%trim%' OR mbs.mask_role LIKE '%fascia%' OR mbs.mask_role LIKE '%shutter%' OR mbs.mask_role LIKE '%garage%' OR mbs.mask_role LIKE '%door%' OR mbs.mask_role LIKE '%window%' THEN 'trim'
                     ELSE 'accent'
                   END AS role_family,
                   COALESCE(NULLIF(mbs.color_hex,''), sv.hex6) AS color_hex,
                   sv.hcl_h, sv.hcl_c, sv.hcl_l
            FROM mask_blend_settings mbs
            LEFT JOIN swatch_view sv ON sv.id = mbs.color_id
            WHERE {$where}
            ORDER BY mbs.updated_at DESC
            LIMIT {$limit}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function pickNearest(array $samples, float $h, float $c, float $l, int $k = 5): ?array
    {
        if (!$samples) return null;
        $best = null;
        $bestDist = PHP_FLOAT_MAX;
        foreach ($samples as $row) {
            $dh = (float)$row['target_h'] - $h;
            $dc = (float)$row['target_c'] - $c;
            $dl = (float)$row['target_lightness'] - $l;
            $dlWeight = ($row['target_lightness'] > $l) ? 3.0 : 2.7;
            $dist = sqrt(($dh * 0.3) * ($dh * 0.3) + ($dc * 0.3) * ($dc * 0.3) + ($dl * $dlWeight) * ($dl * $dlWeight));
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $row;
            }
        }
        return $best;
    }

    /**
     * Linearly interpolate settings between nearest lower/upper samples by target_lightness.
     */
    private function interpolateByLightness(array $samples, float $targetL): ?array
    {
        if (!$samples) return null;
        usort($samples, fn($a, $b) => $a['target_lightness'] <=> $b['target_lightness']);
        $lower = null;
        $upper = null;
        foreach ($samples as $row) {
            if ($row['target_lightness'] <= $targetL) {
                $lower = $row;
            }
            if ($row['target_lightness'] >= $targetL) {
                $upper = $row;
                break;
            }
        }
        if (!$lower || !$upper) {
            return null;
        }
        if ($lower['id'] === $upper['id']) {
            return $this->formatGuessFromSample($lower);
        }

        $l0 = (float)$lower['target_lightness'];
        $l1 = (float)$upper['target_lightness'];
        if ($l1 === $l0) {
            return $this->formatGuessFromSample($lower);
        }
        $t = max(0.0, min(1.0, ($targetL - $l0) / ($l1 - $l0)));

        $blendSame = ($lower['blend_mode'] === $upper['blend_mode']) ? $lower['blend_mode'] : $upper['blend_mode'];
        return [
            'blend_mode' => $blendSame ?? $lower['blend_mode'] ?? 'colorize',
            'blend_opacity' => ((float)$lower['blend_opacity']) + $t * (((float)$upper['blend_opacity']) - ((float)$lower['blend_opacity'])),
            'shadow_l_offset' => ((float)($lower['shadow_l_offset'] ?? 0)) + $t * (((float)($upper['shadow_l_offset'] ?? 0)) - ((float)($lower['shadow_l_offset'] ?? 0))),
            'shadow_tint_hex' => $t < 0.5 ? ($lower['shadow_tint_hex'] ?? null) : ($upper['shadow_tint_hex'] ?? null),
            'shadow_tint_opacity' => ((float)($lower['shadow_tint_opacity'] ?? 0)) + $t * (((float)($upper['shadow_tint_opacity'] ?? 0)) - ((float)($lower['shadow_tint_opacity'] ?? 0))),
        ];
    }

    private function formatGuessFromSample(array $row): array
    {
        return [
            'mask_role' => $row['mask_role'],
            'color_id' => $row['color_id'],
            'blend_mode' => $row['blend_mode'],
            'blend_opacity' => $row['blend_opacity'],
            'shadow_l_offset' => $row['shadow_l_offset'],
            'shadow_tint_hex' => $row['shadow_tint_hex'],
            'shadow_tint_opacity' => $row['shadow_tint_opacity'],
            'neighbors_used' => 1,
        ];
    }

    /**
     * Heuristic fallback when no usable samples exist.
     * Prefer strong multiply; reduce opacity only for darker bases/targets.
     */
    private function heuristicDefault(string $maskRole, ?float $baseLightness, ?float $targetL): array
    {
        $refL = $targetL ?? $baseLightness ?? 60;
        $opacity = 1.0;
        if ($refL < 30) {
            $opacity = 0.55;
        } elseif ($refL < 45) {
            $opacity = 0.7;
        } elseif ($refL < 60) {
            $opacity = 0.85;
        }
        return [
            'mask_role' => $maskRole,
            'color_id' => null,
            'blend_mode' => 'multiply',
            'blend_opacity' => $opacity,
            'shadow_l_offset' => 0,
            'shadow_tint_hex' => null,
            'shadow_tint_opacity' => 0,
            'neighbors_used' => 0,
        ];
    }
}
