<?php
require_once 'db.php';
require_once __DIR__ . '/functions/RGBtoHCL.php';
require_once __DIR__ . '/functions/logMessage.php';
require_once __DIR__ . '/functions/getContrastColor.php';

// Accept optional ?id=123 for single update
$id = $_GET['id'] ?? null;

try {
    if ($id) {
        $stmt = $pdo->prepare("SELECT id, r, g, b FROM colors WHERE id = ?");
        $stmt->execute([$id]);
        $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query("SELECT id, r, g, b FROM colors");
        $colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $update = $pdo->prepare("
        UPDATE colors SET
            lab_l = :lab_l,
            lab_a = :lab_a,
            lab_b = :lab_b,
            hcl_l = :hcl_l,
            hcl_c = :hcl_c,
            hcl_h = :hcl_h,
            hsl_h = :hsl_h,
            hsl_s = :hsl_s,
            hsl_l = :hsl_l,
            contrast_text_color = :contrast
        WHERE id = :id
    ");

    $updated = 0;

    foreach ($colors as $row) {
        try {
            $lab = RGBtoHCL($row['r'], $row['g'], $row['b']);
             $contrast = getContrastColor($lab['hcl_l']);
          

            $update->execute([
                ':lab_l' => $lab['lab_l'],
                ':lab_a' => $lab['lab_a'],
                ':lab_b' => $lab['lab_b'],
                ':hcl_l' => $lab['hcl_l'],
                ':hcl_c' => $lab['hcl_c'],
                ':hcl_h' => $lab['hcl_h'],
                ':hsl_h' => $lab['hsl_h'],
                ':hsl_s' => $lab['hsl_s'],
                ':hsl_l' => $lab['hsl_l'],
                ':contrast' => $contrast,
                ':id'    => $row['id'],
            ]);

            $updated++;
        } catch (Exception $e) {
            logMessage("Row ID {$row['id']} failed: " . $e->getMessage());
        }
    }

    $msg = $id ? "Updated color ID $id with LAB and HCL values." : "Updated $updated rows with LAB and HCL and contrast values.";
    logMessage($msg);
    echo $msg . "\n";

    // Trigger the attribute group ID updater
    $attributeUpdateUrl = __DIR__ . '/update-color-attributes-id.php'; 

    $response = @file_get_contents($attributeUpdateUrl);

    if ($response === false) {
        logMessage("❌ Failed to trigger update-color-attributes-id.php");
    } else {
        logMessage("✅ Successfully triggered update-color-attributes-id.php: $response");
    }



} catch (Exception $e) {
    logMessage("Fatal error: " . $e->getMessage());
    echo "Error: check log/colors-update.log\n";
}
