<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\REX\Services\RexSitemapService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ana_pages (path TEXT, include_in_sitemap INTEGER DEFAULT 1)');
$pdo->exec("INSERT INTO ana_pages VALUES ('/',1),('/picker',1),('/picker?src=sas#slide',1),('/excluded',0),('/null-flag',NULL)");
$pdo->exec('CREATE TABLE playlists (playlist_id INTEGER PRIMARY KEY, is_active INTEGER, is_public INTEGER, indexable INTEGER)');
$pdo->exec('CREATE TABLE rex_reservations (token TEXT, resource_id INTEGER, resolver_key TEXT, resource_type TEXT, experience_key TEXT, status TEXT, revoked_at TEXT)');
$pdo->exec('INSERT INTO playlists VALUES (1,1,1,1),(2,1,0,1),(3,0,1,1),(4,1,1,0)');
$insert = $pdo->prepare('INSERT INTO rex_reservations VALUES (?,?,?,?,?,?,?)');
foreach ([
    ['public',1,'playlist_experience','playlist','public','active',null],
    ['showcase',1,'playlist_experience','playlist','showcase','active',null],
    ['client',1,'playlist_experience','playlist','client','active',null],
    ['private',2,'playlist_experience','playlist','public','active',null],
    ['inactive',3,'playlist_experience','playlist','public','active',null],
    ['excluded',4,'playlist_experience','playlist','showcase','active',null],
    ['revoked',1,'playlist_experience','playlist','public','active','2026-10-26'],
    ['disabled',1,'playlist_experience','playlist','public','inactive',null],
    ['viewer',1,'viewer','playlist','public','active',null],
    ['missing',99,'playlist_experience','playlist','public','active',null],
] as $row) $insert->execute($row);

$service = new RexSitemapService($pdo);
function same(mixed $actual, mixed $expected): void {
    if ($actual !== $expected) throw new RuntimeException('Unexpected result: ' . json_encode($actual));
}
same($service->siteUrls(), [RexSitemapService::ORIGIN . '/', RexSitemapService::ORIGIN . '/picker']);
// Toggle only this in-memory fixture; no production schema/rows are changed.
$pdo->exec("UPDATE ana_pages SET include_in_sitemap=0 WHERE path='/'");
same($service->siteUrls(), [RexSitemapService::ORIGIN . '/picker']);
$pdo->exec("UPDATE ana_pages SET include_in_sitemap=1 WHERE path='/excluded'");
same($service->siteUrls(), [RexSitemapService::ORIGIN . '/excluded', RexSitemapService::ORIGIN . '/picker']);
same($service->candidateTokens(), ['public', 'showcase']);
// Eligibility changes take effect immediately, without cached output or a rebuild.
$pdo->exec('UPDATE playlists SET indexable=0 WHERE playlist_id=1');
same($service->candidateTokens(), []);
$pdo->exec('UPDATE playlists SET indexable=1 WHERE playlist_id=1');
same($service->candidateTokens(), ['public', 'showcase']);
$insert->execute(['newly-published',1,'playlist_experience','playlist','public','active',null]);
same($service->candidateTokens(), ['newly-published', 'public', 'showcase']);

$urls = array_map(fn(string $token): string => RexSitemapService::ORIGIN . '/t/' . rawurlencode($token), $service->candidateTokens());
$urls[] = $urls[0];
$xml = RexSitemapService::xml($urls);
$doc = new DOMDocument();
same($doc->loadXML($xml), true);
same($doc->documentElement->namespaceURI, 'http://www.sitemaps.org/schemas/sitemap/0.9');
same($doc->getElementsByTagName('loc')->length, 3);
foreach ($doc->getElementsByTagName('loc') as $loc) {
    same(parse_url($loc->textContent, PHP_URL_QUERY), null);
}
$escaped = new DOMDocument();
same($escaped->loadXML(RexSitemapService::xml(['https://example.com/a&b'])), true);
same($escaped->getElementsByTagName('loc')->item(0)->textContent, 'https://example.com/a&b');
echo "PASS: ANA sitemap flags, immediate flag changes, REX eligibility, deduplication, XML namespace and escaping\n";
