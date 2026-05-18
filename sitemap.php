<?php
declare(strict_types=1);

use App\Repos\PdoPlaylistRepository;

require_once __DIR__ . '/api/autoload.php';
require_once __DIR__ . '/api/db.php';

$repo = new PdoPlaylistRepository($pdo);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'colorfix.terrymarr.com';
$origin = $scheme . '://' . $host;

$rows = $repo->listPublicPlayerPages();

header('Content-Type: application/xml; charset=utf-8');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($rows as $row): ?>
  <url>
    <loc><?= htmlspecialchars(rtrim($origin, '/') . '/playlist/' . rawurlencode((string)$row['slug']), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc>
<?php if (!empty($row['updated_at']) || !empty($row['published_at'])): ?>
    <lastmod><?= htmlspecialchars(substr((string)($row['updated_at'] ?: $row['published_at']), 0, 10), ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></lastmod>
<?php endif; ?>
  </url>
<?php endforeach; ?>
</urlset>
