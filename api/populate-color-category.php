<?php
file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] → entered populate-color-category.php\n", FILE_APPEND);

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

function logError($msg) {
    file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

function hueInRange($h, $min, $max) {
    // Example: h = 346.951, min = -20, max = 10
    if ($min <= $max) {
        return $h >= $min && $h < $max;
    } else {
        // Wraparound logic: e.g. -20 to 10 means 340–360 and 0–10
        return $h >= $min || $h < $max;
    }
}

try {
  //  $pdo->beginTransaction();

    // Clear previous data
    $pdo->exec("DELETE FROM color_category");
    $pdo->exec("UPDATE colors SET hue_cats = '', hue_cat_order = 99, neutral_cats = '', light_cat_id = NULL, chroma_cat_id = NULL");

    // Get all active category definitions
    $defs = $pdo->query("SELECT * FROM category_definitions WHERE active = 1")->fetchAll(PDO::FETCH_ASSOC);

 


    // Group by name
    $groups = [];
    foreach ($defs as $def) {
        $groups[$def['name']][] = $def;
    }

    foreach ($groups as $name => $definitions) {
        foreach ($definitions as $def) {
            $conditions = [];

            // Hue range
            if (is_numeric($def['hue_min']) && is_numeric($def['hue_max'])) {
                $hmin = ($def['hue_min'] + 360) % 360;
                $hmax = ($def['hue_max'] + 360) % 360;

                if ($hmin > $hmax) {
                    $conditions[] = "(MOD(hcl_h + 360, 360) >= $hmin OR MOD(hcl_h + 360, 360) < $hmax)";
                } else {
                    $conditions[] = "(MOD(hcl_h + 360, 360) >= $hmin AND MOD(hcl_h + 360, 360) < $hmax)";
                }
            }


            // Chroma
            if (is_numeric($def['chroma_min'])) $conditions[] = "hcl_c >= {$def['chroma_min']}";
            if (is_numeric($def['chroma_max'])) $conditions[] = "hcl_c < {$def['chroma_max']}";

            // Lightness
            if (is_numeric($def['light_min'])) $conditions[] = "hcl_l >= {$def['light_min']}";
            if (is_numeric($def['light_max'])) $conditions[] = "hcl_l < {$def['light_max']}";

            // LRV
            if (is_numeric($def['lrv_min'])) $conditions[] = "lrv >= {$def['lrv_min']}";
            if (is_numeric($def['lrv_max'])) $conditions[] = "lrv < {$def['lrv_max']}";

            if (empty($conditions)) {
                logError("❌ No whereClause generated for '{$def['name']}' (ID {$def['id']})");
                continue;
            }

            $whereClause = implode(' AND ', $conditions);
            $sql = "
                INSERT INTO color_category (color_id, category_id)
                SELECT id, :category_id
                FROM colors
                WHERE $whereClause
            ";

file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] 🔍 SQL: $sql\n", FILE_APPEND);

            $stmt = $pdo->prepare($sql);
 

            $stmt->execute([':category_id' => $def['id']]);
        }
    }

  //   $pdo->commit();
     $pdo = null; // Close connection manually
    file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] ✅ committed populate-color-category.php\n", FILE_APPEND);
} catch (Exception $e) {
    $pdo->rollBack();
    logError("❌ " . $e->getMessage());
    file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] ❌ rolled back populate-color-category.php\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => 'DB failure']);
}

