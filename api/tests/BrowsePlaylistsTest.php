<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/fixtures/ANAFixtureDatabase.php';

use App\ANA\DTO\ANAEvent;
use App\ANA\Repos\PdoANAEventRepository;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PLAYLISTS\Services\PlaylistSetService;
use App\REX\Services\RexSitemapService;

$checks = 0;
function browseCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

// Only in-memory fixtures. No api/db.php or production connection.
$pdo = anaFixtureDatabase();
$pdo->exec('ALTER TABLE ana_pages ADD COLUMN title TEXT');
foreach (['include_in_sitemap INTEGER', 'og_title TEXT', 'og_description TEXT', 'og_image TEXT'] as $column) $pdo->exec('ALTER TABLE ana_pages ADD COLUMN ' . $column);
$pdo->exec('DELETE FROM ana_pages');
$pdo->exec("INSERT INTO ana_pages VALUES (4, '/picker/?set=11', 1, 'Browse Playlists', 1, 'Old title', 'Old description', '/existing-cover.jpg')");
$pdo->exec("INSERT INTO analytics_events(id,event_key,resource_type,resource_id,path,created_at) VALUES (77,'visit','page',4,'/historical','2026-09-01 12:00:00')");
$history = $pdo->query('SELECT * FROM analytics_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$repo = new PdoANAEventRepository($pdo);
browseCheck($repo->record(new ANAEvent(eventKey: 'visit', resourceType: 'page', path: '/picker?set=11')) === 0, 'Legacy query path must not match a normalized page pathname.');
// Supply the desired registration as fixture data, not a production setup API.
$pdo->exec("UPDATE ana_pages SET path='/browse-playlists' WHERE id=4");
browseCheck($pdo->query('SELECT * FROM analytics_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $history, 'Page path changes do not rewrite historical events.');
$id = $repo->record(new ANAEvent(eventKey: 'visit', resourceType: 'page', resourceId: 999, path: '/browse-playlists/?src=client', src: 'client', referrer: 'https://www.google.com/'));
$event = $pdo->query('SELECT * FROM analytics_events WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
browseCheck((int)$event['resource_id'] === 4 && $event['source_key'] === 'client' && $event['referrer'] === 'https://www.google.com/', 'New visits resolve to #4 and preserve attribution.');
browseCheck((new RexSitemapService($pdo))->siteUrls() === ['https://colorfix.terrymarr.com/browse-playlists'], 'Dynamic sitemap uses clean new path.');
// Exercise real set/REX reads and the service filter; fixture playlist content
// avoids unrelated schema-introspection/Gallery dependencies.
class BrowsePlaylistContent extends PdoPlaylistRepository {
    public function getAdminRowById(int $playlistId): ?array {
        return ['playlist_id' => $playlistId, 'title' => "Playlist {$playlistId}", 'slug' => null,
            'is_active' => $playlistId === 4 ? 0 : 1, 'is_public' => $playlistId === 3 ? 0 : 1,
            'indexable' => $playlistId === 2 ? 0 : 1];
    }
    public function getAdminItemRows(int $playlistId): array { return []; }
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE playlist_sets (id INTEGER PRIMARY KEY, handle TEXT,title TEXT,subtitle TEXT,context TEXT,cover_photo_library_id INTEGER,end_cta_label TEXT,end_cta_url TEXT,end_cta_enabled INTEGER,is_retired INTEGER,retired_at TEXT,created_at TEXT,updated_at TEXT)');
$db->exec("INSERT INTO playlist_sets VALUES (11,'browse','Browse',NULL,NULL,NULL,NULL,NULL,1,0,NULL,NULL,NULL)");
$db->exec('CREATE TABLE playlist_set_items (id INTEGER PRIMARY KEY,playlist_set_id INTEGER,playlist_id INTEGER,target_set_id INTEGER,item_type TEXT,experience_key TEXT,sort_order INTEGER,created_at TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE rex_reservations (id INTEGER PRIMARY KEY,token TEXT,label TEXT,admin_note TEXT,qr_key TEXT,resolver_key TEXT,resource_type TEXT,resource_id INTEGER,context_json TEXT,status TEXT,revoked_at TEXT,created_at TEXT,updated_at TEXT,fallback_rex_id INTEGER,experience_key TEXT)');
$insert = $db->prepare("INSERT INTO playlist_set_items VALUES (?,11,?,NULL,'playlist',?,?,NULL,NULL)");
$rex = $db->prepare("INSERT INTO rex_reservations VALUES (?,?,?,NULL,NULL,'playlist_experience','playlist',?,'{}','active',?,NULL,NULL,NULL,?)");
foreach ([1=>'public',2=>'public',3=>'public',4=>'public',5=>'public',6=>'client',7=>'concept',8=>'showcase',9=>'public'] as $id=>$experience) {
    $insert->execute([$id,$id,$experience,$id]);
    if ($id !== 9) $rex->execute([$id,'existing-token-'.$id,'Existing',$id,$id===5?'2026-10-01':null,$experience]);
}
$service = new PlaylistSetService($db);
$property = new ReflectionProperty($service, 'playlists');
$property->setValue($service, new BrowsePlaylistContent($db));
$before = $db->query('SELECT * FROM rex_reservations')->fetchAll(PDO::FETCH_ASSOC);
$marketing = $service->getPublicSet(11, null, true);
browseCheck(array_column($marketing['items'], 'playlist_id') === [1,8], 'Marketing must exclude inactive/private/nonindexable/revoked/client/concept/missing REX members.');
browseCheck(array_column($marketing['items'], 'rex_url') === ['/t/existing-token-1','/t/existing-token-8'], 'Reuse exact existing Public/Showcase tokens.');
$legacy = $service->getPublicSet(11);
browseCheck(array_column($legacy['items'], 'playlist_id') === [1,2,5,6,7,8], 'Existing picker mode retains its existing scope.');
browseCheck($db->query('SELECT * FROM rex_reservations')->fetchAll(PDO::FETCH_ASSOC) === $before, 'Listing must not write REX.');

echo "PASS: {$checks} Browse Playlists page identity/history, attribution, sitemap and marketing eligibility checks.\n";
