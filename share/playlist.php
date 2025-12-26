<?php
declare(strict_types=1);

use App\Repos\PdoPlaylistRepository;

require_once __DIR__ . '/../api/autoload.php';

$playlistId = (int)($_GET['id'] ?? 0);
if ($playlistId <= 0) {
    http_response_code(404);
    exit('Missing playlist id');
}

$repo = new PdoPlaylistRepository();
$playlist = $repo->getById((string)$playlistId);

if (!$playlist) {
    http_response_code(404);
    exit('Playlist not found');
}


// If you stored meta directly on the definition array in the repo
$meta = $definition['meta'] ?? [];

// Respect share_enabled
if (($meta['share_enabled'] ?? true) === false) {
    http_response_code(404);
    exit;
}

// Resolve share fields with fallbacks
$title = $meta['share_title'] ?? $playlist->title;
$description = $meta['share_description'] ?? '';
$image = $meta['share_image_url'] ?? '';

// Resolve share meta
$meta = $playlist->meta;

if (($meta['share_enabled'] ?? true) === false) {
    http_response_code(404);
    exit;
}

$title = $meta['share_title'] ?? $playlist->title;
$description = $meta['share_description'] ?? '';
$image = $meta['share_image_url'] ?? '';

$host = $_SERVER['HTTP_HOST'];
$shareUrl = "https://{$host}/share/playlist/{$playlistId}";
$appPath = "/playlist/{$playlistId}";

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


exit;
