<?php
// Run with: php api/tools/run-migrations.php
if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line.\n");
}

require __DIR__ . '/../db.php';

$migrationsDir = dirname(__DIR__, 1) . '/../database/migrations';
if (!is_dir($migrationsDir)) {
    exit("Migrations directory not found: {$migrationsDir}\n");
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    fwrite(STDERR, "Failed to ensure schema_migrations exists: {$e->getMessage()}\n");
    exit(1);
}

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$appliedSet = array_flip($applied);

$files = glob($migrationsDir . '/*.sql');
sort($files);
if (!$files) {
    echo "No migration files found.\n";
    exit(0);
}

foreach ($files as $file) {
    $name = basename($file);
    if (isset($appliedSet[$name])) {
        echo "↺ $name (already applied)\n";
        continue;
    }

    $sql = file_get_contents($file);
    echo "▶ Applying $name ...";
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$name]);
        echo " done.\n";
    } catch (PDOException $e) {
        echo " failed!\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

echo "✅ All migrations applied.\n";
