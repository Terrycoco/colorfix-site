<?php
declare(strict_types=1);

use App\Services\CategoriesService;
use App\Repos\PdoCategoryRepository;

test('calc_only neutral rules still populate colors.neutral_cats', function ($ctx) {
    if (!$ctx['haveDb'] || !$ctx['pdo'] instanceof PDO) {
        throw new RuntimeException('DB not available');
    }
    $pdo = $ctx['pdo'];

    // Pick an easy target color: very light, very low chroma
    $row = $pdo->query("
        SELECT id, hcl_h AS H, hcl_c AS C, hcl_l AS L
        FROM colors
        WHERE hcl_h IS NOT NULL AND hcl_c IS NOT NULL AND hcl_l IS NOT NULL
          AND hcl_l >= 92 AND hcl_c <= 2
        ORDER BY id LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Need at least one very-light, low-chroma color for this test');
    }
    $colorId = (int)$row['id'];

    $pdo->beginTransaction();
    try {
        // Make a temporary neutral category that matches the picked color,
        // but set calc_only=1 (not a label)
        $tmpName = '__TMP_TEST_NEUTRAL__';
        $ins = $pdo->prepare("
            INSERT INTO category_definitions
                (name, type, hue_min, hue_max, chroma_min, chroma_max, light_min, light_max, active, calc_only, sort_order)
            VALUES
                (:name, 'neutral', NULL, NULL, 0.00, 2.50, 92.00, 100.10, 1, 1, 999)
        ");
        $ins->execute([':name' => $tmpName]);

        // Run the category recalc (canonicalization off for speed/simplicity)
        $svc  = new CategoriesService(new PdoCategoryRepository($pdo));
        $svc->recalcAll(batchSize: 5000, applyHueDisplay: false);

        // Verify the color now has our neutral (even though calc_only=1)
        $chk = $pdo->prepare("SELECT neutral_cats FROM colors WHERE id=:id");
        $chk->execute([':id' => $colorId]);
        $neutralCsv = (string)($chk->fetchColumn() ?? '');

        if (stripos($neutralCsv, $tmpName) === false) {
            throw new RuntimeException("calc_only neutral not written to colors.neutral_cats (got: {$neutralCsv})");
        }

        $pdo->rollBack(); // don’t persist temp rule or recalc results
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});
