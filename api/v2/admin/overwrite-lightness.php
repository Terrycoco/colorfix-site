<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

// File: /api/v2/admin/overwrite-lightness.php
// Purpose: Overwrite Lab/HCL lightness (L*) from LRV for Whites, and reassign cluster_id.
// Usage examples:
//   /api/v2/admin/overwrite-lightness.php?ids=12799,29112              (dry run)
//   /api/v2/admin/overwrite-lightness.php?ids=12799,29112&commit=1     (write+recluster)
//   /api/v2/admin/overwrite-lightness.php?brand=de                      (dry run)
//   /api/v2/admin/overwrite-lightness.php?brand=de&commit=1             (write+recluster)
// Params:
//   brand=CODE | ids=1,2,3   (one required)
//   commit=1                 (write; default dry-run)
//   strict=1                 (enable near-white gate L*>=lmin & C*<=cmax; default: trust Whites bucket)
//   lmin=70, cmax=7, dead=0.5

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use PDO;
use App\Repos\PdoClusterRepository;
use App\lib\ColorCompute; 


// --- helpers ---
function lstar_from_lrv(float $lrv): float {
    $Y = max(0.0, min(1.0, $lrv / 100.0));
    $eps = pow(6/29, 3); // ~0.008856
    if ($Y > $eps) return 116.0 * pow($Y, 1.0/3.0) - 16.0;
    return (841.0/108.0) * $Y + 4.0/29.0;
}

try {
    /** @var PDO|null $pdo */
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('PDO missing (db.php must set $GLOBALS["pdo"])');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- params ---
    $brand    = isset($_GET['brand'])  ? trim((string)$_GET['brand']) : '';
    $idsArg   = isset($_GET['ids'])    ? trim((string)$_GET['ids'])   : '';
    $commit   = isset($_GET['commit']) ? (int)$_GET['commit']         : 0;  // 0 = dry-run
    $strict   = isset($_GET['strict']) ? (int)$_GET['strict']         : 0;  // 1 = gate
    $lMin     = isset($_GET['lmin'])   ? (float)$_GET['lmin']         : 70.0;
    $cMax     = isset($_GET['cmax'])   ? (float)$_GET['cmax']         : 7.0;
    $deadband = isset($_GET['dead'])   ? (float)$_GET['dead']         : 0.5;

    if ($brand === '' && $idsArg === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Provide ?brand=<code> or ?ids=1,2,3'], JSON_PRETTY_PRINT);
        exit;
    }

    // --- fetch set ---
    if ($idsArg !== '') {
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsArg)), fn($v)=>$v>0));
        if (!$ids) { http_response_code(400); echo json_encode(['error'=>'Bad ids']); exit; }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, brand, name, hex6, lrv, lab_l, lab_a, lab_b, hcl_l, orig_lab_l, orig_hcl_l,
                       COALESCE(neutral_cats,'') AS neutral_cats,
                       cluster_id
                FROM colors
                WHERE id IN ($ph)
                ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        foreach ($ids as $i => $val) $stmt->bindValue($i+1, $val, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        // brand-scoped: trust Whites bucket
        $sql = "SELECT id, brand, name, hex6, lrv, lab_l, lab_a, lab_b, hcl_l, orig_lab_l, orig_hcl_l,
                       COALESCE(neutral_cats,'') AS neutral_cats,
                       cluster_id
                FROM colors
                WHERE brand = ?
                  AND COALESCE(neutral_cats,'') LIKE '%Whites%'
                ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $brand, PDO::PARAM_STR);
        $stmt->execute();
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // --- writers ---
    $bak = $pdo->prepare("
        UPDATE colors
           SET orig_lab_l = COALESCE(orig_lab_l, lab_l),
               orig_hcl_l = COALESCE(orig_hcl_l, hcl_l)
         WHERE id = :id
    ");
    $updL = $pdo->prepare("
        UPDATE colors
           SET lab_l = :L_eff1,
               hcl_l = :L_eff2
         WHERE id = :id
    ");

    // cluster repo
    $clusterRepo = new PdoClusterRepository($pdo);

    if ($commit) $pdo->beginTransaction();

    $out = [];
    $changed = 0; $skipped = 0; $noLrv = 0; $clusterChanged = 0;

    foreach ($rows as $r) {
        $id   = (int)$r['id'];
        $name = (string)$r['name'];

        // hex sanity
        $hex6 = strtoupper((string)$r['hex6']);
        if (!preg_match('/^[0-9A-F]{6}$/', $hex6)) {
            $out[] = ['id'=>$id,'name'=>$name,'status'=>'skip','reason'=>'bad hex'];
            $skipped++; continue;
        }

        // need Lab present
        $L = isset($r['lab_l']) ? (float)$r['lab_l'] : null;
        $a = isset($r['lab_a']) ? (float)$r['lab_a'] : null;
        $b = isset($r['lab_b']) ? (float)$r['lab_b'] : null;
        if (!isset($L,$a,$b)) {
            $out[] = ['id'=>$id,'name'=>$name,'status'=>'skip','reason'=>'missing Lab'];
            $skipped++; continue;
        }

        // need LRV
        $hasLrv = isset($r['lrv']) && $r['lrv'] !== '' && $r['lrv'] !== null;
        if (!$hasLrv) {
            $out[] = ['id'=>$id,'name'=>$name,'status'=>'skip','reason'=>'no LRV'];
            $noLrv++; continue;
        }
        $lrv = (float)$r['lrv'];

        // optional gate
        $C = hypot($a, $b);
        if ($strict) {
            if (!($L >= $lMin && $C <= $cMax)) {
                $out[] = ['id'=>$id,'name'=>$name,'status'=>'skip','reason'=>'not near-white','L'=>$L,'C'=>round($C,3)];
                $skipped++; continue;
            }
        } else {
            // loose sanity only
            if ($L < 60 || $C > 15) {
                $out[] = ['id'=>$id,'name'=>$name,'status'=>'skip','reason'=>'out-of-sanity-range','L'=>$L,'C'=>round($C,3)];
                $skipped++; continue;
            }
        }

        // compute effective L* from LRV
        $L_eff = lstar_from_lrv($lrv);
        $delta = $L_eff - $L;

        // ignore tiny differences
        if (abs($delta) < $deadband) {
            $out[] = [
                'id'=>$id,'name'=>$name,'lrv'=>$lrv,
                'raw_L'=>$L,'eff_L'=>$L_eff,'delta_L'=>round($delta,3),
                'status'=>'nochange (deadband)'
            ];
            continue;
        }

        // we will update L*, then recompute/assign cluster_id
        $cluster_from = isset($r['cluster_id']) ? (int)$r['cluster_id'] : 0;
        $cluster_to   = $cluster_from;

        if ($commit) {
            // backup originals once
            $bak->bindValue(':id', $id, PDO::PARAM_INT);
            $bak->execute();
            $bak->closeCursor();

            // overwrite L* (Lab/HCL)
            $updL->bindValue(':L_eff1', $L_eff, PDO::PARAM_STR);
            $updL->bindValue(':L_eff2', $L_eff, PDO::PARAM_STR);
            $updL->bindValue(':id',     $id,    PDO::PARAM_INT);
            $updL->execute();
            $updL->closeCursor();

            // Only set a white-display hex when it's actually a White with an LRV and we changed L*
            $inWhites = strpos((string)($r['neutral_cats'] ?? ''), 'Whites') !== false;

            if ($inWhites && $hasLrv && abs($delta) >= $deadband) {
                $hx = ColorCompute::labToHex6($L_eff, $a, $b); // corrected L*, same a*,b*
                $u  = $pdo->prepare("UPDATE colors SET hex6_white = :hx WHERE id = :id");
                $u->execute([':hx' => $hx, ':id' => $id]);
            } elseif ($commit) {
                // If no material change (or not a White), ensure we fall back to vendor hex
                $pdo->prepare("UPDATE colors SET hex6_white = NULL WHERE id = :id")
                    ->execute([':id' => $id]);
            }



            // reassign cluster_id from (rounded) HCL
            // (uses colors.hcl_h/c/l which now has updated hcl_l)
            $cluster_to = $clusterRepo->assignClusterForColorId($id) ?? $cluster_from;
            if ($cluster_to !== $cluster_from) $clusterChanged++;

            $changed++;
            $status = 'updated + reclustered';
        } else {
            // dry-run: predict rounded-to cluster without writing
            // we have current H, C, but L will be L_eff; emulate the rounding
            $h_r = (int)(((floor((float)$r['hcl_h'] + 0.5)) % 360 + 360) % 360);
            $c_r = (int)floor((float)$r['hcl_c'] + 0.5);
            $l_r = (int)floor($L_eff + 0.5);
            // NOTE: the actual id will be looked up/ensured by repo when committed
            $status = 'would-update (+ would-recluster)';
        }

        $out[] = [
            'id'     => $id,
            'name'   => $name,
            'lrv'    => $lrv,
            'raw_L'  => $L,
            'eff_L'  => $L_eff,
            'delta_L'=> round($delta,3),
            'cluster_from' => $cluster_from,
            'cluster_to'   => $commit ? $cluster_to : '(predicted via rounded HCL)',
            'status' => $status
        ];
    }

    if ($commit && $pdo->inTransaction()) $pdo->commit();

    echo json_encode([
        'params'  => [
            'brand'    => $brand,
            'ids'      => $idsArg,
            'strict'   => $strict,
            'lmin'     => $lMin,
            'cmax'     => $cMax,
            'deadband' => $deadband,
            'commit'   => $commit
        ],
        'summary' => [
            'rows'            => count($rows),
            'changed'         => $changed,
            'cluster_changed' => $clusterChanged,
            'skipped'         => $skipped,
            'noLrv'           => $noLrv
        ],
        'items'   => $out
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
