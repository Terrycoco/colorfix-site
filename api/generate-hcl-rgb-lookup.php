<?php
require_once 'db.php'; // Ensure this sets $pdo
require_once __DIR__ . '/functions/HCLtoRGB.php';

try {
    // Create table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hcl_rgb_lookup (
            hcl_hue INT PRIMARY KEY,
            r INT NOT NULL,
            g INT NOT NULL,
            b INT NOT NULL,
            hex VARCHAR(7) NOT NULL,
            hcl_l FLOAT DEFAULT 70,
            hcl_c FLOAT DEFAULT 60
        )
    ");

    $insert = $pdo->prepare("
        INSERT INTO hcl_rgb_lookup (hcl_hue, r, g, b, hex, hcl_l, hcl_c)
        VALUES (:hue, :r, :g, :b, :hex, :l, :c)
        ON DUPLICATE KEY UPDATE
            r = VALUES(r),
            g = VALUES(g),
            b = VALUES(b),
            hex = VALUES(hex)
    ");

    $count = 0;

    for ($h = 0; $h < 360; $h++) {
        $l = 70;
        $c = 60;

        $rgb = HCLtoRGB($h, $c, $l);

        $r = round($rgb['r']);
        $g = round($rgb['g']);
        $b = round($rgb['b']);
        $hex = sprintf("#%02X%02X%02X", $r, $g, $b);

        $insert->execute([
            ':hue' => $h,
            ':r' => $r,
            ':g' => $g,
            ':b' => $b,
            ':hex' => $hex,
            ':l' => $l,
            ':c' => $c,
        ]);

        $count++;
    }

    echo "✅ Populated/updated $count HCL hue rows in hcl_rgb_lookup.\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
    exit;
}
