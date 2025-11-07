<?php
require_once 'db.php';
require_once __DIR__ . '/functions/logMessage.php';
require_once __DIR__ . '/functions/checkCLGroups.php';  // new function lives here

try {


    // Load all group definitions
    $stmt = $pdo->query("SELECT * FROM cl_groups ORDER BY value_min ASC");
    $groupDefs = ['lightness' => [], 'chroma' => []];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $groupDefs[$row['group_type']][] = $row;
    }

    // Load all colors with hcl_l and hcl_c
    $stmt = $pdo->query("SELECT id, hcl_l, hcl_c FROM colors");
    $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;

    foreach ($colors as $color) {
        $groups = checkCLGroups($color, $groupDefs);

        if ($groups['l_group'] !== null || $groups['c_group'] !== null) {
            $updateStmt = $pdo->prepare("
                UPDATE colors
                SET l_group = :l_group, c_group = :c_group
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':l_group' => $groups['l_group'],
                ':c_group' => $groups['c_group'],
                ':id' => $color['id']
            ]);
            $updated++;
        }
    }

    logMessage("Updated $updated color(s) with lightness and chroma groups.");
    echo "Updated $updated color(s).\n";

} catch (PDOException $e) {
    logMessage("DB ERROR: " . $e->getMessage());
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}
