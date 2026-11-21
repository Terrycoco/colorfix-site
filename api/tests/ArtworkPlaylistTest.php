<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';

use App\ARTWORK\Services\ArtworkPageService;

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY,source_type TEXT,inspiration_photo_id INTEGER,show_in_gallery INTEGER DEFAULT 1,is_inactive INTEGER DEFAULT 0,is_retired INTEGER DEFAULT 0,rel_path TEXT,title TEXT,alt_text TEXT,palette_id INTEGER,client_id INTEGER)');
$pdo->exec('CREATE TABLE photos_artwork (artwork_id INTEGER PRIMARY KEY,photo_library_id INTEGER,artist TEXT,artist_last TEXT,official_title TEXT,display_title TEXT,year INTEGER,circa INTEGER,medium TEXT,museum TEXT,source_url TEXT,historical_notes TEXT,color_analysis TEXT,created_at TEXT,updated_at TEXT)');
$pdo->exec('CREATE TABLE photo_crops (photo_library_id INTEGER,crop_type TEXT,crop_x REAL,crop_y REAL,crop_width REAL,crop_height REAL)');
$pdo->exec('CREATE TABLE playlists (playlist_id INTEGER PRIMARY KEY,title TEXT,is_active INTEGER DEFAULT 1,is_public INTEGER DEFAULT 1,indexable INTEGER DEFAULT 1,is_retired INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE playlist_items (playlist_item_id INTEGER PRIMARY KEY,playlist_id INTEGER,photo_library_id INTEGER,is_active INTEGER DEFAULT 1,site INTEGER DEFAULT 1,concept INTEGER DEFAULT 0,order_index INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE player_experiences (player_experience_id INTEGER PRIMARY KEY,experience_key TEXT,name TEXT,slide_flag TEXT,palette_viewer_key TEXT,rex_parent_experience_key TEXT,cta_page_id INTEGER,is_active INTEGER)');
$pdo->exec('CREATE TABLE rex_reservations (id INTEGER PRIMARY KEY,token TEXT,label TEXT,admin_note TEXT,qr_key TEXT,resolver_key TEXT,resource_type TEXT,resource_id INTEGER,context_json TEXT,status TEXT,revoked_at TEXT,created_at TEXT,updated_at TEXT,experience_key TEXT)');
$pdo->exec("INSERT INTO photo_library (photo_library_id,source_type,inspiration_photo_id,rel_path,title) VALUES (746,'art',NULL,'/art.png','Artwork'),(747,'art_inspired',746,'/design.png','Design'),(800,'art',NULL,'/other.png','Other artwork'),(900,'progression',NULL,'/room.png','Unrelated room')");
$pdo->exec("INSERT INTO photos_artwork (artwork_id,photo_library_id,display_title) VALUES (1,746,'Art study'),(2,800,'Other study')");
$pdo->exec("UPDATE photos_artwork SET artist='Vincent van Gogh',artist_last='van Gogh' WHERE artwork_id=1");
$pdo->exec("INSERT INTO player_experiences VALUES (1,'public','Public','site','default','public',2,1)");
foreach (range(60,75) as $id) {
    $pdo->prepare('INSERT INTO playlists (playlist_id,title) VALUES (?,?)')->execute([$id,'Playlist '.$id]);
    $pdo->prepare('INSERT INTO playlist_items (playlist_item_id,playlist_id,photo_library_id) VALUES (?,?,?)')->execute([$id,$id,$id === 61 ? 747 : ($id === 62 ? 900 : 746)]);
    if ($id !== 67) {
        $pdo->prepare("INSERT INTO rex_reservations (id,token,label,resolver_key,resource_type,resource_id,status,experience_key) VALUES (?,?,'Existing','playlist_experience','playlist',?,'active','public')")
            ->execute([$id,'stable-'.$id,$id]);
    }
}
$pdo->exec('UPDATE playlists SET is_active=0 WHERE playlist_id=63');
$pdo->exec('UPDATE playlists SET is_public=0 WHERE playlist_id=64');
$pdo->exec('UPDATE playlists SET indexable=0 WHERE playlist_id=65');
$pdo->exec('UPDATE playlists SET is_retired=1 WHERE playlist_id=66');
$pdo->exec("UPDATE rex_reservations SET revoked_at='2026-11-10' WHERE resource_id=68");
$pdo->exec("UPDATE rex_reservations SET experience_key='client' WHERE resource_id=69");
$pdo->exec("UPDATE rex_reservations SET status='inactive' WHERE resource_id=70");
$pdo->exec('INSERT INTO playlist_items (playlist_item_id,playlist_id,photo_library_id) VALUES (171,71,800)');
$pdo->exec('UPDATE playlist_items SET is_active=0 WHERE playlist_id=72');
$pdo->exec('UPDATE playlist_items SET site=0,concept=1 WHERE playlist_id=73');
$pdo->exec("INSERT INTO rex_reservations (id,token,label,resolver_key,resource_type,resource_id,status,experience_key) VALUES (174,'newer-duplicate','Duplicate','playlist_experience','playlist',74,'active','public')");
$pdo->exec("UPDATE rex_reservations SET experience_key=NULL,context_json='{" . '"experience_key":"public"' . "}' WHERE resource_id=75");
$checks = 0;
$expect = static function(bool $ok,string $why) use (&$checks): void { if (!$ok) { throw new RuntimeException($why); } $checks++; };
$snapshot = static function() use ($pdo): array {
    $data=[]; foreach(['photo_library','photos_artwork','playlists','playlist_items','rex_reservations','player_experiences'] as $table) {
        $data[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    } return $data;
};
$before=$snapshot();
$service=new ArtworkPageService($pdo);
$gallery = array_column($service->listGallery(), null, 'artwork_id');
$expect($gallery[1]['artist_last'] === 'van Gogh', 'Public gallery preserves the explicit compound surname');
$expect($gallery[1]['artist'] === 'Vincent van Gogh', 'Public gallery retains the complete artist name');
$page=$service->get(1);
$expect(array_column($page['playlists'],'playlist_id') === [60,61,74,75], 'Only eligible, uniquely associated playlists with existing active public REX are discoverable');
$expect(array_column($page['playlists'],'rex_url') === ['/t/stable-60','/t/stable-61','/t/stable-74','/t/stable-75'], 'Reuse earliest stable token including legacy public context, never client/revoked/duplicate destinations');
$expect(array_keys($page['playlists'][0]) === ['playlist_id','title','rex_url'], 'Expose only the additive public link contract');
$expect($before === $snapshot(), 'No tokens, associations, flags, or analytics records are written');
$pdo->exec('UPDATE player_experiences SET is_active=0');
$expect($service->get(1)['playlists'] === [], 'Inactive public experience hides links');
$pdo->exec("UPDATE player_experiences SET is_active=1,slide_flag='concept'");
$expect(array_column($service->get(1)['playlists'],'playlist_id') === [73], 'Use selected Public experience slide flag, not a hardcoded site scope');
$pdo->exec("UPDATE player_experiences SET slide_flag='site'");
$pdo->exec('UPDATE playlists SET is_public=0');
$expect($service->get(1)['playlists'] === [], 'No eligible playlist returns empty list');
$pdo->exec('UPDATE photo_library SET show_in_gallery=0 WHERE photo_library_id=746');
try { $service->get(1); throw new RuntimeException('Private artwork exposed'); }
catch (OutOfBoundsException) { $checks++; }
echo "PASS: {$checks} artwork playlist composition/eligibility/read-only checks\n";
