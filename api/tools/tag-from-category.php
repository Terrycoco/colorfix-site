<?php
declare(strict_types=1);

// Run with: php api/tools/tag-from-category.php
if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

require __DIR__ . '/../db.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "DB not initialized via api/db.php\n");
    exit(1);
}

$sql = "
INSERT IGNORE INTO photos_tags (photo_id, tag)
SELECT
  id,
  LOWER(SUBSTRING_INDEX(category_path, '/', -1)) AS tag
FROM photos
WHERE category_path IS NOT NULL
  AND category_path <> ''
  AND LOWER(SUBSTRING_INDEX(category_path, '/', -1)) <> ''
";

try {
    $count = $pdo->exec($sql);
    echo "✅ tags inserted: {$count}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to tag from category_path: " . $e->getMessage() . "\n");
    exit(1);
}
