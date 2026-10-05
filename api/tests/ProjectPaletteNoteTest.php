<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PROJECTS\Repos\PdoProjectPaletteRepository;

test('project palette area note saves on an existing link without a unique index', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE project_palettes (
        project_palette_id INTEGER PRIMARY KEY, project_id INTEGER, saved_palette_id INTEGER,
        note TEXT, area_label TEXT, is_final INTEGER, order_index INTEGER, created_at TEXT
    )');
    $pdo->exec("INSERT INTO project_palettes VALUES
        (1, 7, 11, 'Original note', 'Wine Room', 1, 2, '2026-10-04'),
        (2, 8, 11, 'Other project note', 'Dining Room', 0, 0, '2026-10-04')");
    $repo = new PdoProjectPaletteRepository($pdo);
    $note = "Painter's instructions: protect window frames";

    assert_equals(1, $repo->link(7, 11, $note, 'Wine Room'));
    $row = $pdo->query('SELECT * FROM project_palettes WHERE project_palette_id = 1')->fetch(PDO::FETCH_ASSOC);
    assert_equals($note, $row['note']);
    assert_equals('Wine Room', $row['area_label']);
    assert_equals(1, (int)$row['is_final']);
    assert_equals(2, (int)$row['order_index']);
    assert_equals(2, (int)$pdo->query('SELECT COUNT(*) FROM project_palettes')->fetchColumn());
    assert_equals('Other project note', $pdo->query('SELECT note FROM project_palettes WHERE project_palette_id = 2')->fetchColumn());

    assert_equals(1, $repo->link(7, 11, null, 'Wine Room'));
    assert_equals(null, $pdo->query('SELECT note FROM project_palettes WHERE project_palette_id = 1')->fetchColumn());
});

test('project palette area note updates existing duplicate links consistently', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE project_palettes (
        project_palette_id INTEGER PRIMARY KEY, project_id INTEGER, saved_palette_id INTEGER,
        note TEXT, area_label TEXT
    )');
    $pdo->exec("INSERT INTO project_palettes VALUES
        (1, 7, 11, 'Old note', 'Wine Room'),
        (2, 7, 11, 'Different old note', 'Wine Room')");
    $repo = new PdoProjectPaletteRepository($pdo);

    assert_equals(1, $repo->link(7, 11, 'Use two coats', 'Wine Room'));
    assert_equals(['Use two coats', 'Use two coats'], $pdo->query(
        'SELECT note FROM project_palettes ORDER BY project_palette_id'
    )->fetchAll(PDO::FETCH_COLUMN));
});
