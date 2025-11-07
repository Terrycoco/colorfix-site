<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoColorRepository;

final class FindBestPerBrand
{
    public function __construct(private PdoColorRepository $repo) {}

    /**
     * Pick the closest candidate in EACH brand (excluding the seed),
     * using the same SSOT ranker (LRV-aware near-white logic).
     *
     * @param int $seedId
     * @param string[] $brands   Brand codes (lowercase in your DB)
     * @param string $metric     'white' (default) or 'de'
     * @param int $perBrandMax   (ignored here; we scan all in brand)
     * @return array{
     *   metric:string,
     *   seed: array,
     *   results: array<int, array{
     *     brand:string,
     *     id:int,
     *     deltaE: float,
     *     nearWhite: null,
     *     color: array
     *   }>
     * }
     */
    public function run(int $seedId, array $brands, string $metric = 'white', int $perBrandMax = 500): array
    {
        $metric = strtolower($metric) === 'de' ? 'de' : 'white';

        // Normalize brand list to your convention (lowercase)
        $brands = array_values(array_unique(array_filter(
            array_map(fn($b)=> strtolower(trim((string)$b)), $brands),
            fn($b)=> $b !== ''
        )));

        // Seed: prefer array row with cluster/lrv
        $seedRow = $this->repo->getColorWithCluster($seedId);
        if (!$seedRow || $seedRow['lab_l'] === null || $seedRow['lab_a'] === null || $seedRow['lab_b'] === null) {
            throw new \InvalidArgumentException("Seed color not found or missing LAB: {$seedId}");
        }

        $aL = (float)$seedRow['lab_l'];
        $aA = (float)$seedRow['lab_a'];
        $aB = (float)$seedRow['lab_b'];
        $seedLRV = isset($seedRow['lrv']) && is_numeric($seedRow['lrv']) ? (float)$seedRow['lrv'] : null;

        // Fallback seed array for output (if you need a consistent shape)
        $seedOut = $seedRow;

        $out = [];
        foreach ($brands as $brand) {
            // Full brand scan (no prefilter), exclude seed id; allow same-cluster exact-hex
            $rows = $this->repo->listAllCandidates(
                $excludeId = $seedId,
                $excludeClusterId = null,      // allow twins for cross-brand matching
                $brands = [$brand]
            );

            if (!$rows) continue;

            // Pass seed LRV to ranker via sentinel
            $rows['_seed_lrv'] = $seedLRV;

            // Use the single SSOT ranker (LRV-aware, near-white logic)
            $ranked = MatchingService::rankCandidates($aL, $aA, $aB, $rows, $metric);
            if (!$ranked) continue;

            $top = $ranked[0];

            // Compute ΔE2000 for reporting
            $dE = null;
            if (isset($top['lab_l'],$top['lab_a'],$top['lab_b'])) {
                $dE = \App\Lib\ColorDelta::deltaE2000($aL, $aA, $aB, (float)$top['lab_l'], (float)$top['lab_a'], (float)$top['lab_b']);
            }

            $out[] = [
                'brand'     => (string)($top['brand'] ?? $brand),
                'id'        => (int)($top['color_id'] ?? $top['id'] ?? 0),
                'deltaE'    => $dE !== null ? (float)$dE : 0.0,
                'nearWhite' => null,
                'color'     => $top,
            ];
        }

        return [
            'metric'  => $metric,
            'seed'    => $seedOut,
            'results' => $out,
        ];
    }
}
