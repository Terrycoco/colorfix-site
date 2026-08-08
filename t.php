<?php
declare(strict_types=1);

require __DIR__ . '/api/autoload.php';
require __DIR__ . '/api/db.php';

use App\Repos\PdoUrlReservationRepository;
use App\Repos\PdoUrlReservationResourceRepository;
use App\Services\UrlReservationService;
use App\Services\UrlReservations\UrlReservationRegistryFactory;

function t_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function t_origin(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'colorfix.terrymarr.com'));
    return 'https://' . ($host !== '' ? $host : 'colorfix.terrymarr.com');
}

function t_abs(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return t_origin() . '/apple-touch-icon-teal-20260712.png';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    return t_origin() . '/' . ltrim($url, '/');
}

function t_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, noarchive');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex, noarchive"><title>ColorFix Link Not Found</title></head><body>Link not found.</body></html>';
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
try {
    $service = new UrlReservationService(
        new PdoUrlReservationRepository($pdo),
        UrlReservationRegistryFactory::create(new PdoUrlReservationResourceRepository($pdo)),
        t_origin()
    );
    $result = $service->resolveReservation($token);
} catch (Throwable) {
    t_not_found();
}

$og = is_array($result['og'] ?? null) ? $result['og'] : [];
$reservation = is_array($result['reservation'] ?? null) ? $result['reservation'] : [];
$resolution = is_array($result['resolution'] ?? null) ? $result['resolution'] : [];
$isAdmin = (isset($_COOKIE['cf_admin']) && $_COOKIE['cf_admin'] === '1')
    || (isset($_COOKIE['cf_admin_global']) && $_COOKIE['cf_admin_global'] === '1');

$title = trim((string)($og['title'] ?? '')) ?: 'ColorFix by Terry';
$description = trim((string)($og['description'] ?? '')) ?: 'A ColorFix experience by Terry Marr.';
$image = t_abs((string)($og['image_url'] ?? ''));
$url = t_origin() . ($_SERVER['REQUEST_URI'] ?? '/t/' . rawurlencode($token));

if (($reservation['type_key'] ?? '') === 'project_experience') {
    $experienceKey = strtolower(trim((string)($reservation['experience_key'] ?? $resolution['experience_key'] ?? '')));
    $targetParams = [
        'reservation_token' => $token,
        'fresh' => '1',
    ];
    $returnTo = trim((string)($_GET['return_to'] ?? ''));
    if ($returnTo !== '' && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
        $targetParams['return_to'] = $returnTo;
    }
    $targetBase = $experienceKey === 'painter' ? '/project-painter-specs' : '/p/reserved';
    $target = $targetBase . '?' . http_build_query($targetParams, '', '&', PHP_QUERY_RFC3986);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, noarchive');
    header('Location: ' . $target, true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, noarchive');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, noarchive">
  <title><?= t_escape($title) ?></title>
  <meta name="description" content="<?= t_escape($description) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="ColorFix by Terry">
  <meta property="og:title" content="<?= t_escape($title) ?>">
  <meta property="og:description" content="<?= t_escape($description) ?>">
  <meta property="og:image" content="<?= t_escape($image) ?>">
  <meta property="og:url" content="<?= t_escape($url) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= t_escape($title) ?>">
  <meta name="twitter:description" content="<?= t_escape($description) ?>">
  <meta name="twitter:image" content="<?= t_escape($image) ?>">
</head>
<body>
  <main>
    <h1><?= t_escape($title) ?></h1>
    <p><?= t_escape($description) ?></p>
    <?php if ($isAdmin): ?>
      <pre><?= t_escape(json_encode([
          'reservation' => $reservation,
          'resolution' => $resolution,
          'og' => $og,
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
    <?php else: ?>
      <p>This private ColorFix link is valid. Full delivery will be connected in the next pass.</p>
    <?php endif; ?>
  </main>
</body>
</html>
