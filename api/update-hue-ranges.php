<?php
require_once 'db.php';

try {
    // Fetch all categories with a defined hue_center
    $stmt = $pdo->query("SELECT id, category, hue_center, tolerance FROM category WHERE hue_center IS NOT NULL");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare("
        UPDATE category
        SET hue_start = :start, hue_end = :end
        WHERE id = :id
    ");

    $updated = 0;

    foreach ($categories as $cat) {
        $center = floatval($cat['hue_center']);
        $tolerance = isset($cat['tolerance']) ? floatval($cat['tolerance']) : 25;

        // Raw values before normalization
        $start = $center - $tolerance;
        $end = $center + $tolerance;

        // Handle wraparound
        if ($start < 0) {
            // Keep start negative to indicate wrap
            $end = fmod($end + 360, 360); // normalize end into [0–360)
        } elseif ($end >= 360) {
            // Normalize end into [0–360)
            $end = fmod($end, 360);
        }

        $update->execute([
            ':start' => $start,
            ':end' => $end,
            ':id' => $cat['id']
        ]);

        $updated++;
    }

    echo "✅ Updated hue_start and hue_end for $updated categories (wraparound-aware).\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
    exit;
}
