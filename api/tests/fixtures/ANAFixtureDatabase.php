<?php
declare(strict_types=1);

// Isolated SQLite adapter for the established MySQL config/report statements.
final class ANAFixturePDO extends PDO
{
    public int $configLoads = 0;
    public function __construct(string $path = ':memory:')
    {
        parent::__construct('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('NOW', fn () => '2026-10-29 12:00:00');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'SELECT config_json FROM app_configs')) $this->configLoads++;
        $query = str_replace('<=>', 'IS', $query);
        if (str_contains($query, 'INSERT INTO app_configs')) {
            $query = preg_replace('/ON DUPLICATE KEY UPDATE.*$/s', 'ON CONFLICT(config_key) DO UPDATE SET config_json=excluded.config_json, updated_at=CURRENT_TIMESTAMP', $query);
        }
        return parent::prepare($query, $options);
    }
}

function anaFixtureDatabase(string $path = ':memory:'): ANAFixturePDO
{
    $pdo = new ANAFixturePDO($path);
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_configs (config_key TEXT PRIMARY KEY, config_json TEXT NOT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS src_params (src TEXT PRIMARY KEY, label TEXT)');
    $pdo->exec("INSERT OR IGNORE INTO src_params VALUES ('client','Client')");
    $pdo->exec('CREATE TABLE IF NOT EXISTS ana_pages (id INTEGER PRIMARY KEY, path TEXT UNIQUE, track_enabled INTEGER)');
    $pdo->exec("INSERT OR IGNORE INTO ana_pages VALUES (1,'/picker',1)");
    $exists = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE name='analytics_events'")->fetchColumn();
    if (!$exists) {
        $pdo->exec('CREATE TABLE analytics_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT, is_test INTEGER DEFAULT 0,
            reservation_id INTEGER, reservation_token TEXT, resolver_key TEXT, resource_type TEXT,
            resource_id INTEGER, experience_key TEXT, source_key TEXT, session_id TEXT, referrer TEXT,
            viewer_id TEXT, path TEXT, payload_json TEXT, created_at TEXT
        )');
        $pdo->exec("INSERT INTO analytics_events(event_key,resource_type,resource_id,path,created_at) VALUES ('visit','page',1,'/picker','2026-09-01 12:00:00')");
        $migration = file_get_contents(__DIR__ . '/../../../database/migrations/2026_10_29_001_ana_crawler_filter.sql');
        preg_match('/ALTER TABLE analytics_events.*?;/s', $migration, $alter);
        $pdo->exec($alter[0]);
    }
    $seed = file_get_contents(__DIR__ . '/../../../database/migrations/2026_10_29_002_seed_ana_ignore.sql');
    $seed = str_replace('ON DUPLICATE KEY UPDATE config_key = VALUES(config_key)', 'ON CONFLICT(config_key) DO NOTHING', $seed);
    $pdo->exec($seed);

    return $pdo;
}
