<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoCategoryRepository;
use App\Lib\Logger;

/**
 * CategoriesService
 * ------------------
 * Recalculates all category assignments for every color in the database.
 * - Pulls live definitions from category_definitions.
 * - Honors calc_only (affects assignment but not visible labels).
 * - Writes results to color_category, colors, and clusters tables.
 * - Canonicalizes hue combos through hue_display.
 */
final class CategoriesService
{
    private const EPS = 1e-9;

    /** @var array<string,int> */
    private array $hueSinglesOrder = [];

    public function __construct(private PdoCategoryRepository $repo) {}

    /**
     * Run full recalc across all colors.
     * @param int  $batchSize        Number of colors per batch
     * @param bool $applyHueDisplay  Whether to canonicalize hue names/order via hue_display
     */
    public function recalcAll(int $batchSize = 2000, bool $applyHueDisplay = true): array
    {
        $defsByType = $this->repo->fetchCategoryDefinitions();
        $total = $this->repo->countColors();

        // Fetch wheel order for single hues so we sort combos deterministically
        $this->hueSinglesOrder = $this->repo->getHueSinglesOrderMap();

        Logger::info("CategoriesService: starting recalc for {$total} colors");

        $processed = 0;
        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            $batch = $this->repo->fetchColorBatch($offset, $batchSize);
            if (!$batch) break;

            foreach ($batch as $color) {
                $colorId = (int)$color['id'];
                $h = (float)$color['hcl_h'];
                $c = (float)$color['hcl_c'];
                $l = (float)$color['hcl_l'];

                [$catIds, $csvs] = $this->resolveCategories($h, $c, $l, $defsByType);

                // Write assignments + caches
                $this->repo->replaceColorCategories($colorId, $catIds);
                $this->repo->updateColorCachedCats(
                    $colorId,
                    $csvs['hue'],
                    $csvs['hue_order'],
                    $csvs['neutral'],
                    $csvs['light_cat_id'],
                    $csvs['chroma_cat_id']
                );

                // Optional: immediate canonicalization for per-color visibility
                if ($applyHueDisplay) {
                    $this->repo->applyHueDisplayForColor($colorId);
                }

                $processed++;
            }

            Logger::info("Processed {$processed}/{$total}");
        }

        // Rebuild cluster aggregates
        $this->repo->refreshClusterAggregates();

        // Final sweep canonicalization (safe no-op if already done per color)
        if ($applyHueDisplay) {
            $this->repo->applyHueDisplayCanonicalization();
        }

        Logger::info("CategoriesService: recalc complete");

        return [
            'total_colors'  => $total,
            'processed'     => $processed,
            'batch_size'    => $batchSize,
            'canonicalized' => $applyHueDisplay,
        ];
    }

    /**
     * Given an HCL triple, find all matching category definitions.
     * Returns [category_ids, csv_map].
     */
 private function resolveCategories(float $h, float $c, float $l, array $defsByType): array
{
    $catIds = [];
    $csv = ['hue' => [], 'neutral' => [], 'hue_order' => 999];

    $lightCandidates  = []; // ['id'=>int,'visible'=>bool,'sort_order'=>int]
    $chromaCandidates = [];

    foreach ($defsByType as $type => $defs) {
        foreach ($defs as $def) {
            if (!$this->inCategory($h, $c, $l, $def)) continue;

            $catIds[] = (int)$def['id'];
            $visible = ((int)$def['calc_only'] === 0);
            $sort    = (int)($def['sort_order'] ?? 9999);

            if ($type === 'hue' && $visible)       $csv['hue'][] = $def['name'];
            if ($type === 'neutral')               $csv['neutral'][] = $def['name'];   // ← drop $visible gate here

            if ($type === 'lightness')             $lightCandidates[]  = ['id'=>(int)$def['id'],'visible'=>$visible,'sort_order'=>$sort];
            if ($type === 'chroma')                $chromaCandidates[] = ['id'=>(int)$def['id'],'visible'=>$visible,'sort_order'=>$sort];
        }
    }

    // --- Deterministic wheel order for HUE (uses hue_display singles order loaded earlier) ---
    if (!empty($csv['hue'])) {
        $unique = array_values(array_unique($csv['hue']));
        $orderMap = $this->hueSinglesOrder; // set in recalcAll()
        usort($unique, static function(string $a, string $b) use ($orderMap): int {
            $oa = $orderMap[$a] ?? 9999;
            $ob = $orderMap[$b] ?? 9999;
            return ($oa <=> $ob) ?: strcmp($a, $b);
        });
        $csv['hue'] = $unique;
    }

    // --- Deterministic order for NEUTRALS; slash-join ---
    if (!empty($csv['neutral'])) {
        $unique = array_values(array_unique($csv['neutral']));

        // Build name → sort_order map from neutral definitions (visible only)
        $neutralOrder = [];
        foreach (($defsByType['neutral'] ?? []) as $def) {
            if ((int)($def['calc_only'] ?? 0) === 0) {
                $neutralOrder[(string)$def['name']] = (int)($def['sort_order'] ?? 9999);
            }
        }
        usort($unique, static function(string $a, string $b) use ($neutralOrder): int {
            $oa = $neutralOrder[$a] ?? 9999;
            $ob = $neutralOrder[$b] ?? 9999;
            return ($oa <=> $ob) ?: strcmp($a, $b);
        });

        // IMPORTANT: neutrals use slash, not comma
        $csv['neutral'] = $unique;
    }

    $pick = function(array $cands): ?int {
        if (!$cands) return null;
        usort($cands, fn($a,$b) =>
            ($a['visible'] !== $b['visible']) ? ($a['visible'] ? -1 : 1)
                                              : ($a['sort_order'] <=> $b['sort_order'])
        );
        return $cands[0]['id'];
    };

    return [
        $catIds,
        [
            'hue'           => implode(',', $csv['hue']),                // raw (canonicalized later)
            'hue_order'     => $csv['hue_order'],
            'neutral'       => implode('/', $csv['neutral']),            // <-- slash here
            'light_cat_id'  => $pick($lightCandidates),
            'chroma_cat_id' => $pick($chromaCandidates),
        ]
    ];
}

    private function inCategory(float $h, float $c, float $l, array $def): bool
    {
        return $this->inHueRange($h, $def['hue_min'], $def['hue_max'])
            && $this->inRange($c, $def['chroma_min'], $def['chroma_max'])
            && $this->inRange($l, $def['light_min'], $def['light_max']);
    }

    private function inRange(float $x, $min, $max): bool
    {
        if ($min === null && $max === null) return true;
        if ($min !== null && $x < (float)$min - self::EPS) return false;
        if ($max !== null && $x >= (float)$max - self::EPS) return false;
        return true;
    }

private function inHueRange(float $h, $min, $max): bool
{
    // If both null: no hue constraint
    if ($min === null && $max === null) return true;

    // Normalize a value into [0,360)
    $norm = static function(float $x): float {
        $y = fmod($x, 360.0);
        if ($y < 0) $y += 360.0;
        return $y;
    };

    // Normalize the test hue
    $H = $norm($h);

    // Handle one-sided bounds (still respect negatives by normalizing)
    if ($min !== null && $max === null) {
        $A = $norm((float)$min);
        // ">= min" in circular space == from A through wrap to A (i.e., whole circle),
        // but this case typically isn't used for hues; keep a sane interpretation:
        return ($H >= A) || ($A == 0.0); // if A==0, it's trivially true
    }
    if ($min === null && $max !== null) {
        $B = $norm((float)$max);
        return $H <= B || $B == 359.999; // conservative
    }

    // Two-sided interval, normalize both ends
    $A = $norm((float)$min);
    $B = $norm((float)$max);

    // Non-wrapping interval (e.g., 55..120)
    if ($A <= $B) {
        return ($H >= $A && $H <= $B);
    }

    // Wrapping interval (e.g., 350..20 OR -15..41→345..41)
    return ($H >= $A || $H <= $B);
}

}
