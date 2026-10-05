<?php
declare(strict_types=1);
require_once __DIR__ . '/PhotoReplacementTest.php';

use App\Repos\PdoPhotoLibraryRepository;

function photo_library_usage_fixture(): array
{
    [$pdo, $root] = photo_replacement_fixture();
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$values): string => implode('', $values));
    $pdo->sqliteCreateFunction('SUBSTRING_INDEX', static function ($value, $separator, $count): ?string {
        if ($value === null) return null;
        return implode($separator, array_slice(explode($separator, $value), 0, $count));
    });
    $pdo->exec('DELETE FROM playlist_items');
    $pdo->exec('DELETE FROM project_photos');
    $pdo->exec('ALTER TABLE playlist_items ADD COLUMN playlist_item_id INTEGER');
    $pdo->exec('ALTER TABLE playlist_items ADD COLUMN playlist_id INTEGER');
    $pdo->exec('ALTER TABLE project_photos ADD COLUMN project_id INTEGER');
    $schemas = [
        'playlists' => 'playlist_id INTEGER PRIMARY KEY, title TEXT, hero_image_id INTEGER, hero_image_url TEXT',
        'playlist_sets' => 'id INTEGER PRIMARY KEY, title TEXT, cover_photo_library_id INTEGER',
        'projects' => 'id INTEGER PRIMARY KEY, project_name TEXT',
        'project_palettes' => 'project_id INTEGER, saved_palette_id INTEGER, is_final INTEGER',
        'palette_viewers' => 'palette_viewer_id INTEGER PRIMARY KEY, title TEXT, format TEXT, saved_palette_id INTEGER, project_id INTEGER, is_active INTEGER',
        'palette_viewer_photos' => 'palette_viewer_id INTEGER, photo_library_id INTEGER, rel_path TEXT, photo_type TEXT',
        'playlist_instance_sets' => 'id INTEGER PRIMARY KEY, title TEXT',
        'playlist_instance_set_items' => 'id INTEGER PRIMARY KEY, playlist_instance_set_id INTEGER, photo_library_id INTEGER',
        'playlist_instances' => 'playlist_instance_id INTEGER PRIMARY KEY, instance_name TEXT, intro_image_url TEXT, share_image_url TEXT',
        'articles' => 'id INTEGER PRIMARY KEY, title TEXT, hero_asset_id INTEGER, hero_mobile_asset_id INTEGER',
        'article_sections' => 'id INTEGER PRIMARY KEY, article_id INTEGER, asset_id INTEGER',
        'saved_palettes' => 'id INTEGER PRIMARY KEY, nickname TEXT, palette_hash TEXT',
        'saved_palette_sets' => 'id INTEGER PRIMARY KEY, saved_palette_id INTEGER, title TEXT, slug TEXT',
        'saved_palette_set_photos' => 'saved_palette_set_id INTEGER, photo_library_id INTEGER, photo_type TEXT, rel_path TEXT',
        'photo_groups' => 'group_id INTEGER PRIMARY KEY, title TEXT',
        'photo_group_items' => 'group_id INTEGER, photo_library_id INTEGER',
    ];
    foreach ($schemas as $name => $columns) { $pdo->exec("CREATE TABLE {$name} ({$columns})"); }
    $pdo->exec("INSERT INTO playlists VALUES (56,'Ulloa',NULL,NULL)");
    $pdo->exec("INSERT INTO projects VALUES (7,'Ulloa Project')");
    $pdo->exec('INSERT INTO project_palettes VALUES (7,162,1)');
    $pdo->exec("INSERT INTO palette_viewers VALUES (85,'Exterior Client','client',162,NULL,1),(84,'All Rooms Painter','painter',NULL,7,1),(79,'Inactive Concept','concept',162,NULL,0)");
    return [$pdo, $root, new PdoPhotoLibraryRepository($pdo)];
}

test('photo delete guard checks disabled Project Photos and current shared client, concept and painter PVs', function () {
    [$pdo, $root, $repo] = photo_library_usage_fixture();
    try {
        $pdo->exec('INSERT INTO project_photos VALUES (736,162,0,1,0,0,5,7)');
        $usages = $repo->listUsages(736);
        assert_equals(['project_photo'], array_column($usages, 'usage_type'));
        $pdo->exec('UPDATE project_photos SET `use`=1');
        $usages = $repo->listUsages(736);
        $pvs = array_values(array_filter($usages, static fn($row) => $row['usage_type'] === 'project_palette_viewer_photo'));
        assert_equals(3, count($pvs));
        assert_true(in_array(84, array_map('intval', array_column($pvs, 'ref_id')), true));
        assert_equals([], $repo->listUsages(900));
    } finally { cleanup_photo_replacement_fixture($root); }
});

test('photo delete guard catches playlist IDs, encoded references, paths, heroes and current set covers', function () {
    [$pdo, $root, $repo] = photo_library_usage_fixture();
    try {
        foreach (['photo:736|/unrelated.png', '/old.png?v=123', 'old.png', 'https://colorfix.test/old.png#preview'] as $index => $path) {
            $stmt = $pdo->prepare('INSERT INTO playlist_items VALUES (NULL,?, ?,56)');
            $stmt->execute([$path, $index + 1]);
        }
        $pdo->exec("INSERT INTO playlist_items VALUES (736,'/other.png',5,56),(900,'/not-old.png',6,56)");
        $pdo->exec("UPDATE playlists SET hero_image_url='photo:736'");
        $pdo->exec("INSERT INTO playlist_sets VALUES (1,'Client Collection',736)");
        $usages = $repo->listUsages(736);
        $types = array_count_values(array_column($usages, 'usage_type'));
        assert_equals(5, $types['playlist_item']);
        assert_equals(1, $types['playlist_hero']);
        assert_equals(1, $types['playlist_set_cover']);
    } finally { cleanup_photo_replacement_fixture($root); }
});

test('photo delete guard catches direct PV links and cached legacy paths without filtering inactive viewers', function () {
    [$pdo, $root, $repo] = photo_library_usage_fixture();
    try {
        $pdo->exec("INSERT INTO palette_viewer_photos VALUES (79,736,NULL,'zoom'), (85,NULL,'/old.png?v=123','main')");
        $usages = $repo->listUsages(736);
        assert_equals(2, count($usages));
        assert_equals(['palette_viewer_photo','palette_viewer_photo'], array_column($usages, 'usage_type'));
        assert_equals([], $repo->listUsages(900));
    } finally { cleanup_photo_replacement_fixture($root); }
});

test('photo delete guard preserves saved palette, instance, group and article checks and fails closed on query errors', function () {
    [$pdo, $root, $repo] = photo_library_usage_fixture();
    try {
        $pdo->exec("INSERT INTO playlist_instances VALUES (1,'Legacy Instance','photo:736','/old.png?v=1')");
        $pdo->exec("INSERT INTO saved_palettes VALUES (162,'Exterior','hash')");
        $pdo->exec("INSERT INTO saved_palette_sets VALUES (1,162,'Blue','blue')");
        $pdo->exec("INSERT INTO saved_palette_set_photos VALUES (1,NULL,'main','/old.png')");
        $pdo->exec("INSERT INTO photo_groups VALUES (1,'Ulloa')");
        $pdo->exec('INSERT INTO photo_group_items VALUES (1,736)');
        $pdo->exec("INSERT INTO articles VALUES (1,'Example',736,NULL)");
        assert_equals(5, count($repo->listUsages(736)));
        $pdo->exec('DROP TABLE project_photos');
        try { $repo->listUsages(736); throw new LogicException('Usage failure was treated as unused'); }
        catch (PDOException $e) {}
    } finally { cleanup_photo_replacement_fixture($root); }
});
