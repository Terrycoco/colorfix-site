<?php
// make-sw-code-hex.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php'; // provides $pdo = new PDO(...)

$filepath = __DIR__ . '/data/sw-code-hex.csv';

try {
    $out = fopen($filepath, 'w');
    if (!$out) throw new RuntimeException("Cannot open $filepath for writing.");

    fputcsv($out, ['code', 'name', 'hex6']); // header

    $stmt = $pdo->prepare("
        SELECT code, name, hex6
        FROM colors
        WHERE brand = :brand
        ORDER BY code
    ");
    $stmt->execute([':brand' => 'sw']);

    $rows = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$row['code'], $row['name'], strtoupper($row['hex6'] ?? '')]);
        $rows++;
    }
    fclose($out);
    @chmod($filepath, 0644);
    echo "✅ Wrote $rows rows to $filepath\n";
} catch (Throwable $e) {
    if (isset($out) && is_resource($out)) fclose($out);
    http_response_code(500);
    echo "❌ Error: " . $e->getMessage();
}
