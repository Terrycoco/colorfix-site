<?php
declare(strict_types=1);

require_once __DIR__ . '/PainterViewerRexTest.php';

use App\PALETTES\Repos\PdoPVRepository;
use App\PALETTES\Managers\PVManager;

function pv_management_fixture(): PDO
{
    $pdo = painter_viewer_rex_fixture();
    $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-10-04 21:00:00');
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$values): string => implode('', $values));
    foreach (['label TEXT', 'admin_note TEXT', 'created_at TEXT', 'updated_at TEXT', 'context_json TEXT', 'fallback_rex_id INTEGER', 'locked INTEGER DEFAULT 0', 'lock_reason TEXT'] as $column) {
        $pdo->exec('ALTER TABLE rex_reservations ADD COLUMN ' . $column);
    }
    $pdo->exec('CREATE TABLE rex_aliases (id INTEGER PRIMARY KEY, reservation_id INTEGER, alias TEXT)');
    $pdo->exec('CREATE TABLE project_photos (project_id INTEGER, photo_library_id INTEGER, `use` INTEGER, palette_id INTEGER, zoom INTEGER, main INTEGER, `before` INTEGER, sort_order INTEGER)');
    $pdo->exec('INSERT INTO project_photos VALUES (7, 1, 1, 11, 0, 1, 0, 0)');
    $pdo->exec("INSERT INTO rex_reservations (id,token,resolver_key,resource_type,resource_id,status,label) VALUES (800,'client-copy','viewer','palette_viewer',85,'active','Kitchen Client')");
    $pdo->exec("INSERT INTO rex_reservation_links VALUES (100,800,800,'viewer',0,NULL,NULL)");
    $pdo->exec("INSERT INTO rex_aliases VALUES (1,800,'client-copy')");
    return $pdo;
}

test('PV duplicate titles are blocked within an experience and project but copies and other experiences are allowed', function () {
    $pdo = pv_management_fixture();
    $repo = new PdoPVRepository($pdo);
    try { $repo->assertUniqueTitleExperience(7, 12, 'client', ' Kitchen Client '); throw new LogicException('Duplicate allowed'); }
    catch (InvalidArgumentException $e) {}
    $repo->assertUniqueTitleExperience(7, 11, 'client', 'Kitchen Client', 85);
    $repo->assertUniqueTitleExperience(7, 11, 'concept', 'Kitchen Client');
    $repo->assertUniqueTitleExperience(7, 11, 'client', 'Kitchen Client (copy)');
    $repo->assertUniqueTitleExperience(8, 12, 'client', 'Kitchen Client');
    $repo->updateHeader(85, ['template_key'=>'client','kicker_text'=>'Final Plan','intro'=>'Copied intro','notes'=>'Copied notes','cta_label'=>'Replay','is_active'=>0]);
    $row=$pdo->query('SELECT * FROM palette_viewers WHERE palette_viewer_id=85')->fetch(PDO::FETCH_ASSOC);
    assert_equals('Copied intro', $row['intro']);
    assert_equals('Copied notes', $row['notes']);
    assert_equals('Replay', $row['cta_label']);
    assert_equals(0, (int)$row['is_active']);
});

test('a copied PV creates a new identity with preserved fields and shared palette', function () {
    $pdo = pv_management_fixture();
    $repo = new PdoPVRepository($pdo);
    $item = (new PVManager($pdo))->createPV(11, 'concept', 'Kitchen Client (copy)', 7);
    $id = (int)$item['palette_viewer_id'];
    assert_true($id !== 85);
    $repo->updateHeader($id, ['template_key'=>'concept', 'intro'=>'Copied intro', 'notes'=>'Copied notes', 'cta_label'=>'Replay', 'is_active'=>1]);
    $row = $pdo->query('SELECT * FROM palette_viewers WHERE palette_viewer_id=' . $id)->fetch(PDO::FETCH_ASSOC);
    assert_equals(11, (int)$row['saved_palette_id']);
    assert_equals('concept', $row['format']);
    assert_equals('Copied notes', $row['notes']);
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM project_photos')->fetchColumn());
    assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM rex_reservations WHERE resource_id=' . $id)->fetchColumn());
});

test('PV deletion removes its REX and presentation rows without deleting project photos or palettes', function () {
    $pdo = pv_management_fixture();
    $photos=(int)$pdo->query('SELECT COUNT(*) FROM photo_library')->fetchColumn();
    assert_true((new PVManager($pdo))->deletePV(85));
    assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM palette_viewers WHERE palette_viewer_id=85')->fetchColumn());
    assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM rex_reservations WHERE id=800')->fetchColumn());
    assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM rex_reservation_links WHERE id=100')->fetchColumn());
    assert_equals(0, (int)$pdo->query('SELECT COUNT(*) FROM rex_aliases WHERE reservation_id=800')->fetchColumn());
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM project_photos')->fetchColumn());
    assert_equals(2, (int)$pdo->query('SELECT COUNT(*) FROM saved_palettes')->fetchColumn());
    assert_equals($photos, (int)$pdo->query('SELECT COUNT(*) FROM photo_library')->fetchColumn());
});

test('locked PV REX prevents deletion and leaves its data intact', function () {
    $pdo = pv_management_fixture();
    $pdo->exec('UPDATE rex_reservations SET locked=1 WHERE id=800');
    try { (new PVManager($pdo))->deletePV(85); throw new LogicException('Locked PV deleted'); }
    catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'locked')); }
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM palette_viewers WHERE palette_viewer_id=85')->fetchColumn());
    assert_equals(1, (int)$pdo->query('SELECT COUNT(*) FROM rex_reservations WHERE id=800')->fetchColumn());
});
