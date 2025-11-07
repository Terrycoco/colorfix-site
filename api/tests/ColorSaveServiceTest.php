<?php
declare(strict_types=1);

use App\services\ColorSaveService;

test('color-save service: insert → update (transactional)', function () {
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        throw new RuntimeException('DB not available; ensure /api/db.php defines $pdo.');
    }
    $pdo = $GLOBALS['pdo'];

    $brand = (string)$pdo->query("SELECT code FROM company ORDER BY code LIMIT 1")->fetchColumn();
    if ($brand === '' || $brand === false) {
        $brand = (string)$pdo->query("SELECT DISTINCT brand FROM colors WHERE brand IS NOT NULL AND brand<>'' ORDER BY brand LIMIT 1")->fetchColumn();
    }
    if ($brand === '' || $brand === false) {
        throw new RuntimeException('No valid brand code found for FK.');
    }

    $pdo->beginTransaction();
    try {
        $svc = new ColorSaveService();

        // INSERT
        $ins = $svc->save([
            'name'  => 'Service Insert ' . uniqid(),
            'brand' => $brand,
            'code'  => 'SV-INS',
            'hex6'  => 'DDEEFF',
        ], $pdo);
        if (empty($ins['ok'])) throw new RuntimeException('insert failed');
        $id = (int)$ins['id'];
        if ($id <= 0) throw new RuntimeException('missing id after insert');

        // sanity verify
        $r1 = $pdo->query("SELECT brand, hex6 FROM colors WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$r1) throw new RuntimeException('inserted row missing');
        if ((string)$r1['brand'] !== $brand) throw new RuntimeException('brand not saved');
        if (strtoupper((string)$r1['hex6']) !== 'DDEEFF') throw new RuntimeException('hex6 not saved');

        // UPDATE
        $newCode = 'SV-UPD-' . substr(uniqid(), -4);
        $upd = $svc->save([
            'id'   => $id,
            'code' => $newCode,
        ], $pdo);
        if (empty($upd['ok'])) throw new RuntimeException('update failed');

        // VERIFY
        $r2 = $pdo->query("SELECT code FROM colors WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$r2 || (string)$r2['code'] !== $newCode) throw new RuntimeException('code not updated');

        $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});
