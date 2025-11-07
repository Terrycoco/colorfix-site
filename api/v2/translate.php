<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoColorRepository;
use App\Repos\PdoSwatchRepository;
use App\Services\FindBestPerBrand;

/** Normalize a repo row (object with ->toArray() OR plain array) to array */
function row_to_array(mixed $row): array {
    if (is_object($row) && method_exists($row, 'toArray')) {
        /** @var object{toArray: callable} $row */
        return (array)$row->toArray();
    }
    return is_array($row) ? $row : [];
}

try {
    // Inputs (mirror v1 semantics)
    $sourceId = isset($_GET['source_id']) ? (int)$_GET['source_id'] : (int)($_GET['seed_id'] ?? 0);
    $metric   = strtolower((string)($_GET['metric'] ?? 'white')); // 'white' (preferred) or 'de'
    if ($sourceId <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Provide ?source_id=<id>'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!in_array($metric, ['white','de'], true)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>"Invalid metric '$metric' (use 'white' or 'de')"], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Repos / service
    $colorRepo  = new PdoColorRepository($pdo);
    $swatchRepo = new PdoSwatchRepository($pdo);
    $finder     = new FindBestPerBrand($colorRepo);

    // Source swatch (ALWAYS from swatch_view)
    $srcMap = $swatchRepo->getByIds([$sourceId]);   // may be arrays or entities
    $src    = $srcMap[$sourceId] ?? null;
    if (!$src) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>"Seed swatch not found: {$sourceId}"], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $srcArr = row_to_array($src);
    if (!isset($srcArr['hex']) && !empty($srcArr['hex6'])) {
        $srcArr['hex'] = '#' . ltrim((string)$srcArr['hex6'], '#');
    }

    // Default brands → ALL brands from swatch_view (exclude calibration brand 'true')
// Build brand list: all brands except calibration 'true' AND the source brand
$brandRows = $pdo->query("
    SELECT DISTINCT LOWER(brand) AS b
    FROM swatch_view
    WHERE brand IS NOT NULL AND brand <> ''
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$srcBrand = strtolower((string)($srcArr['brand'] ?? ''));

$allBrands = array_values(array_unique(array_filter(
    array_map(fn($r) => strtolower(trim((string)($r['b'] ?? ''))), $brandRows),
    fn($s) => $s !== '' && $s !== 'true' && $s !== $srcBrand
)));


    // Find one best candidate per brand (service handles distance scan)
    // NOTE: service excludes nothing by default; we will filter source + 'true' below.
    $best = $finder->run($sourceId, $allBrands, $metric, 5000); // scan cap ~ v1

    // Collect IDs for hydration from swatch_view
    $bestIds = [];
    foreach (($best['results'] ?? []) as $r) {
        $cid = (int)($r['id'] ?? 0);
        $b   = strtolower((string)($r['brand'] ?? ''));
        if ($cid <= 0) continue;
        if ($cid === $sourceId) continue;             // exclude EXACt source
        if ($b === 'true') continue;                  // exclude calibration brand
        $bestIds[] = $cid;
    }
    $bestIds = array_values(array_unique($bestIds));
    $svById  = $bestIds ? $swatchRepo->getByIds($bestIds) : [];

    // Shape for Gallery: single group “Closest by Brand”
    $items = [];
    foreach (($best['results'] ?? []) as $r) {
        $cid = (int)($r['id'] ?? 0);
        $b   = strtolower((string)($r['brand'] ?? ''));
        if ($cid <= 0) continue;
        if ($cid === $sourceId) continue;
        if ($b === 'true') continue;

        $row = $svById[$cid] ?? null;
        if (!$row) continue;

        $rowArr = row_to_array($row);
        if (!isset($rowArr['hex']) && !empty($rowArr['hex6'])) {
            $rowArr['hex'] = '#' . ltrim((string)$rowArr['hex6'], '#');
        }

        $items[] = [
            'group_header' => 'Closest by Brand',
            'group_order'  => 1,
            'delta_e'      => (float)($r['deltaE'] ?? $r['delta_e'] ?? 0.0),
            'is_twin'      => (bool)($r['is_twin'] ?? false),
            'color'        => $rowArr,                 // ALWAYS hydrated from swatch_view
        ];
    }

    echo json_encode([
        'ok'     => true,
        'source' => $srcArr,
        'items'  => $items,
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
