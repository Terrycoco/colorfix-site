<?php
declare(strict_types=1);
// CLI-only fixture: exercise the real stored-column reader and HTML renderer.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../autoload.php';
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\REX\DTO\RexShareMetadata;
use App\REX\Endpoints\RexPublicEntryEndpoint;

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE playlists (playlist_id INTEGER, project_id INTEGER, title TEXT, type TEXT, slug TEXT, headline TEXT, page_title TEXT, meta_description TEXT, dek TEXT)');
$stmt = $pdo->prepare('INSERT INTO playlists VALUES (3,NULL,?,?,NULL,?,?,?,?)');
$stmt->execute(['Cottage Palettes', 'normal', 'Existing headline', $input['page_title'] ?? null, $input['meta_description'] ?? null, 'Existing summary']);
$read = new ReflectionMethod(PdoPlaylistRepository::class, 'getPlaylistMeta');
$meta = $read->invoke(new PdoPlaylistRepository($pdo), '3');
$_SERVER['HTTP_HOST'] = 'colorfix.example.test';
$_SERVER['HTTPS'] = 'on';
$_GET['token'] = 'fixture';
$bootstrap = $input['bootstrap'] ?? null;
if ($bootstrap) {
    $bootstrap['payload']['data']['destination']['playback_plan']['page_title'] = trim((string)($meta['page_title'] ?? ''));
    $bootstrap['payload']['data']['destination']['playback_plan']['meta_description'] = trim((string)($meta['meta_description'] ?? ''));
}
$render = new ReflectionMethod(RexPublicEntryEndpoint::class, 'renderReactShell');
$render->invoke(null, $input['shell'], new RexShareMetadata('Existing share title', 'Existing summary'), true, $bootstrap, $meta);
