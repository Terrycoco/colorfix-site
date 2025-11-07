<?php
// export-bm-colors.php
// Outputs bm-colors.csv with: id,code,name
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php'; // must set $pdo

$OUT = __DIR__ . '/data/bm-color-dump.csv';
if (!is_dir(dirname($OUT))) mkdir(dirname($OUT), 0777, true);

$sql = "
  SELECT id, code, name
  FROM colors
  WHERE brand = 'bm'
    AND code IS NOT NULL AND code <> ''
  ORDER BY code, name
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$fh = fopen($OUT, 'w');
fputcsv($fh, ['id','code','name']);

foreach ($rows as $r) {
    // trim & normalize whitespace
    $id   = (int)$r['id'];
    $code = preg_replace('/\s+/', ' ', trim($r['code']));
    $name = preg_replace('/\s+/', ' ', trim($r['name']));
    fputcsv($fh, [$id, $code, $name]);
}

fclose($fh);
echo "✅ Exported " . count($rows) . " Benjamin Moore colors to $OUT\n";
