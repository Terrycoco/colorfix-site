<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionResult;
use App\REX\DTO\RexShareMetadata;
use App\REX\Endpoints\RexPublicEntryEndpoint;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE playlists (playlist_id INTEGER PRIMARY KEY, is_active INTEGER, is_public INTEGER, indexable INTEGER)');
$insert = $pdo->prepare('INSERT INTO playlists VALUES (?, ?, ?, ?)');
foreach ([[18, 1, 1, 1], [19, 0, 1, 1], [20, 1, 0, 1], [21, 1, 1, 0], [22, 1, 1, null]] as $row) {
    $insert->execute($row);
}

$eligibility = new ReflectionMethod(RexPublicEntryEndpoint::class, 'isIndexablePlaylist');
$metadata = new ReflectionMethod(RexPublicEntryEndpoint::class, 'applyRobotsMetadata');
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

foreach (['public', 'showcase', 'client', 'concept', 'painter', ''] as $experience) {
    foreach ([18, 19, 20, 21, 22, 999] as $id) {
        $result = new RexResolutionResult(
            resolverKey: 'playlist_experience', resourceType: 'playlist', resourceId: $id,
            behavior: RexResolutionBehavior::RENDER, shareMetadata: new RexShareMetadata(),
            // Deliberately misleading plan flags must never override stored fields.
            destination: ['playback_plan' => ['experience_key' => $experience, 'is_public' => true, 'indexable' => true]],
        );
        $expected = $id === 18 && in_array($experience, ['public', 'showcase'], true);
        check($eligibility->invoke(null, $pdo, $result) === $expected, "Eligibility: {$experience}/{$id}");
    }
}

foreach (['viewer', 'document', 'playlist_thumbs', 'route'] as $resolver) {
    $result = new RexResolutionResult(
        resolverKey: $resolver, resourceType: 'playlist', resourceId: 18,
        behavior: RexResolutionBehavior::RENDER, shareMetadata: new RexShareMetadata(),
        destination: ['playback_plan' => ['experience_key' => 'public']],
    );
    check(!$eligibility->invoke(null, $pdo, $result), "Excluded resolver: {$resolver}");
}

// The query must use resolved resourceId, not a stale/original plan playlist ID.
$fallback = new RexResolutionResult(
    resolverKey: 'playlist_experience', resourceType: 'playlist', resourceId: 20,
    behavior: RexResolutionBehavior::RENDER, shareMetadata: new RexShareMetadata(),
    destination: ['playback_plan' => ['experience_key' => 'public', 'playlist_id' => 18]],
);
check(!$eligibility->invoke(null, $pdo, $fallback), 'Resolved private destination must stay excluded');

foreach ([
    '<meta name="robots" content="noindex, noarchive">',
    "<meta content='noindex' name='robots' /><META NAME=\"ROBOTS\" CONTENT=\"noindex\">",
    '',
] as $existing) {
    $shell = '<html><head><title>Playlist</title>' . $existing . '</head><body><script src="/assets/app.js"></script></body></html>';
    foreach ([true, false] as $indexable) {
        $html = $metadata->invoke(null, $shell, $indexable);
        check(substr_count($html, 'name="robots"') === 1, 'Exactly one robots tag');
        check(str_contains($html, $indexable ? 'content="index, follow"' : 'content="noindex, noarchive"'), 'Matching robots policy');
        check(!$indexable || !str_contains($html, 'noindex'), 'No inherited noindex for eligible playlist');
        check(str_contains($html, '<title>Playlist</title>') && str_contains($html, '<script src="/assets/app.js"></script>'), 'Preserve title and playback bootstrap');
    }
}

$publicUrl = new ReflectionMethod(RexPublicEntryEndpoint::class, 'publicRexUrl');
$_SERVER['HTTP_HOST'] = 'colorfix.terrymarr.com';
$_SERVER['HTTPS'] = 'on';
foreach (['public-token', 'showcase-token', 'fallback-entry-token'] as $token) {
    $_GET = ['token' => $token, 'src' => 'sas', 'return_to' => '/picker'];
    $_SERVER['REQUEST_URI'] = '/t/' . $token . '?src=sas&return_to=%2Fpicker';
    check($publicUrl->invoke(null) === 'https://colorfix.terrymarr.com/t/' . $token, 'Experience-specific canonical excludes tracking');
}

echo "PASS: {$checks} REX indexing/canonical checks\n";
