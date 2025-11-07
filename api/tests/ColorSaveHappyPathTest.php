<?php
declare(strict_types=1);

use App\services\ColorSaveService;

test('color-save: insert(with hex6) → hydrate → update (transactional)', function () {
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        throw new RuntimeException('DB not available; ensure /api/db.php defines $pdo.');
    }
    $pdo = $GLOBALS['pdo'];

    // pick a valid brand code to satisfy FK(colors.brand → company.code)
    $brand = (string)$pdo->query("SELECT code FROM company ORDER BY code LIMIT 1")->fetchColumn();
    if ($brand === '' || $brand === false) {
        $brand = (string)$pdo->query("SELECT DISTINCT brand FROM colors WHERE brand IS NOT NULL AND brand<>'' ORDER BY brand LIMIT 1")->fetchColumn();
    }
    if ($brand === '' || $brand === false) {
        throw new RuntimeException('No valid brand code found (company/code or colors/brand).');
    }

    $pdo->beginTransaction();
    try {
        $svc = new ColorSaveService();

        // INSERT
        $ins = $svc->save([
            'name'  => 'Unit Insert ' . uniqid(),
            'brand' => $brand,
            'code'  => 'UNIT-INS',
            'hex6'  => 'A1B2C3',
        ], $pdo);
        if (empty($ins['ok'])) throw new RuntimeException('insert failed');
        $id = (int)$ins['id'];
        if ($id <= 0) throw new RuntimeException('missing id after insert');

        // UPDATE
        $upd = $svc->save([
            'id'       => $id,
            'code'     => 'UNIT-UPD',
            'chip_num' => 'UPD-01',
        ], $pdo);
        if (empty($upd['ok'])) throw new RuntimeException('update failed');

        // VERIFY
        $row = $pdo->query("SELECT name, brand, code, chip_num, hex6 FROM colors WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('row missing after update');
        if ((string)$row['brand'] !== $brand) throw new RuntimeException('brand mismatch');
        if ($row['code'] !== 'UNIT-UPD')   throw new RuntimeException('code not updated');
        if ($row['chip_num'] !== 'UPD-01') throw new RuntimeException('chip_num not updated');
        if (strtoupper((string)$row['hex6']) !== 'A1B2C3') throw new RuntimeException('hex6 mismatch');

        $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});
