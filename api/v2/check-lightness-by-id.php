<?php
declare(strict_types=1);

/**
 * /api/dev/check-lightness-by-id.php
 *
 * Compare L* from your stored hex6 (via ColorCompute) vs L* derived from declared LRV.
 * Hard-coded cases include:
 *   - SW Trite White (id 29112, LRV ~80)
 *   - Behr Swiss Coffee (id 12799, LRV ~84)
 *
 * You can also override via querystring:
 *   ?id=29112&lrv=80
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\lib\ColorCompute;         // note: lowercase "lib" to match the file you sent
use App\Repos\PdoColorRepository; // repo casing per your v2 structure
use PDO;

function lstar_from_lrv(float $lrv): float {
    $y = max(0.0, min(1.0, $lrv / 100.0));
    return 116.0 * pow($y, 1.0/3.0) - 16.0;
}

/** Fetch {id, hex6} either via repo->getById(...) if available, else raw SQL */
function fetch_color(PDO $pdo, int $id): ?array {
    try {
        $repo = new PdoColorRepository($pdo);
        if (method_exists($repo, 'getById')) {
            $row = $repo->getById($id);
            if ($row) return ['id' => (int)$row['id'], 'hex6' => strtoupper((string)$row['hex6'])];
        }
    } catch (\Throwable $e) {
        // fall through to raw SQL
    }
    $stmt = $pdo->prepare('SELECT id, hex6 FROM colors WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'hex6' => strtoupper((string)$row['hex6'])] : null;
}

/** Core: compute Lab L* from hex6 */
function l_from_hex6(string $hex6): float {
    $hex = strtoupper(ltrim($hex6, '#'));
    if (!preg_match('/^[0-9A-F]{6}$/', $hex)) {
        throw new \InvalidArgumentException("Bad hex6: {$hex6}");
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $lab = ColorCompute::rgbToLab($r,$g,$b);
    return (float)$lab['L'];
}

/** Build case list (hard-coded + optional query override) */
$cases = [
    ['id' => 29112, 'declared_lrv' => 80.0, 'label' => 'SW Trite White'],
    ['id' => 12799, 'declared_lrv' => 84.0, 'label' => 'Behr Swiss Coffee'],
];

$idQ  = isset($_GET['id'])  ? (int)$_GET['id']  : 0;
$lrvQ = isset($_GET['lrv']) ? (float)$_GET['lrv'] : null;
if ($idQ > 0 && $lrvQ !== null) {
    $cases = [['id' => $idQ, 'declared_lrv' => $lrvQ, 'label' => "id {$idQ}"]];
}

try {
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) throw new \RuntimeException('PDO missing');

    $rows = [];
    foreach ($cases as $c) {
        $id   = (int)$c['id'];
        $lrv  = (float)$c['declared_lrv'];
        $tag  = (string)($c['label'] ?? "id {$id}");

        $row = fetch_color($pdo, $id);
        if (!$row) {
            $rows[] = ['id'=>$id, 'label'=>$tag, 'error' => 'Not found'];
            continue;
        }

        $hex6 = $row['hex6'];
        try {
            $L_hex = l_from_hex6($hex6);
        } catch (\Throwable $e) {
            $rows[] = ['id'=>$id, 'label'=>$tag, 'hex6'=>$hex6, 'error' => 'hex→Lab failed: '.$e->getMessage()];
            continue;
        }

        $L_lrv = lstar_from_lrv($lrv);
        $rows[] = [
            'id'            => $id,
            'label'         => $tag,
            'hex6'          => $hex6,
            'declared_lrv'  => $lrv,
            'L_from_LRV'    => round($L_lrv, 2),
            'L_from_hex'    => round($L_hex, 2),
            'delta_L'       => round($L_hex - $L_lrv, 2),
        ];
    }

    echo json_encode(['items' => $rows], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
