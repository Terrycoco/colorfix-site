<?php
declare(strict_types=1);

use App\Repos\PdoPlaylistRepository;
use App\Services\PlaylistLandingService;

require_once __DIR__ . '/../api/autoload.php';
require_once __DIR__ . '/../api/db.php';

$repo = new PdoPlaylistRepository($pdo);
$service = new PlaylistLandingService($repo);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'colorfix.terrymarr.com';
$origin = $scheme . '://' . $host;

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function renderNotFound(): never
{
    http_response_code(404);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Not Found</title></head><body>Not found</body></html>';
    exit;
}

$slug = trim((string)($_GET['slug'] ?? ''));

if ($slug !== '') {
    $row = $repo->findSeoLandingBySlug($slug);
    if (!$row) {
        renderNotFound();
    }

    $page = $service->buildLandingViewModel($row, $origin);
    $title = h($page['page_title_final']);
    $metaDescription = h($page['meta_description_final']);
    $canonicalUrl = h($page['canonical_url']);
    $heroImage = h((string)($page['hero_image_final'] ?? ''));
    $heroAlt = h((string)($page['hero_alt_final'] ?? ''));
    $watchUrl = h((string)($page['watch_url'] ?? ''));
    $headline = h((string)($page['headline_final'] ?? ''));
    $dek = trim((string)($page['dek_final'] ?? ''));
    $introHtml = (string)($page['intro_html_final'] ?? '');
    $bodyHtml = (string)($page['body_html_final'] ?? '');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $title ?></title>
  <meta name="description" content="<?= $metaDescription ?>">
  <link rel="canonical" href="<?= $canonicalUrl ?>">
  <meta property="og:title" content="<?= $title ?>">
  <meta property="og:description" content="<?= $metaDescription ?>">
  <meta property="og:image" content="<?= $heroImage ?>">
  <meta property="og:type" content="article">
  <meta property="og:url" content="<?= $canonicalUrl ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= $title ?>">
  <meta name="twitter:description" content="<?= $metaDescription ?>">
  <meta name="twitter:image" content="<?= $heroImage ?>">
  <style>
    :root {
      --bg: #f5f7fb;
      --panel: #ffffff;
      --text: #162033;
      --muted: #607086;
      --border: #d9e2ee;
      --accent: #102544;
      --accent-2: #f59b22;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Georgia, "Times New Roman", serif;
      background: linear-gradient(180deg, #fafbfd 0%, var(--bg) 100%);
      color: var(--text);
    }
    .playlist-landing {
      max-width: 1040px;
      margin: 0 auto;
      padding: 28px 18px 56px;
    }
    .playlist-landing__crumbs {
      margin-bottom: 18px;
      font: 600 12px/1.2 system-ui, sans-serif;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .playlist-landing__crumbs a {
      color: var(--muted);
      text-decoration: none;
    }
    .playlist-landing__hero {
      display: grid;
      gap: 18px;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 22px;
      box-shadow: 0 18px 40px rgba(16, 37, 68, .08);
    }
    .playlist-landing__hero-copy {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .playlist-landing h1 {
      margin: 0;
      font-size: clamp(2rem, 4vw, 3.4rem);
      line-height: 1.04;
      letter-spacing: -.03em;
    }
    .playlist-landing__dek {
      margin: 0;
      font: 500 1.05rem/1.6 system-ui, sans-serif;
      color: var(--muted);
      max-width: 72ch;
    }
    .playlist-landing__watch {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: 0 18px;
      border-radius: 999px;
      background: var(--accent);
      color: #fff;
      font: 700 15px/1 system-ui, sans-serif;
      text-decoration: none;
      width: fit-content;
    }
    .playlist-landing__watch:hover {
      background: #0a1a31;
    }
    .playlist-landing__hero-image {
      margin: 0;
      border-radius: 18px;
      overflow: hidden;
      background: #e9eef5;
      border: 1px solid var(--border);
    }
    .playlist-landing__hero-image img {
      display: block;
      width: 100%;
      height: auto;
    }
    .playlist-landing__section {
      margin-top: 18px;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 22px;
      box-shadow: 0 14px 30px rgba(16, 37, 68, .05);
      font: 400 17px/1.75 Georgia, serif;
    }
    .playlist-landing__section h2,
    .playlist-landing__section h3 {
      font-family: system-ui, sans-serif;
      line-height: 1.15;
    }
    .playlist-landing__section p:first-child { margin-top: 0; }
    .playlist-landing__section p:last-child { margin-bottom: 0; }
    @media (max-width: 720px) {
      .playlist-landing { padding: 18px 12px 40px; }
      .playlist-landing__hero, .playlist-landing__section { padding: 16px; border-radius: 18px; }
    }
  </style>
</head>
<body>
  <main class="playlist-landing">
    <div class="playlist-landing__crumbs"><a href="/playlists">Published Playlists</a></div>
    <section class="playlist-landing__hero">
      <div class="playlist-landing__hero-copy">
        <h1><?= $headline ?></h1>
        <?php if ($dek !== ''): ?>
          <p class="playlist-landing__dek"><?= h($dek) ?></p>
        <?php endif; ?>
        <?php if ($watchUrl !== ''): ?>
          <a class="playlist-landing__watch" href="<?= $watchUrl ?>">Watch Now</a>
        <?php endif; ?>
      </div>
      <?php if ($heroImage !== ''): ?>
        <figure class="playlist-landing__hero-image">
          <img src="<?= $heroImage ?>" alt="<?= $heroAlt ?>">
        </figure>
      <?php endif; ?>
    </section>

    <?php if ($introHtml !== ''): ?>
      <section class="playlist-landing__section">
        <?= $introHtml ?>
      </section>
    <?php endif; ?>

    <?php if ($bodyHtml !== ''): ?>
      <section class="playlist-landing__section">
        <?= $bodyHtml ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
    <?php
    exit;
}

$rows = array_map(
    fn(array $row): array => $service->buildLandingViewModel($row, $origin),
    $repo->listSeoLandingPages()
);

$canonicalUrl = h(rtrim($origin, '/') . '/playlists');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Published Playlists | ColorFix</title>
  <meta name="description" content="Explore published ColorFix playlists with clear summaries and direct watch links.">
  <link rel="canonical" href="<?= $canonicalUrl ?>">
  <style>
    :root {
      --bg: #f5f7fb;
      --panel: #ffffff;
      --text: #162033;
      --muted: #607086;
      --border: #d9e2ee;
      --accent: #102544;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: system-ui, sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    .playlist-index {
      max-width: 1160px;
      margin: 0 auto;
      padding: 28px 18px 56px;
    }
    .playlist-index__hero {
      margin-bottom: 18px;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 22px;
      box-shadow: 0 18px 40px rgba(16, 37, 68, .08);
    }
    .playlist-index__hero h1 {
      margin: 0;
      font-size: clamp(2rem, 4vw, 3.2rem);
      line-height: 1.05;
      letter-spacing: -.03em;
    }
    .playlist-index__hero p {
      margin: 10px 0 0;
      max-width: 70ch;
      font-size: 1rem;
      line-height: 1.7;
      color: var(--muted);
    }
    .playlist-index__list {
      display: grid;
      gap: 14px;
    }
    .playlist-index__item {
      display: grid;
      grid-template-columns: 220px minmax(0, 1fr);
      gap: 16px;
      align-items: stretch;
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 14px;
      text-decoration: none;
      color: inherit;
      box-shadow: 0 14px 30px rgba(16, 37, 68, .05);
    }
    .playlist-index__item:hover {
      border-color: #b4c5d9;
    }
    .playlist-index__thumb {
      border-radius: 14px;
      overflow: hidden;
      background: #e9eef5;
      min-height: 130px;
      border: 1px solid var(--border);
    }
    .playlist-index__thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .playlist-index__copy {
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 8px;
    }
    .playlist-index__headline {
      margin: 0;
      font-size: 1.35rem;
      line-height: 1.2;
      color: var(--accent);
    }
    .playlist-index__dek {
      margin: 0;
      color: var(--muted);
      line-height: 1.6;
    }
    @media (max-width: 760px) {
      .playlist-index { padding: 18px 12px 40px; }
      .playlist-index__item { grid-template-columns: 1fr; }
      .playlist-index__thumb { min-height: 180px; }
    }
  </style>
</head>
<body>
  <main class="playlist-index">
    <section class="playlist-index__hero">
      <h1>Published Playlists</h1>
      <p>Browse compact ColorFix playlist landing pages with context, images, and a direct path into the standalone viewing experience.</p>
    </section>

    <section class="playlist-index__list">
      <?php foreach ($rows as $row): ?>
        <a class="playlist-index__item" href="/playlists/<?= h((string)$row['slug']) ?>">
          <div class="playlist-index__thumb">
            <?php if (!empty($row['hero_image_final'])): ?>
              <img src="<?= h((string)$row['hero_image_final']) ?>" alt="<?= h((string)$row['hero_alt_final']) ?>">
            <?php endif; ?>
          </div>
          <div class="playlist-index__copy">
            <h2 class="playlist-index__headline"><?= h((string)$row['headline_final']) ?></h2>
            <?php if (!empty($row['dek_final'])): ?>
              <p class="playlist-index__dek"><?= h((string)$row['dek_final']) ?></p>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </section>
  </main>
</body>
</html>
