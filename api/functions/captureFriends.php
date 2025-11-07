<?php


/**
 * Capture friend pairs by resolving swatch/color IDs to hex values,
 * canonicalizing pairs, and inserting them into color_friends.
 *
 * Returns the same structure your endpoint returned.
 *
 * @param PDO   $pdo
 * @param int[] $ids    array of swatch/color IDs (2–6)
 * @return array
 */
function captureFriends(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) < 2 || count($ids) > 6) {
        return ['ok'=>false, 'error'=>'Provide 2–6 ids', 'got'=>count($ids)];
    }

    // Resolve ids -> hex6 from swatch_view and colors
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT UPPER(hex6) AS hex6 FROM swatch_view WHERE id IN ($ph)
            UNION
            SELECT UPPER(hex6) AS hex6 FROM colors WHERE id IN ($ph)";
    $stmt = $pdo->prepare($sql);

    $k = 1;
    foreach ($ids as $id) $stmt->bindValue($k++, $id, PDO::PARAM_INT);
    foreach ($ids as $id) $stmt->bindValue($k++, $id, PDO::PARAM_INT);

    $stmt->execute();
    $hexes = array_values(array_unique(array_filter(
        $stmt->fetchAll(PDO::FETCH_COLUMN, 0),
        fn($h) => is_string($h) && preg_match('/^[0-9A-Fa-f]{6}$/', $h)
    )));
    $hexes = array_map('strtoupper', $hexes);

    if (count($hexes) < 2) {
        return ['ok'=>false, 'error'=>'Unable to resolve 2+ colors from ids', 'resolved_hexes'=>$hexes, 'ids'=>$ids];
    }

    // Canonical unordered pairs
    $pairs = [];
    $seen  = [];
    for ($i=0; $i<count($hexes); $i++) {
        for ($j=$i+1; $j<count($hexes); $j++) {
            $a = $hexes[$i]; $b = $hexes[$j];
            if ($a === $b) continue;
            $lo = ($a <= $b) ? $a : $b;
            $hi = ($a <= $b) ? $b : $a;
            $key = $lo . ':' . $hi;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $pairs[] = [$lo, $hi];
        }
    }
    if (!$pairs) {
        return ['ok'=>false, 'error'=>'No pairs after canonicalization', 'resolved_hexes'=>$hexes];
    }

    // Insert
    $attempted = count($pairs);
    $inserted  = 0;
    $skipped   = 0;

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO color_friends (hex1, hex2) VALUES (?, ?)");
        foreach ($pairs as [$lo, $hi]) {
            if (!preg_match('/^[0-9A-F]{6}$/', $lo) || !preg_match('/^[0-9A-F]{6}$/', $hi)) {
                throw new RuntimeException("Bad pair: $lo,$hi");
            }
            $ins->execute([$lo, $hi]);
            if ($ins->rowCount() === 1) $inserted++; else $skipped++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'error'=>'DB insert failed', 'detail'=>$e->getMessage()];
    }

    return [
        'ok' => true,
        'ids' => $ids,
        'resolved_hexes' => $hexes,
        'pairs' => $pairs,
        'attempted_pairs' => $attempted,
        'inserted_pairs' => $inserted,
        'skipped_pairs' => $skipped
    ];
}
