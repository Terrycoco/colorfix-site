<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use PDO;
use App\Lib\ColorCompute;

/** L* from LRV (Y = LRV/100) */
function lstar_from_lrv(?float $lrv): ?float {
    if ($lrv === null) return null;
    $y = max(0.0, min(1.0, $lrv / 100.0));
    return 116.0 * pow($y, 1.0/3.0) - 16.0;
}

function angle_deg(float $a, float $b): float {
    $h = atan2($b, $a) * 180 / M_PI;
    $h = fmod($h + 360.0, 360.0);
    return $h;
}

try {
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) throw new RuntimeException('PDO missing');

    // ---------- Params ----------
    $idsArg   = isset($_GET['ids']) ? trim((string)$_GET['ids']) : '';
    $brand    = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
    $limit    = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 200;
    $whites   = isset($_GET['whites']) ? (int)$_GET['whites'] : 0; // 1 = restrict to Whites bucket
    $checkLRV = isset($_GET['check_lrv']) ? (int)$_GET['check_lrv'] : 1; // 1 = include near-white/LRV check
    $lMin     = isset($_GET['lmin']) ? (float)$_GET['lmin'] : 70.0;
    $cMax     = isset($_GET['cmax']) ? (float)$_GET['cmax'] : 6.0;

    // Tolerances
    $tolLab   = isset($_GET['tol_lab']) ? (float)$_GET['tol_lab'] : 0.02; // L*, a*, b*
    $tolHcl   = isset($_GET['tol_hcl']) ? (float)$_GET['tol_hcl'] : 0.02; // L, C
    $tolHue   = isset($_GET['tol_hue']) ? (float)$_GET['tol_hue'] : 0.2;  // degrees
    $tolHsl   = isset($_GET['tol_hsl']) ? (float)$_GET['tol_hsl'] : 0.02; // %
    $deadband = isset($_GET['dead'])    ? (float)$_GET['dead']    : 0.5;  // L* diff to consider “real” vs tiny

    // Optional write: mode + commit
    // mode:
    //   - none (default): just verify
    //   - fix_consistency: rewrite HCL (L/C/h) from Lab; ensure hcl_l == lab_l; DOES NOT touch Lab
    //   - recompute_all: rewrite Lab/HCL/HSL from RGB (overwrites lab_*, hcl_*, hsl_*)
    //   - enforce_lrv_nearwhite: same as recompute_all but set L* = L*(LRV) for near-whites (if lrv present)
    $mode     = isset($_GET['mode']) ? (string)$_GET['mode'] : 'none';
    $commit   = isset($_GET['commit']) ? (int)$_GET['commit'] : 0;

    // ---------- Fetch ----------
    $rows = [];
    if ($idsArg !== '') {
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsArg)), fn($v)=>$v>0));
        if (!$ids) { http_response_code(400); echo json_encode(['error'=>'Bad ids']); exit; }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, brand, name, hex6, r,g,b, lrv,
                       lab_l, lab_a, lab_b,
                       hcl_l, hcl_c, hcl_h,
                       hsl_h, hsl_s, hsl_l,
                       orig_lab_l, orig_hcl_l,
                       COALESCE(neutral_cats,'') AS neutral_cats
                FROM colors WHERE id IN ($ph) LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        if ($brand === '') {
            http_response_code(400);
            echo json_encode(['error'=>'Provide ?ids=... or ?brand=<code>'], JSON_PRETTY_PRINT);
            exit;
        }
        $where = "WHERE brand = ? ";
        $params = [$brand];
        if ($whites) $where .= " AND COALESCE(neutral_cats,'') LIKE '%Whites%' ";
        $sql = "SELECT id, brand, name, hex6, r,g,b, lrv,
                       lab_l, lab_a, lab_b,
                       hcl_l, hcl_c, hcl_h,
                       hsl_h, hsl_s, hsl_l,
                       orig_lab_l, orig_hcl_l,
                       COALESCE(neutral_cats,'') AS neutral_cats
                FROM colors
                $where
                ORDER BY name ASC
                LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Writers
    $upd = $pdo->prepare("
        UPDATE colors
           SET lab_l=:lab_l, lab_a=:lab_a, lab_b=:lab_b,
               hcl_l=:hcl_l, hcl_c=:hcl_c, hcl_h=:hcl_h,
               hsl_h=:hsl_h, hsl_s=:hsl_s, hsl_l=:hsl_l
         WHERE id=:id
    ");

    $out = [];
    $counts = [
        'scanned' => 0,
        'ok'      => 0,
        'mismatch'=> 0,
        'fixed'   => 0,
        'nw_lrv_flag' => 0,
    ];

    foreach ($rows as $r) {
        $counts['scanned']++;

        $id   = (int)$r['id'];
        $hex6 = strtoupper((string)$r['hex6']);
        $R    = isset($r['r']) ? (int)$r['r'] : null;
        $G    = isset($r['g']) ? (int)$r['g'] : null;
        $B    = isset($r['b']) ? (int)$r['b'] : null;

        if (!preg_match('/^[0-9A-F]{6}$/', $hex6)) {
            $out[] = ['id'=>$id,'name'=>$r['name'],'status'=>'skip','reason'=>'bad hex6'];
            continue;
        }
        if (!isset($R,$G,$B)) {
            // derive from hex if RGB not stored
            $R = hexdec(substr($hex6, 0, 2));
            $G = hexdec(substr($hex6, 2, 2));
            $B = hexdec(substr($hex6, 4, 2));
        }

        // Recompute using your lib
        $lab = ColorCompute::rgbToLab($R,$G,$B);
        $lch = ColorCompute::labToLch($lab['L'],$lab['a'],$lab['b']);
        $hsl = ColorCompute::rgbToHsl($R,$G,$B);

        // DB values
        $dbL  = isset($r['lab_l']) ? (float)$r['lab_l'] : null;
        $dbA  = isset($r['lab_a']) ? (float)$r['lab_a'] : null;
        $dbBv = isset($r['lab_b']) ? (float)$r['lab_b'] : null;
        $dbHL = isset($r['hcl_l']) ? (float)$r['hcl_l'] : null;
        $dbHC = isset($r['hcl_c']) ? (float)$r['hcl_c'] : null;
        $dbHh = isset($r['hcl_h']) ? (float)$r['hcl_h'] : null;
        $dbHslH = isset($r['hsl_h']) ? (float)$r['hsl_h'] : null;
        $dbHslS = isset($r['hsl_s']) ? (float)$r['hsl_s'] : null;
        $dbHslL = isset($r['hsl_l']) ? (float)$r['hsl_l'] : null;

        // Diffs
        $dLab = [
            'L' => isset($dbL)  ? $lab['L'] - $dbL   : null,
            'a' => isset($dbA)  ? $lab['a'] - $dbA   : null,
            'b' => isset($dbBv) ? $lab['b'] - $dbBv  : null,
        ];
        $dHcl = [
            'L' => isset($dbHL) ? $lch['L'] - $dbHL  : null,
            'C' => isset($dbHC) ? $lch['C'] - $dbHC  : null,
            'h' => isset($dbHh) ? $lch['h'] - $dbHh  : null,
        ];
        $dHsl = [
            'H' => isset($dbHslH) ? $hsl['h'] - $dbHslH : null,
            'S' => isset($dbHslS) ? $hsl['s'] - $dbHslS : null,
            'L' => isset($dbHslL) ? $hsl['l'] - $dbHslL : null,
        ];

        // Consistency checks (internal): hcl_l == lab_l, hcl_c == hypot(a,b), hcl_h == atan2(b,a)
        $consistency = [];
        if (isset($dbL,$dbHL)) $consistency['hcl_l_eq_lab_l'] = abs($dbHL - $dbL) <= $tolHcl;
        if (isset($dbA,$dbBv,$dbHC)) $consistency['hcl_c_eq_hypot'] = abs($dbHC - hypot($dbA,$dbBv)) <= max($tolHcl, 1e-6);
        if (isset($dbA,$dbBv,$dbHh)) $consistency['hcl_h_eq_atan2'] = abs($dbHh - angle_deg($dbA,$dbBv)) <= max($tolHue, 1e-6);

        // Near-white + LRV correctness check
        $nwFlag = null;
        if ($checkLRV && isset($dbL,$dbA,$dbBv) && $r['lrv'] !== null && $r['lrv'] !== '') {
            $Cdb = hypot($dbA,$dbBv);
            if ($dbL >= $lMin && $Cdb <= $cMax) {
                $L_from_lrv = lstar_from_lrv((float)$r['lrv']);
                $dl = $L_from_lrv !== null ? ($L_from_lrv - $dbL) : null;
                if ($dl !== null && abs($dl) > $deadband) {
                    $nwFlag = [
                        'near_white' => true,
                        'L_from_LRV' => $L_from_lrv,
                        'delta_vs_L' => $dl
                    ];
                }
            }
        }
        if ($nwFlag) $counts['nw_lrv_flag']++;

        // Decide if mismatched beyond tolerances
        $mismatch =
            (isset($dLab['L']) && abs($dLab['L']) > $tolLab) ||
            (isset($dLab['a']) && abs($dLab['a']) > $tolLab) ||
            (isset($dLab['b']) && abs($dLab['b']) > $tolLab) ||
            (isset($dHcl['L']) && abs($dHcl['L']) > $tolHcl) ||
            (isset($dHcl['C']) && abs($dHcl['C']) > $tolHcl) ||
            (isset($dHcl['h']) && abs($dHcl['h']) > $tolHue) ||
            (isset($dHsl['H']) && abs($dHsl['H']) > $tolHsl) ||
            (isset($dHsl['S']) && abs($dHsl['S']) > $tolHsl) ||
            (isset($dHsl['L']) && abs($dHsl['L']) > $tolHsl);

        // Optional fixes
        $fixed = false;
        if ($commit && $mode !== 'none') {
            $writeLab = $dbL; $writeA = $dbA; $writeB = $dbBv;

            if ($mode === 'fix_consistency') {
                // Keep Lab; rewrite HCL/HSL from Lab & RGB
                $lch_from_dbLab = ColorCompute::labToLch($dbL, $dbA, $dbBv);
                $upd->execute([
                    ':lab_l' => $dbL, ':lab_a' => $dbA, ':lab_b' => $dbBv,
                    ':hcl_l' => $lch_from_dbLab['L'], ':hcl_c' => $lch_from_dbLab['C'], ':hcl_h' => $lch_from_dbLab['h'],
                    ':hsl_h' => $hsl['h'], ':hsl_s' => $hsl['s'], ':hsl_l' => $hsl['l'],
                    ':id'    => $id,
                ]);
                $fixed = true;

            } elseif ($mode === 'recompute_all') {
                // Overwrite Lab/HCL/HSL from RGB
                $upd->execute([
                    ':lab_l' => $lab['L'], ':lab_a' => $lab['a'], ':lab_b' => $lab['b'],
                    ':hcl_l' => $lch['L'], ':hcl_c' => $lch['C'], ':hcl_h' => $lch['h'],
                    ':hsl_h' => $hsl['h'], ':hsl_s' => $hsl['s'], ':hsl_l' => $hsl['l'],
                    ':id'    => $id,
                ]);
                $fixed = true;

            } elseif ($mode === 'enforce_lrv_nearwhite') {
                // Recompute a*,b* from RGB; set L* from LRV if near-white & has LRV; else keep current L*
                $L_eff = $dbL;
                $Cdb   = isset($dbA,$dbBv) ? hypot($dbA,$dbBv) : hypot($lab['a'],$lab['b']);
                $L_from_lrv = lstar_from_lrv($r['lrv'] !== null && $r['lrv'] !== '' ? (float)$r['lrv'] : null);
                if ($L_from_lrv !== null && (($dbL ?? $lab['L']) >= $lMin) && $Cdb <= $cMax) {
                    $L_eff = $L_from_lrv;
                }
                $lch_eff = ColorCompute::labToLch($L_eff, $lab['a'], $lab['b']);
                $upd->execute([
                    ':lab_l' => $L_eff, ':lab_a' => $lab['a'], ':lab_b' => $lab['b'],
                    ':hcl_l' => $lch_eff['L'], ':hcl_c' => $lch_eff['C'], ':hcl_h' => $lch_eff['h'],
                    ':hsl_h' => $hsl['h'], ':hsl_s' => $hsl['s'], ':hsl_l' => $hsl['l'],
                    ':id'    => $id,
                ]);
                $fixed = true;
            }
        }

        if ($mismatch) $counts['mismatch']++; else $counts['ok']++;
        if ($fixed)    $counts['fixed']++;

        $out[] = [
            'id'    => $id,
            'brand' => $r['brand'],
            'name'  => $r['name'],
            'hex6'  => $hex6,
            'lrv'   => $r['lrv'] !== null ? (float)$r['lrv'] : null,
            'recomputed' => [
                'lab' => ['L'=>$lab['L'],'a'=>$lab['a'],'b'=>$lab['b']],
                'hcl' => ['L'=>$lch['L'],'C'=>$lch['C'],'h'=>$lch['h']],
                'hsl' => $hsl,
            ],
            'db' => [
                'lab' => ['L'=>$dbL,'a'=>$dbA,'b'=>$dbBv],
                'hcl' => ['L'=>$dbHL,'C'=>$dbHC,'h'=>$dbHh],
                'hsl' => ['h'=>$dbHslH,'s'=>$dbHslS,'l'=>$dbHslL],
            ],
            'diff' => [
                'lab' => $dLab,
                'hcl' => $dHcl,
                'hsl' => $dHsl,
            ],
            'consistency' => $consistency,
            'nearwhite_lrv' => $nwFlag,  // null or details
            'status' => ($commit && $mode!=='none') ? 'updated' : ($mismatch ? 'mismatch' : 'ok'),
        ];
    }

    echo json_encode([
        'params'  => [
            'ids'=>$idsArg, 'brand'=>$brand, 'limit'=>$limit, 'whites'=>$whites,
            'check_lrv'=>$checkLRV, 'lmin'=>$lMin, 'cmax'=>$cMax,
            'tol_lab'=>$tolLab, 'tol_hcl'=>$tolHcl, 'tol_hue'=>$tolHue, 'tol_hsl'=>$tolHsl,
            'deadband'=>$deadband, 'mode'=>$mode, 'commit'=>$commit
        ],
        'summary' => $counts,
        'items'   => $out
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()], JSON_PRETTY_PRINT);
}
