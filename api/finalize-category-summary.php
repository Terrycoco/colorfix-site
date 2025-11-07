<?php
file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] 👣 VERY FIRST LINE RAN\n", FILE_APPEND);

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] ✅ DB loaded\n", FILE_APPEND);

function logError($msg) {
    file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

try {
    // Clear current summary fields
    $pdo->exec("UPDATE colors SET hue_cats = '', hue_cat_order = 99, neutral_cats = '', light_cat_id = NULL, chroma_cat_id = NULL");

    // 🧪 DEBUG: Show what the subquery would return
    file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] 🧪 Dumping raw summary data\n", FILE_APPEND);
    $results = $pdo->query("
        SELECT
            color_id,
            GROUP_CONCAT(IF(cd.type = 'hue', cd.name, NULL) ORDER BY cd.hue_min) AS hue_cats,
            GROUP_CONCAT(IF(cd.type = 'neutral', cd.name, NULL) ORDER BY cd.hue_min) AS neutral_cats,
            MAX(IF(cd.type = 'lightness', cd.id, NULL)) AS light_cat_id,
            MAX(IF(cd.type = 'chroma', cd.id, NULL)) AS chroma_cat_id
        FROM color_category cc
        JOIN category_definitions cd ON cc.category_id = cd.id
        GROUP BY color_id
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $row) {
        file_put_contents(__DIR__ . '/assign.log', "[DATA] " . json_encode($row) . "\n", FILE_APPEND);
    }

    // Now run the real summary update
    $update = $pdo->prepare("
        UPDATE colors c
        JOIN (
            SELECT
                color_id,
                GROUP_CONCAT(
                    IF(cd.type = 'hue', cd.name, NULL) 
                    ORDER BY cd.hue_min
                    SEPARATOR ','
                ) AS hue_cats,
                GROUP_CONCAT(IF(cd.type = 'neutral', cd.name, NULL) SEPARATOR ',') AS neutral_cats,
                MAX(IF(cd.type = 'lightness', cd.id, NULL)) AS light_cat_id,
                MAX(IF(cd.type = 'chroma', cd.id, NULL)) AS chroma_cat_id
            FROM color_category cc
            JOIN category_definitions cd ON cc.category_id = cd.id
            GROUP BY color_id
        ) x ON c.id = x.color_id
        SET 
            c.hue_cats = x.hue_cats,
            c.neutral_cats = x.neutral_cats,
            c.light_cat_id = x.light_cat_id,
            c.chroma_cat_id = x.chroma_cat_id
    ");

    file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] About to run summary update\n", FILE_APPEND);

    try {
        $update->execute();

        // Deduplicate neutral_cats and hue_cats
            $fixCats = $pdo->query("SELECT id, neutral_cats, hue_cats FROM colors WHERE neutral_cats IS NOT NULL OR hue_cats IS NOT NULL");

            $dedupe = $pdo->prepare("UPDATE colors SET neutral_cats = :neutrals, hue_cats = :hues WHERE id = :id");

            while ($row = $fixCats->fetch(PDO::FETCH_ASSOC)) {
                $neutral = $row['neutral_cats'] ?? '';
                $hue = $row['hue_cats'] ?? '';

               $neutralUnique = implode(',', array_unique(array_map('trim', explode(',', (string)$neutral))));
                $hueUnique     = implode(',', array_unique(array_map('trim', explode(',', (string)$hue))));
                // harden: strip any stray internal spaces just in case
                $neutralUnique = str_replace(' ', '', $neutralUnique);
                $hueUnique     = str_replace(' ', '', $hueUnique);
                
                $dedupe->execute([
                    ':neutrals' => $neutralUnique,
                    ':hues'     => $hueUnique,
                    ':id'       => $row['id']
                ]);
            }



        file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] ✅ summary update executed\n", FILE_APPEND);
    } catch (PDOException $e) {
        logError("❌ Summary execute failed: " . $e->getMessage());
        throw $e;
    }

    // Final normalization using hue_display
    $pdo->exec("
        UPDATE colors c
        JOIN hue_display h ON c.hue_cats = h.combo_key
        SET 
            c.hue_cats = h.display_name,
            c.hue_cat_order = h.sort_order
    ");

    file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] ✅ finished finalize-category-summary.php\n", FILE_APPEND);

} catch (PDOException $e) {
    logError("❌ SQL Error during summary insert: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'DB error']);
}
