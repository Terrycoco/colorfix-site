<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';

use App\ARTICLES\Services\PublicArticleService;

// Isolated fixtures only; never load the application database bootstrap.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('FIELD', static function ($id, ...$ids) { return array_search($id, $ids, true) + 1; });
$pdo->exec("CREATE TABLE playlists (playlist_id INTEGER, article_id INTEGER, editorial_type TEXT);
CREATE TABLE cta_groups (id INTEGER, key TEXT, label TEXT, description TEXT);
CREATE TABLE cta_types (cta_type_id INTEGER, action_key TEXT, is_active INTEGER);
CREATE TABLE ctas (cta_id INTEGER, cta_type_id INTEGER, label TEXT, params TEXT, onclick TEXT, is_active INTEGER);
CREATE TABLE cta_group_items (cta_group_id INTEGER, cta_id INTEGER, order_index INTEGER);
CREATE TABLE rex_reservations (id INTEGER, token TEXT, label TEXT, admin_note TEXT, resolver_key TEXT, resource_type TEXT, resource_id INTEGER, status TEXT, experience_key TEXT, revoked_at TEXT, created_at TEXT, updated_at TEXT);
INSERT INTO playlists VALUES (60,4,'artwork'),(61,5,'artwork');
INSERT INTO cta_groups VALUES (12,'artwork-article','Artwork Article','');
INSERT INTO cta_types VALUES (1,'playlist_link',1),(2,'navigate',1);
INSERT INTO ctas VALUES (14,1,'Slideshow:','{}',NULL,1),(30,2,'Explore More Artworks','{\"url\":\"/art-gallery\"}','browse_playlists_click',1),(23,2,'Contact the Designer','{\"url\":\"/send-note\"}',NULL,1);
INSERT INTO cta_group_items VALUES (12,14,1),(12,30,2),(12,23,3);
INSERT INTO rex_reservations VALUES (1,'ingres','Public',NULL,'playlist_experience','playlist',60,'active','public',NULL,NULL,NULL),(2,'other','Public',NULL,'playlist_experience','playlist',61,'active','public',NULL,NULL,NULL);");
$method = new ReflectionMethod(PublicArticleService::class, 'resolveCtas');
$service = new PublicArticleService($pdo);
$resolve = static fn(int $id = 4, array $overrides = []) => $method->invoke($service, ['id' => $id, 'cta_overrides' => json_encode($overrides)]);
$checks = 0;
$expect = static function (bool $value, string $message) use (&$checks) {
    if (!$value) throw new RuntimeException($message);
    $checks++;
};
$ctas = $resolve();
$expect(array_column($ctas, 'cta_id') === [14,30,23], 'Reuse group order and definitions');
$expect($ctas[0]['data']['reserved_playlist_url'] === '/t/ingres', 'Unique article source supplies contextual URL');
$expect(!isset(json_decode($ctas[0]['params'], true)['url']), 'Context is not saved as explicit destination');
$expect($resolve(5)[0]['data']['reserved_playlist_url'] === '/t/other', 'Same group follows a different article');
$expect($ctas[1]['onclick'] === 'browse_playlists_click', 'Keep CTA tracking metadata');
$explicit = $resolve(4, ['14' => ['url' => '/explicit', 'playlist_id' => 61, 'target' => '_blank']]);
$expect(json_decode($explicit[0]['params'], true)['url'] === '/explicit', 'Explicit URL wins over playlist ID and context');
$expect(json_decode($explicit[0]['params'], true)['target'] === '_blank', 'Keep other overrides');
$fixed = $resolve(4, ['14' => ['playlist_id' => 61]]);
$expect(json_decode($fixed[0]['params'], true)['url'] === '/t/other', 'Explicit playlist override wins over article context');
$expect(($resolve(4, ['14' => ['playlist_id' => 999]])[0]['enabled'] ?? true) === false, 'Missing explicit destination never falls back to article');
$expect($resolve(4, ['_cta_ids' => []]) === [], 'Keep intentionally empty CTA selection');
$expect(array_column($resolve(4, ['_cta_ids' => [23]]), 'cta_id') === [23], 'Keep existing selected CTAs');
$expect(array_column($resolve(999, ['_cta_group_id' => 12]), 'cta_id') === [14,30,23], 'Explicit CTA page works independently of artwork classification');
$expect($resolve(999, ['_cta_group_id' => 12])[0]['enabled'] === false, 'CTA page does not guess a missing playlist destination');
$expect(array_column($resolve(4, ['_cta_group_id' => 12, '_cta_ids' => [23]]), 'cta_id') === [14,30,23], 'CTA page takes precedence over legacy individual selection');
$expect($resolve(4, ['_cta_group_id' => 0]) === [], 'None disables even the artwork default');
$expect($resolve(4, ['_cta_group_id' => 999]) === [], 'Missing page does not silently substitute another group');
$pageOverride = $resolve(4, ['_cta_group_id' => 12, '14' => ['url' => '/custom-slideshow', 'label' => 'Custom label']]);
$expect(json_decode($pageOverride[0]['params'], true)['url'] === '/custom-slideshow', 'CTA page preserves existing per-CTA overrides');
$pdo->exec('UPDATE cta_group_items SET order_index=0 WHERE cta_id=23');
$expect(array_column($resolve(4, ['_cta_group_id' => 12]), 'cta_id') === [23,14,30], 'Page is resolved live rather than copied into article');
$pdo->exec('UPDATE cta_group_items SET order_index=3 WHERE cta_id=23');
// Legacy instance-only assignments use the established repository, never guessed URLs.
$instanceColumns = ['playlist_instance_id', 'playlist_id', 'slug', 'instance_name', 'display_title',
    'display_subtitle', 'instance_notes', 'intro_layout', 'intro_title', 'intro_subtitle', 'intro_body',
    'intro_image_url', 'cta_group_id', 'palette_viewer_cta_group_id', 'demo_enabled', 'cta_context_key',
    'audience', 'player_experience_id', 'cta_overrides', 'share_enabled', 'share_title', 'share_description',
    'share_image_url', 'skip_intro_on_replay', 'hide_stars', 'is_active', 'created_from_instance', 'kicker_id'];
$pdo->exec('CREATE TABLE playlist_instances (' . implode(' TEXT, ', $instanceColumns) . ' TEXT)');
$pdo->exec("INSERT INTO playlist_instances (playlist_instance_id,playlist_id,instance_name,intro_layout,is_active) VALUES (90,61,'Legacy public','default',1)");
$legacy = $resolve(999, ['_cta_ids' => [14], '14' => ['playlist_instance_id' => '90', 'label' => 'Legacy slideshow', 'subtitle' => 'Existing subtitle']]);
$legacyParams = json_decode($legacy[0]['params'], true);
$expect($legacyParams['url'] === '/t/other' && ($legacy[0]['enabled'] ?? true), 'Instance-only article CTA resolves and remains visible without article source');
$expect($legacyParams['label'] === 'Legacy slideshow' && $legacyParams['subtitle'] === 'Existing subtitle', 'Preserve legacy editorial overrides');
$expect(json_decode($resolve(4, ['14' => ['playlistInstanceId' => 90]])[0]['params'], true)['url'] === '/t/other', 'Support existing camelCase instance parameter');
$expect(json_decode($resolve(4, ['14' => ['playlist_instance_id' => 90, 'playlist_id' => 60]])[0]['params'], true)['url'] === '/t/ingres', 'Explicit playlist ID still wins over instance');
$expect(json_decode($resolve(4, ['14' => ['playlist_instance_id' => 999, 'url' => '/explicit']])[0]['params'], true)['url'] === '/explicit', 'Explicit URL still wins over instance');
$expect($resolve(4, ['14' => ['playlist_instance_id' => 999]])[0]['enabled'] === false, 'Missing instance does not guess article source');
$pdo->exec("UPDATE rex_reservations SET status='revoked' WHERE resource_id=61");
$expect($resolve(4, ['14' => ['playlist_instance_id' => 90]])[0]['enabled'] === false, 'Legacy instance requires active public REX');
$pdo->exec("UPDATE rex_reservations SET status='active',experience_key='client' WHERE resource_id=61");
$expect($resolve(4, ['14' => ['playlist_instance_id' => 90]])[0]['enabled'] === false, 'Legacy instance cannot use client-only REX');
$pdo->exec("UPDATE rex_reservations SET experience_key='public' WHERE resource_id=61");
$pdo->exec("INSERT INTO playlists VALUES (62,4,'artwork')");
$expect($resolve()[0]['enabled'] === false, 'Ambiguous source suppresses slideshow');
$expect(count($resolve()) === 3, 'Ambiguous source retains other group CTAs');
$pdo->exec('DELETE FROM playlists WHERE playlist_id=62');
$pdo->exec("UPDATE rex_reservations SET status='revoked' WHERE resource_id=60");
$expect($resolve()[0]['enabled'] === false, 'No active public REX suppresses slideshow');
$pdo->exec("UPDATE rex_reservations SET status='active',experience_key='client' WHERE resource_id=60");
$expect($resolve()[0]['enabled'] === false, 'Client REX cannot become public CTA');
$expect($resolve(999) === [], 'Missing source never guesses artwork classification or destination');
$expect($resolve(999, ['_cta_ids' => [14]])[0]['enabled'] === false, 'Selected slideshow without source is unavailable');
$pdo->exec("UPDATE playlists SET editorial_type='teaching' WHERE article_id=4");
$expect($resolve() === [], 'Unrelated article types do not acquire artwork CTAs');
echo "PASS: {$checks} article CTA checks (SQLite fixtures only)\n";
