<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';

use App\REX\Services\RexPlaylistExperienceSyncService;
use App\SAS\Services\RexPlaylistSearchService;

// Isolated SQL fixture: execute the real repositories, graph sync and reserver.
// Adapt only MySQL dialect/schema inspection; never connect to production.
final class SASPlaylistPDO extends PDO
{
    public array $statements = [];
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('NOW', fn() => '2026-11-06 12:00:00');
    }
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements[] = $query;
        if (str_contains($query, 'INFORMATION_SCHEMA.COLUMNS')) {
            $query = str_contains($query, ':column') ? 'SELECT 0 WHERE :column IS NOT NULL' : 'SELECT 0';
        }
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo = new SASPlaylistPDO();
$pdo->exec('CREATE TABLE playlists (playlist_id INTEGER PRIMARY KEY, project_id INTEGER, title TEXT, type TEXT,
 is_active INTEGER DEFAULT 0, is_public INTEGER DEFAULT 0, indexable INTEGER DEFAULT 0, is_retired INTEGER DEFAULT 0,
 slug TEXT, headline TEXT, page_title TEXT, meta_description TEXT, dek TEXT, intro_html TEXT, body_html TEXT,
 hero_image_id INTEGER, hero_image_url TEXT, hero_alt TEXT, published_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY, rel_path TEXT, alt_text TEXT, title TEXT)');
$pdo->exec('CREATE TABLE playlist_items (playlist_item_id INTEGER PRIMARY KEY, playlist_id INTEGER, order_index INTEGER,
 ap_id INTEGER, palette_hash TEXT, image_url TEXT, saved_palette_id INTEGER, title TEXT, subtitle TEXT, subtitle_2 TEXT,
 body TEXT, item_type TEXT, layout TEXT, title_mode TEXT, star INTEGER, transition TEXT, duration_ms INTEGER,
 is_active INTEGER)');
$pdo->exec('CREATE TABLE player_experiences (player_experience_id INTEGER PRIMARY KEY, experience_key TEXT, name TEXT,
 slide_flag TEXT, palette_viewer_key TEXT, rex_parent_experience_key TEXT, cta_page_id INTEGER,
 is_active INTEGER, sort_order INTEGER, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE cta_groups (id INTEGER PRIMARY KEY, `key` TEXT, label TEXT)');
$pdo->exec('CREATE TABLE rex_reservations (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE, label TEXT, admin_note TEXT,
 qr_key TEXT, resolver_key TEXT, experience_key TEXT, resource_type TEXT, resource_id INTEGER, context_json TEXT,
 status TEXT, revoked_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE rex_aliases (id INTEGER PRIMARY KEY, reservation_id INTEGER, alias TEXT)');
$pdo->exec('CREATE TABLE rex_reservation_links (id INTEGER PRIMARY KEY, parent_reservation_id INTEGER,
 child_reservation_id INTEGER, relationship_key TEXT, sort_order INTEGER, created_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO playlists (playlist_id,title,type,is_active,is_public,indexable) VALUES
 (1,'Existing','marketing',1,1,1),(2,'Draft without REX','marketing',0,0,0),(3,'Revoked','marketing',1,0,0)");
$insert=$pdo->prepare('INSERT INTO player_experiences (player_experience_id,experience_key,name,slide_flag,palette_viewer_key,rex_parent_experience_key,is_active,sort_order) VALUES (?,?,?,?,?,?,1,?)');
foreach (['public'=>'site','concept'=>'concept','showcase'=>'showcase','client'=>'client','custom'=>'site'] as $key=>$flag) {
    $id=($id??0)+1; $insert->execute([$id,$key,ucfirst($key),$flag,'none',$key,$id]);
}
$pdo->exec("INSERT INTO rex_reservations (token,label,resolver_key,experience_key,resource_type,resource_id,context_json,status)
 VALUES ('stable_token','Existing','playlist_experience','public','playlist',1,'{}','active'),
 ('thumb_token','Thumbs','playlist_thumbs','showcase','playlist',1,'{}','active'),
 ('revoked_token','Revoked','playlist_experience','public','playlist',3,'{}','revoked')");
$before = $pdo->query('SELECT * FROM playlists ORDER BY playlist_id')->fetchAll(PDO::FETCH_ASSOC);
$service = new RexPlaylistSearchService($pdo);
$catalogue = $service->listRexPlaylists();
check(count($catalogue)===3, 'All saved playlists, including inactive drafts, must appear.');
$byId=array_column($catalogue,null,'playlist_id');
check(count($byId[2]['experiences'])===5, 'Missing REXs must not hide built-in/custom experience choices.');
check($pdo->query('SELECT COUNT(*) FROM rex_reservations')->fetchColumn()===3, 'Listing must not create REXs.');
check(array_column($byId[1]['experiences'],null,'experience_key')['public']['token']==='stable_token','Existing token must remain stable.');
check(array_column($byId[1]['experiences'],null,'experience_key')['showcase']['token']===null,'Thumbs REX must not masquerade as playlist playback.');
$sync = new RexPlaylistExperienceSyncService($pdo);
foreach (['public','concept','showcase','client','custom'] as $key) {
    $result=$sync->syncPlaylist(2,$key);
    check($result['ok']===true, 'Selected built-in/custom experience must sync.');
    $token=$pdo->query("SELECT token FROM rex_reservations WHERE resource_id=2 AND experience_key='$key'")->fetchColumn();
    check(is_string($token)&&strlen($token)>10,'A missing REX must be created, even for an empty draft.');
    $sync->syncPlaylist(2,$key);
    check($pdo->query("SELECT COUNT(*) FROM rex_reservations WHERE resource_id=2 AND experience_key='$key'")->fetchColumn()===1,'Repeat selection must reuse one identity.');
    check($pdo->query("SELECT token FROM rex_reservations WHERE resource_id=2 AND experience_key='$key'")->fetchColumn()===$token,'Repeated selection must retain the token.');
}
$sync->syncPlaylist(1,'public');
check($pdo->query("SELECT token FROM rex_reservations WHERE resource_id=1 AND resolver_key='playlist_experience'")->fetchColumn()==='stable_token','Sync must preserve existing identities.');
$revoked=$sync->syncPlaylist(3,'public');
check($revoked['ok']===false && $pdo->query('SELECT COUNT(*) FROM rex_reservations WHERE resource_id=3')->fetchColumn()===1,'A revoked permanent identity must not be replaced.');
check($pdo->query('SELECT * FROM playlists ORDER BY playlist_id')->fetchAll(PDO::FETCH_ASSOC)===$before,'No playlist fields, publication flags or metadata may change.');
check($pdo->query('SELECT COUNT(*) FROM playlist_items')->fetchColumn()===0,'Creation must not save or invent slides.');
// Saved slides also survive selected and full desktop sync unchanged.
$pdo->exec("INSERT INTO playlist_items (playlist_item_id,playlist_id,order_index,title,item_type,is_active) VALUES (21,2,1,'Saved cover','cover',1)");
$slides=$pdo->query('SELECT * FROM playlist_items')->fetchAll(PDO::FETCH_ASSOC);
$sync->syncPlaylist(2,'public');
$sync->syncPlaylist(2);
check($pdo->query('SELECT * FROM playlist_items')->fetchAll(PDO::FETCH_ASSOC)===$slides,'Sync must preserve existing saved slides.');
check($pdo->query('SELECT * FROM playlists ORDER BY playlist_id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Full/selected sync must preserve draft flags.');
check($pdo->query("SELECT COUNT(*) FROM rex_reservations WHERE resource_id=2 AND experience_key='custom'")->fetchColumn()===1,'Desktop full sync must preserve custom identities.');
try {
    $sync->syncPlaylist(999,'public');
    throw new RuntimeException('A missing playlist must fail.');
} catch (InvalidArgumentException $e) {
    check(!$pdo->inTransaction(),'Failed creation must roll back its transaction.');
}

$locks=array_values(array_filter($pdo->statements,fn($sql)=>str_contains($sql,'FOR UPDATE')));
check(count($locks)>10,'Canonical creation must lock playlist rows and use current reservation reads.');
$pdo->beginTransaction();
$sync->syncPlaylist(2,'public');
check($pdo->inTransaction(),'Canonical sync must preserve its caller-owned transaction and lock lifetime.');
$pdo->rollBack();
echo "PASS: all saved playlists; read-only catalogue; standard/custom/empty creation; repeat/existing token reuse; revoked protection; flags/slides unchanged; lock SQL and transaction ownership.\n";
