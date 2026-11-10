<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PALETTES\Repos\PdoSavedPaletteRepository;
use App\PALETTES\Managers\SavedPaletteManager;

// No swatch_view/base catalog exists: member enrichment must use the snapshot.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE saved_palettes (id INTEGER PRIMARY KEY, palette_type TEXT, is_public INTEGER, nickname TEXT)');
$pdo->exec('CREATE TABLE saved_palette_members (id INTEGER PRIMARY KEY, saved_palette_id INTEGER, color_id INTEGER, role_name TEXT, sheen TEXT, note TEXT, order_index INTEGER)');
$pdo->exec('CREATE TABLE mv_swatch_view (id INTEGER PRIMARY KEY, name TEXT, brand TEXT, brand_name TEXT, code TEXT, hex6 TEXT, hcl_h REAL, hcl_c REAL, hcl_l REAL, int_only INTEGER, chip_num TEXT, cluster_id INTEGER, hue_cats TEXT, neutral_cats TEXT, is_inactive INTEGER)');
$pdo->exec("INSERT INTO mv_swatch_view VALUES (1,'Blue','sw','Sherwin-Williams','SW1','123456',221.123456,12.654321,45.987654,1,'A1',10,'Blues',NULL,0), (2,'Inactive','bm','Benjamin Moore','B2','ABCDEF',10,20,30,0,'B1',11,'Reds',NULL,1)");
foreach (['art','interior','exterior','hoa'] as $i => $type) {
    $id = $i + 1;
    $pdo->prepare('INSERT INTO saved_palettes VALUES (?,?,?,?)')->execute([$id, $type, $i % 2, 'Fixture']);
    $pdo->prepare('INSERT INTO saved_palette_members VALUES (?,?,?,?,?,?,?)')->execute([$id * 10, $id, 999, 'Missing', 'Flat', 'Keep missing membership', 2]);
    $pdo->prepare('INSERT INTO saved_palette_members VALUES (?,?,?,?,?,?,?)')->execute([$id * 10 + 1, $id, 1, 'Body', 'Satin', 'Exact note', 1]);
    $pdo->prepare('INSERT INTO saved_palette_members VALUES (?,?,?,?,?,?,?)')->execute([$id * 10 + 2, $id, 2, 'Trim', null, null, 1]);
}
$expect = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$before = $pdo->query('SELECT * FROM saved_palettes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$repo = new PdoSavedPaletteRepository($pdo);
foreach (range(1, 4) as $id) {
    $full = (new SavedPaletteManager($pdo))->get($id);
    $rows = $full['members'];
    $expect(array_column($rows, 'color_id') === [1,2,999], 'Preserve member order and missing/inactive memberships');
    $expect($rows[0]['role'] === 'Body' && $rows[0]['sheen'] === 'Satin' && $rows[0]['note'] === 'Exact note', 'Preserve member metadata');
    $expect($rows[0]['color_name'] === 'Blue' && $rows[0]['color_brand_name'] === 'Sherwin-Williams' && $rows[0]['color_hex6'] === '123456', 'Preserve display aliases');
    $expect($rows[0]['color_hcl_h'] === 221.123456 && $rows[0]['color_hcl_c'] === 12.654321 && $rows[0]['color_hcl_l'] === 45.987654, 'No DTO rounding or precision loss');
    $expect($rows[1]['color_name'] === null && $rows[2]['color_name'] === null, 'Inactive/missing colors do not acquire display data');
    $expect($full['palette']['is_public'] === ($id - 1) % 2, 'Do not change palette visibility');
}
$expect($repo->getMembersForPalette(0) === [] && $repo->getMembersForPalette(999) === [], 'Preserve empty lookups');
$expect($before === $pdo->query('SELECT * FROM saved_palettes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Reads do not change palettes');
$pdo->exec('CREATE TABLE photos_artwork (artwork_id INTEGER PRIMARY KEY, photo_library_id INTEGER, artist TEXT, official_title TEXT, display_title TEXT, year INTEGER, circa INTEGER, medium TEXT, museum TEXT, source_url TEXT, historical_notes TEXT, color_analysis TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO photos_artwork (artwork_id,photo_library_id,display_title) VALUES (1,746,'Fixture artwork')");
$pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY,source_type TEXT,rel_path TEXT,title TEXT,alt_text TEXT,palette_id INTEGER,show_in_gallery INTEGER,is_inactive INTEGER,is_retired INTEGER,inspiration_photo_id INTEGER,client_id INTEGER)');
$pdo->exec("INSERT INTO photo_library VALUES
 (746,'art','photos/art.png','Artwork','Artwork alt',1,1,0,0,NULL,NULL),
 (747,'progression','photos/design.png','Visible design','Design alt',2,1,0,0,746,NULL),
 (748,'client','photos/private.png','Client design','',2,1,0,0,746,99),
 (749,'progression','photos/inactive.png','Inactive design','',2,1,1,0,746,NULL),
 (750,'progression','photos/retired.png','Retired design','',2,1,0,1,746,NULL),
 (751,'progression','photos/hidden.png','Hidden design','',2,0,0,0,746,NULL)");
$service = new App\ARTWORK\Services\ArtworkPageService($pdo);
$page = $service->get(1);
$expect($page['paint_approximations']['members'][0]['name'] === 'Blue', 'Real Artwork composition uses materialized palette members');
$expect($page['photo']['image_url'] === '/photos/art.png', 'API retains original photo URL for enlargement');
$expect(array_column($page['inspired_designs'], 'photo_library_id') === [747], 'Client/private/inactive/retired designs remain excluded');
$pdo->exec('UPDATE photo_library SET show_in_gallery=0 WHERE photo_library_id=746');
try { $service->get(1); throw new RuntimeException('Hidden artwork became public'); }
catch (OutOfBoundsException) { /* Existing visibility policy remains authoritative. */ }
echo "PASS: snapshot-only palette enrichment, four palette types, aliases/precision/order/metadata, missing/inactive members; real Artwork composition and public/client visibility protection.\n";
