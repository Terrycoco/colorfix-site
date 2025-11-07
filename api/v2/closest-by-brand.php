<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoColorRepository;
use App\Repos\PdoSwatchRepository;
use App\Repos\PdoColorDetailRepository;
use App\Services\Rules;
use App\Services\ScoreCandidates;
use App\Services\FindBestPerBrand;
use App\Services\MatchingService;

try {
    // ---- Inputs ----
    $seedId    = isset($_GET['seed_id']) ? (int) $_GET['seed_id'] : 0;                 // MUST be a colors.id
    $brandsCsv = (string) ($_GET['brands'] ?? '');                                      // e.g. "ppg,sw,behr"
    $metric    = strtolower((string) ($_GET['metric'] ?? 'white'));                     // 'white' | 'de'

    if ($seedId <= 0 || $brandsCsv === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Provide ?seed_id=<color_id> and ?brands=<csv>'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Normalize brand list (lowercase, unique)
    $brands = array_values(array_unique(array_filter(
        array_map(static fn($b) => strtolower(trim($b)), explode(',', $brandsCsv)),
        static fn($b) => $b !== ''
    )));

    // Metric whitelist
    if (!in_array($metric, ['white', 'de'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Invalid metric '$metric' (use 'white' or 'de')"], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ---- Build SSOT (MatchingService) ----
    $colorRepo  = new PdoColorRepository($pdo);
    $swatchRepo = new PdoSwatchRepository($pdo);
    $detailRepo = new PdoColorDetailRepository($pdo);

    $rules    = class_exists(Rules::class) ? new Rules() : null;
    $scorer   = new ScoreCandidates($colorRepo);
    $perBrand = new FindBestPerBrand($colorRepo);

    $matching = new MatchingService($colorRepo, $swatchRepo, $detailRepo, $rules, $scorer, $perBrand);

    // ---- Source swatch (for UI "source" block) ----
    $srcMap = $swatchRepo->getByIds([$seedId]);
    $src    = $srcMap[$seedId] ?? null;
    if (!$src) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "Seed swatch not found: {$seedId}"], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $seedClusterId = (int) ($src->cluster_id ?? 0);

    // ---- SSOT per-brand (COLOR-seeded) ----
    // NOTE: per your rules, brand work starts from a COLOR id (not a cluster).
    $best = $matching->bestPerBrandFromColor($seedId, $brands, [
        'metric'      => $metric,
        'perBrandMax' => 5000,
    ]);

    // ---- Hydrate results from swatch_view ----
    $bestIds = [];
    foreach (($best['results'] ?? []) as $r) {
        $cid = (int) ($r['id'] ?? 0); // COLOR id
        if ($cid > 0 && $cid !== $seedId) $bestIds[] = $cid;
    }
    $bestIds = array_values(array_unique($bestIds));
    $svById  = $bestIds ? $swatchRepo->getByIds($bestIds) : [];

    // ---- Shape for Gallery: single group “Closest by Brand” ----
    $items = [];
    foreach (($best['results'] ?? []) as $r) {
        $cid = (int) ($r['id'] ?? 0); // COLOR id
        if ($cid === $seedId || $cid <= 0) continue;
        if (!isset($svById[$cid])) continue;

        $picked = $svById[$cid]->toArray();
        $pickedClusterId = (int) ($picked['cluster_id'] ?? 0);

        $items[] = [
            'group_header' => 'Closest by Brand',
            'group_order'  => 1,
            'delta_e'      => (float) ($r['deltaE'] ?? 0.0),
            // "Twin" = same-cluster as the seed (exact-hex bucket after rounding)
            'is_twin'      => ($seedClusterId > 0 && $pickedClusterId === $seedClusterId),
            'color'        => $picked, // swatch_view row as array
        ];
    }

    echo json_encode([
        'ok'     => true,
        'source' => $src->toArray(),
        'items'  => $items,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
