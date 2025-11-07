<?php
declare(strict_types=1);

use App\services\ColorSaveService;

test('color-save upsert updates chip_num (transactional)', function () {
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
            'name'     => 'Upsert Insert ' . uniqid(),
            'brand'    => $brand,
            'code'     => 'UP-INS',
            'hex6'     => '112233',
            'chip_num' => 'A1',
        ], $pdo);
        if (empty($ins['ok'])) throw new RuntimeException('insert failed');
        $id = (int)$ins['id'];
        if ($id <= 0) throw new RuntimeException('missing id after insert');

        // VERIFY A1
        $r1 = $pdo->query("SELECT chip_num, brand FROM colors WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$r1) throw new RuntimeException('inserted row missing');
        if ((string)$r1['brand'] !== $brand) throw new RuntimeException('brand not saved');
        if ((string)$r1['chip_num'] !== 'A1') throw new RuntimeException('chip_num not A1 after insert');

        // UPDATE (second save as upsert)
        $upd = $svc->save([
            'id'       => $id,
            'chip_num' => 'B9',
        ], $pdo);
        if (empty($upd['ok'])) throw new RuntimeException('update failed');

        // VERIFY B9
        $r2 = $pdo->query("SELECT chip_num FROM colors WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$r2 || (string)$r2['chip_num'] !== 'B9') throw new RuntimeException('chip_num not B9 after update');

        $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});
