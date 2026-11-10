<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\ARTWORK\Services\ArtworkService;
use App\PLAYLISTS\Entities\PlaylistItem;
use App\PLAYLISTS\Services\PlaylistExperienceService;

// In-memory fixtures only: never load the production database bootstrap.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY, source_type TEXT, inspiration_photo_id INTEGER, show_in_gallery INTEGER DEFAULT 1, is_inactive INTEGER DEFAULT 0, is_retired INTEGER DEFAULT 0, rel_path TEXT, title TEXT, alt_text TEXT, palette_id INTEGER)');
$pdo->exec('CREATE TABLE photos_artwork (artwork_id INTEGER PRIMARY KEY, photo_library_id INTEGER UNIQUE)');
$pdo->exec("INSERT INTO photo_library (photo_library_id,source_type,inspiration_photo_id) VALUES
    (746,'art',NULL), (747,'art_inspired',746), (748,'saved_palette',746),
    (800,'art',NULL), (801,'progression',800), (900,'art',NULL),
    (901,'progression',900), (950,'progression',NULL), (951,'progression',950)");
$pdo->exec('INSERT INTO photos_artwork VALUES (1,746),(2,800),(3,950)');
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
};
$art = new ArtworkService($pdo);
$expect($art->publicStudyUrlForPhotos([746]) === '/inspired-by/1', 'Direct artwork slide resolves');
$expect($art->publicStudyUrlForPhotos([747,748]) === '/inspired-by/1', 'Inspired design slides resolve');
$expect($art->publicStudyUrlForPhotos([746,747,748,747]) === '/inspired-by/1', 'Repeated and converging links stay unambiguous');
$expect($art->publicStudyUrlForPhotos([801]) === '/inspired-by/2', 'Another artwork resolves without hardcoded IDs');
foreach ([[], [0,-1,9999], [950], [900], [901], [951], [746,800], [747,901]] as $ids) {
    $expect($art->publicStudyUrlForPhotos($ids) === null, 'Missing, invalid, or conflicting artwork must not resolve: ' . json_encode($ids));
}
foreach (['show_in_gallery' => 0, 'is_inactive' => 1, 'is_retired' => 1] as $field => $value) {
    $pdo->exec("UPDATE photo_library SET {$field}={$value} WHERE photo_library_id=746");
    $expect($art->publicStudyUrlForPhotos([747]) === null, 'Public visibility gate: ' . $field);
    $pdo->exec("UPDATE photo_library SET {$field}=" . ($field === 'show_in_gallery' ? 1 : 0) . ' WHERE photo_library_id=746');
}
$hydrate = new ReflectionMethod(PlaylistExperienceService::class, 'hydrateArtworkCtas');
$service = new PlaylistExperienceService($pdo);
$cta = ['cta_id' => 42, 'action_key' => 'navigate', 'label' => 'Explore the Artwork & Color Study', 'onclick' => 'existing_event',
    'params' => '{"url":"/inspired-by/{artwork_id}?src=pin&playlist={playlist_id}","target":"_self","preserve_src":true,"icon":"palette"}'];
$ordinary = ['cta_id' => 22, 'action_key' => 'navigate', 'params' => '{"url":"/browse-playlists","preserve_src":true}'];
$replay = ['cta_id' => 1, 'action_key' => 'replay', 'params' => ''];
$makeItem = static fn(int $id): PlaylistItem => new PlaylistItem('', null, null, $id);
$before = $pdo->query('SELECT * FROM photo_library ORDER BY photo_library_id')->fetchAll(PDO::FETCH_ASSOC);
$result = $hydrate->invoke($service, [$replay,$cta,$ordinary], [$makeItem(747),$makeItem(748)]);
$params = json_decode($result[1]['params'], true, 512, JSON_THROW_ON_ERROR);
$expect($params['url'] === '/inspired-by/1?src=pin&playlist={playlist_id}', 'Resolve artwork only; retain tracking and other player placeholders');
$expect($params['target'] === '_self' && $params['preserve_src'] === true && $params['icon'] === 'palette', 'Keep Navigate configuration');
$expect($result[1]['onclick'] === 'existing_event' && $result[1]['cta_id'] === 42, 'Keep analytics identity and event contract');
$expect($result[0] === $replay && $result[2] === $ordinary, 'Other CTA order/content stays unchanged');
$expect($hydrate->invoke($service, [$replay,$cta,$ordinary], []) === [$replay,$ordinary], 'Omit unavailable artwork CTA');
$expect($hydrate->invoke($service, [$cta], [$makeItem(746),$makeItem(800)]) === [], 'Omit ambiguous artwork CTA');
$arrayCta = ['key' => 'navigate', 'params' => ['url' => '/inspired-by/{artwork_id}', 'target' => '_blank']];
$arrayResult = $hydrate->invoke($service, [$arrayCta], [$makeItem(801)]);
$expect($arrayResult[0]['params'] === ['url' => '/inspired-by/2', 'target' => '_blank'], 'Array params and alternate artwork supported');
$expect($before === $pdo->query('SELECT * FROM photo_library ORDER BY photo_library_id')->fetchAll(PDO::FETCH_ASSOC), 'Resolution never changes photo associations/visibility');
// A schema-free connection proves unrelated CTAs perform no artwork queries.
$plain = new PlaylistExperienceService(new PDO('sqlite::memory:'));
$expect($hydrate->invoke($plain, [$ordinary,$replay], []) === [$ordinary,$replay], 'No artwork lookup without opt-in placeholder');
echo "PASS: {$checks} artwork CTA checks (SQLite fixtures only)\n";
