<?php
declare(strict_types=1);

use App\Repos\PdoPlaylistInstanceRepository;
use App\Repos\PdoPlaylistRepository;
use App\Services\PlayerExperienceService;

require_once __DIR__ . '/../api/autoload.php';
require_once __DIR__ . '/../api/db.php';

$instanceId = (int)($_GET['id'] ?? 0);
if ($instanceId <= 0) {
    http_response_code(404);
    exit('Missing playlist instance id');
}

$instanceRepo = new PdoPlaylistInstanceRepository($pdo);
$instance = $instanceRepo->getById($instanceId);
if (!$instance) {
    http_response_code(404);
    exit('Playlist instance not found');
}

if ($instance->shareEnabled === false) {
    http_response_code(404);
    exit;
}

$playlistTitle = 'Playlist';
try {
    $playlistRepo = new PdoPlaylistRepository($pdo);
    $playlist = $playlistRepo->getById((string)$instance->playlistId);
    if ($playlist) {
        $playlistTitle = $playlist->title;
    }
} catch (\Throwable $e) {
    // Non-fatal: keep fallback title.
}

$host = $_SERVER['HTTP_HOST'];

$resolvedTitle = '';
$resolvedDescription = '';
$resolvedImage = '';
try {
    $experience = new PlayerExperienceService($pdo);
    $plan = $experience->buildPlaybackPlanFromInstance($instanceId);
    $resolvedTitle = trim((string)($plan['share_title'] ?? $plan['display_title'] ?? $plan['title'] ?? ''));
    $resolvedDescription = trim((string)($plan['project_summary'] ?? $plan['share_description'] ?? ''));
    $resolvedImage = trim((string)($plan['share_image_url'] ?? ''));
} catch (\Throwable $e) {
    // Fall back to raw instance fields below.
}

$title = $resolvedTitle !== '' ? $resolvedTitle : ($instance->shareTitle ?: ($instance->displayTitle ?: $playlistTitle));
$description = $resolvedDescription !== '' ? $resolvedDescription : (string)($instance->shareDescription ?? '');
$image = $resolvedImage !== '' ? $resolvedImage : (string)($instance->shareImageUrl ?? '');
$image = trim($image);
if ($image !== '' && !preg_match('#^https?://#i', $image)) {
    if (str_starts_with($image, '/')) {
        $image = "https://{$host}{$image}";
    } elseif (!str_starts_with($image, 'photo:')) {
        $image = "https://{$host}/" . ltrim($image, '/');
    }
}

$shareUrl = "https://{$host}/share/playlist.php?id={$instanceId}";
$pathId = trim((string)($instance->slug ?? ''));
if ($pathId === '') {
    $pathId = (string)$instanceId;
}
$appPath = "/playlist/" . rawurlencode($pathId);

$forwardParams = $_GET;
unset($forwardParams['id']);
$forwardQuery = http_build_query($forwardParams, '', '&', PHP_QUERY_RFC3986);
if ($forwardQuery !== '') {
    $shareUrl .= '&' . $forwardQuery;
    $appPath .= '?' . $forwardQuery;
}

$titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
$descEsc  = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
$imageEsc = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
$shareEsc = htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8');
$appEsc   = htmlspecialchars($appPath, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $titleEsc ?></title>

  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= $titleEsc ?>">
  <meta property="og:description" content="<?= $descEsc ?>">
  <meta property="og:image" content="<?= $imageEsc ?>">
  <meta property="og:url" content="<?= $shareEsc ?>">

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">

  <!-- Redirect humans -->
  <meta http-equiv="refresh" content="0; url=<?= $appEsc ?>">
</head>
<body>
Redirecting…
</body>
</html>
