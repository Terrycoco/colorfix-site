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
    public function guessSettings(
        string $maskRole,
        int $colorId,
        int $k = 5,
        ?int $photoId = null,
        ?string $assetId = null,
        ?array &$debug = null
    ): ?array
    {
        if (!$photoId && $assetId) {
            $photoId = $this->fetchPhotoIdByAssetId($assetId);
        }
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
        $baseLightness = $this->fetchBaseLightness($photoId, $maskRole);

        $family = $this->roleFamily($maskRole);
        $approved = "approved = 1";

        $bestSamples = null;
        $debug = $debug ?? [];
        $debug['photo_id'] = $photoId;
        $debug['mask_role'] = $maskRole;
        $debug['color_id'] = $colorId;
        // priority 1: exact match same photo/mask/color
        if ($photoId) {
            $exact = $this->fetchSamples("photo_id = :pid AND mask_role = :role AND color_id = :cid AND {$approved}", [
                ':pid' => $photoId,
                ':role' => $maskRole,
                ':cid' => $colorId,
            ], 1);
            if ($exact) {
                $debug['branch'] = 'exact_approved';
                return $this->formatGuessFromSample($exact[0]);
            }
            $exact = $this->fetchSamples("photo_id = :pid AND mask_role = :role AND color_id = :cid", [
                ':pid' => $photoId,
                ':role' => $maskRole,
                ':cid' => $colorId,
            ], 1);
            if ($exact) {
                $debug['branch'] = 'exact_any';
                return $this->formatGuessFromSample($exact[0]);
            }
        }

        // priority 2: same photo + same mask (favor local samples before any global pool)
        if ($photoId) {
            $local = $this->fetchSamples("photo_id = :pid AND mask_role = :role AND {$approved}", [
                ':pid' => $photoId,
                ':role' => $maskRole,
            ], 500);
            $debug['local_approved_count'] = count($local);
            if (count($local) < 2) {
                $local = $this->fetchSamples("photo_id = :pid AND mask_role = :role", [
                    ':pid' => $photoId,
                    ':role' => $maskRole,
                ], 500);
            }
            $debug['local_any_count'] = count($local);
            if (count($local) >= 2) {
                $dominant = $this->dominantLocalSettings($local, $l);
                if ($dominant) {
                    $debug['branch'] = 'local_dominant';
                    return $dominant + ['neighbors_used' => $dominant['neighbors_used'] ?? 2, 'mask_role' => $maskRole, 'color_id' => $colorId];
                }
                $interp = $this->interpolateByLightness($local, $l);
                if ($interp) {
                    $debug['branch'] = 'local_interp';
                    return $interp + ['neighbors_used' => 2, 'mask_role' => $maskRole, 'color_id' => $colorId];
                }
                $nearest = $this->pickNearest($local, $h, $c, $l, $baseLightness, $k);
                if ($nearest) {
                    $debug['branch'] = 'local_nearest';
                    return $this->formatGuessFromSample($nearest);
                }
            } elseif (count($local) === 1) {
                $debug['branch'] = 'local_single';
                return $this->formatGuessFromSample($local[0]);
            }
        }

        $targets = [];
        if ($photoId) {
            $targets[] = ['where' => "photo_id = :pid AND role_family = :fam AND {$approved}", 'params' => [':pid' => $photoId, ':fam' => $family]];
        } else {
            $targets[] = ['where' => "mask_role = :role AND {$approved}", 'params' => [':role' => $maskRole]];
            $targets[] = ['where' => "role_family = :fam AND {$approved}", 'params' => [':fam' => $family]];
        }

        $best = null;
        foreach ($targets as $t) {
            if (!$t['where']) continue;
            $samples = $this->fetchSamples($t['where'], $t['params'], 500);
            if (!$samples) continue;
            $guess = $this->pickNearest($samples, $h, $c, $l, $baseLightness, $k);
            if ($guess) {
                $best = $guess;
                $bestSamples = $samples;
                $debug['branch'] = 'global_nearest';
                break;
            }
        }
        if (!$best || !$bestSamples) {
            // Fallback: most recent approved sample for this mask
            $latest = $this->latestApproved($maskRole, $photoId);
            if ($latest) {
                $debug['branch'] = 'latest';
                return $this->formatGuessFromSample($latest);
            }
            $debug['branch'] = 'heuristic';
            return $this->heuristicDefault($maskRole, $baseLightness, $l);
        }

        // Compute distances and pick top k
        $scored = [];
        foreach ($bestSamples as $sample) {
            $dist = $this->sampleDistance($sample, $h, $c, $l, $baseLightness);
            $scored[] = [$dist, $sample];
        }
        usort($scored, fn($a, $b) => $a[0] <=> $b[0]);
        $neighbors = array_slice($scored, 0, $k);

        if (!count($neighbors)) {
            return $this->heuristicDefault($maskRole, $baseLightness, $l);
        }

        $aggregate = $this->aggregateGuess($bestSamples, $h, $c, $l, $baseLightness, $k, $maskRole, $colorId);
        if ($aggregate) {
            $debug['branch'] = ($debug['branch'] ?? 'global_nearest') . ':aggregate';
            return $aggregate;
        }

        $debug['branch'] = ($debug['branch'] ?? 'global_nearest') . ':heuristic';
        return $this->heuristicDefault($maskRole, $baseLightness, $l);
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
            SELECT v.*,
                   CASE
                     WHEN v.mask_role LIKE '%body%' OR v.mask_role LIKE '%stucco%' OR v.mask_role LIKE '%siding%' THEN 'body'
                     WHEN v.mask_role LIKE '%trim%' OR v.mask_role LIKE '%fascia%' OR v.mask_role LIKE '%shutter%' OR v.mask_role LIKE '%garage%' OR v.mask_role LIKE '%door%' OR v.mask_role LIKE '%window%' THEN 'trim'
                     ELSE 'accent'
                   END AS role_family,
                   v.color_hex,
                   v.color_h AS hcl_h,
                   v.color_c AS hcl_c,
                   v.color_l AS hcl_l
            FROM vw_master_mask_blend v
            WHERE {$where}
            ORDER BY v.updated_at DESC
            LIMIT {$limit}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function pickNearest(array $samples, float $h, float $c, float $l, ?float $baseLightness, int $k = 5): ?array
    {
        if (!$samples) return null;
        $best = null;
        $bestDist = PHP_FLOAT_MAX;
        foreach ($samples as $row) {
            $dist = $this->sampleDistance($row, $h, $c, $l, $baseLightness);
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
        $usable = [];
        foreach ($samples as $row) {
            $sampleL = $this->sampleTargetL($row);
            if ($sampleL === null) continue;
            $row['_target_l'] = $sampleL;
            $usable[] = $row;
        }
        if (!$usable) return null;
        usort($usable, fn($a, $b) => $a['_target_l'] <=> $b['_target_l']);
        $lower = null;
        $upper = null;
        foreach ($usable as $row) {
            if ($row['_target_l'] <= $targetL) {
                $lower = $row;
            }
            if ($row['_target_l'] >= $targetL) {
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

        $l0 = (float)$lower['_target_l'];
        $l1 = (float)$upper['_target_l'];
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

    private function dominantLocalSettings(array $samples, float $targetL): ?array
    {
        if (count($samples) < 2) return null;

        $groups = [];
        foreach ($samples as $row) {
            $mode = $row['blend_mode'] ?? 'colorize';
            $opacity = isset($row['blend_opacity']) ? (float)$row['blend_opacity'] : null;
            $shadow = isset($row['shadow_l_offset']) ? (float)$row['shadow_l_offset'] : null;
            $tintHex = $row['shadow_tint_hex'] ?? null;
            $tintOpacity = isset($row['shadow_tint_opacity']) ? (float)$row['shadow_tint_opacity'] : null;
            $key = implode('|', [
                $mode,
                $opacity !== null ? sprintf('%.4f', $opacity) : 'null',
                $shadow !== null ? sprintf('%.2f', $shadow) : 'null',
                $tintHex ?? 'null',
                $tintOpacity !== null ? sprintf('%.4f', $tintOpacity) : 'null',
            ]);
            $entry = $groups[$key] ?? [
                'count' => 0,
                'minL' => null,
                'maxL' => null,
                'row' => $row,
            ];
            $entry['count'] += 1;
            $sampleL = $this->sampleTargetL($row);
            if ($sampleL !== null) {
                $entry['minL'] = $entry['minL'] === null ? $sampleL : min($entry['minL'], $sampleL);
                $entry['maxL'] = $entry['maxL'] === null ? $sampleL : max($entry['maxL'], $sampleL);
            }
            $groups[$key] = $entry;
        }

        if (!$groups) return null;
        usort($groups, function ($a, $b) {
            if ($a['count'] === $b['count']) return 0;
            return $a['count'] > $b['count'] ? -1 : 1;
        });
        $best = $groups[0];
        if (($best['count'] ?? 0) < 2) {
            return null;
        }

        $minL = $best['minL'];
        $maxL = $best['maxL'];
        if ($minL !== null && $maxL !== null) {
            $pad = 2.0;
            if ($targetL < ($minL - $pad) || $targetL > ($maxL + $pad)) {
                return null;
            }
        }

        $guess = $this->formatGuessFromSample($best['row']);
        $guess['neighbors_used'] = $best['count'];
        return $guess;
    }

    private function aggregateGuess(
        array $samples,
        float $h,
        float $c,
        float $l,
        ?float $baseLightness,
        int $k,
        string $maskRole,
        int $colorId
    ): ?array {
        if (!$samples) return null;

        $scored = [];
        foreach ($samples as $sample) {
            $dist = $this->sampleDistance($sample, $h, $c, $l, $baseLightness);
            $scored[] = [$dist, $sample];
        }
        usort($scored, fn($a, $b) => $a[0] <=> $b[0]);
        $neighbors = array_slice($scored, 0, $k);

        if (!count($neighbors)) {
            return null;
        }

        // Try lightness interpolation within the sample set (monotonic pattern)
        $interp = $this->interpolateByLightness($samples, $l);
        if ($interp) {
            return $interp + ['neighbors_used' => 2, 'mask_role' => $maskRole, 'color_id' => $colorId];
        }

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
        $shadowGuess = $sumWeights ? $sumShadowL / $sumWeights : null;
        $tintOpacityGuess = $sumWeights ? $sumShadowTintOp / $sumWeights : null;
        $adjusted = $this->adjustGuessForChroma($bestMode ?? 'multiply', $opacityGuess, $shadowGuess, $l, $c, $baseLightness);

        return [
            'mask_role' => $maskRole,
            'color_id' => $colorId,
            'blend_mode' => $bestMode ?? 'multiply',
            'blend_opacity' => $adjusted['blend_opacity'],
            'shadow_l_offset' => $adjusted['shadow_l_offset'],
            'shadow_tint_hex' => $tintHex,
            'shadow_tint_opacity' => $tintOpacityGuess,
            'neighbors_used' => count($neighbors),
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

    private function fetchBaseLightness(?int $photoId, string $maskRole): ?float
    {
        if (!$photoId) return null;
        $stmt = $this->pdo->prepare("
            SELECT l_avg01
            FROM photos_mask_stats
            WHERE photo_id = :photo_id AND role = :role
            LIMIT 1
        ");
        $stmt->execute([
            ':photo_id' => $photoId,
            ':role' => $maskRole,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $row['l_avg01'] !== null ? (float)$row['l_avg01'] : null;
    }

    private function fetchPhotoIdByAssetId(string $assetId): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM photos
            WHERE asset_id = :asset_id
            LIMIT 1
        ");
        $stmt->execute([':asset_id' => $assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($row['id'])) {
            return null;
        }
        return (int)$row['id'];
    }

    private function sampleTargetL(array $row): ?float
    {
        if (array_key_exists('color_l', $row) && $row['color_l'] !== null) {
            return (float)$row['color_l'];
        }
        if (array_key_exists('hcl_l', $row) && $row['hcl_l'] !== null) {
            return (float)$row['hcl_l'];
        }
        return null;
    }

    private function sampleTargetH(array $row): ?float
    {
        if (array_key_exists('color_h', $row) && $row['color_h'] !== null) {
            return (float)$row['color_h'];
        }
        if (array_key_exists('hcl_h', $row) && $row['hcl_h'] !== null) {
            return (float)$row['hcl_h'];
        }
        return null;
    }

    private function sampleTargetC(array $row): ?float
    {
        if (array_key_exists('color_c', $row) && $row['color_c'] !== null) {
            return (float)$row['color_c'];
        }
        if (array_key_exists('hcl_c', $row) && $row['hcl_c'] !== null) {
            return (float)$row['hcl_c'];
        }
        return null;
    }

    private function hueDelta(float $a, float $b): float
    {
        $d = abs($a - $b);
        return $d > 180 ? 360 - $d : $d;
    }

    private function sampleDistance(array $row, float $h, float $c, float $l, ?float $baseLightness): float
    {
        $sampleH = $this->sampleTargetH($row) ?? $h;
        $sampleC = $this->sampleTargetC($row) ?? $c;
        $sampleL = $this->sampleTargetL($row) ?? $l;

        $dh = $this->hueDelta($sampleH, $h);
        $dc = $sampleC - $c;
        $dl = $sampleL - $l;
        $dlWeight = ($sampleL > $l) ? 3.0 : 2.7;
        $hScale = max(0.1, min(1.0, $c / 30.0));

        $sum = ($dh * 0.25 * $hScale) * ($dh * 0.25 * $hScale)
            + ($dc * 0.45) * ($dc * 0.45)
            + ($dl * $dlWeight) * ($dl * $dlWeight);

        $sampleBase = isset($row['base_lightness']) && $row['base_lightness'] !== null
            ? (float)$row['base_lightness']
            : null;
        if ($baseLightness !== null && $sampleBase !== null) {
            $db = $sampleBase - $baseLightness;
            $sum += ($db * 1.2) * ($db * 1.2);
        }

        return sqrt($sum);
    }

    private function adjustGuessForChroma(string $mode, ?float $opacity, ?float $shadowL, float $targetL, float $targetC, ?float $baseL): array
    {
        $nextOpacity = $opacity;
        $nextShadow = $shadowL;

        if ($mode === 'multiply' && $nextShadow !== null) {
            $chromaBoost = max(0.0, min(1.0, ($targetC - 30.0) / 35.0));
            $lightnessGate = $targetL <= 35.0 ? 0.0 : ($targetL <= 55.0 ? 0.5 : 1.0);
            $reduce = $chromaBoost * $lightnessGate;
            if ($reduce > 0.0 && $nextShadow < 0) {
                $nextShadow = $nextShadow * (1.0 - (0.65 * $reduce));
                if ($nextShadow > -0.5) $nextShadow = 0.0;
            }
        }

        if ($mode === 'multiply' && $nextOpacity !== null && $baseL !== null) {
            $delta = $targetL - $baseL;
            if ($delta > 20.0 && $nextOpacity > 0.85) {
                $nextOpacity = 0.85;
            }
            if ($delta < -25.0 && $nextOpacity < 0.75) {
                $nextOpacity = 0.75;
            }
        }

        return [
            'blend_opacity' => $nextOpacity,
            'shadow_l_offset' => $nextShadow,
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
