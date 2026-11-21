<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistryFactory;
use PDO;
use App\ARTICLES\Repos\PdoArticleRepository;
use Throwable;

final class RexSitemapService
{
    public const ORIGIN = 'https://colorfix.terrymarr.com';

    public function __construct(private PDO $pdo) {}

    public function siteUrls(): array
    {
        $paths = $this->pdo->query(
            'SELECT DISTINCT path FROM ana_pages WHERE include_in_sitemap = 1 ORDER BY path'
        )->fetchAll(PDO::FETCH_COLUMN);
        $urls = [];
        foreach ($paths as $path) {
            // Registry entries are site paths. Metadata URLs omit query/hash state.
            $path = parse_url(trim((string)$path), PHP_URL_PATH);
            if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
                continue;
            }
            // Article eligibility comes exclusively from ARTICLES, not stale ANA registrations.
            if (preg_match('~^/articles/~i', $path)) continue;
            $urls[] = self::ORIGIN . $path;
        }
        return array_values(array_unique($urls));
    }

    /** Only discover explicitly public marketing reservations, never private share tokens. */
    public function candidateTokens(): array
    {
        return $this->pdo->query(<<<'SQL'
SELECT DISTINCT r.token
  FROM rex_reservations r
  JOIN playlists p ON p.playlist_id = r.resource_id
 WHERE r.resolver_key = 'playlist_experience'
   AND r.resource_type = 'playlist'
   AND r.experience_key IN ('public', 'showcase')
   AND r.status = 'active' AND r.revoked_at IS NULL
   AND p.is_active = 1 AND p.is_public = 1 AND p.indexable = 1
 ORDER BY r.token
SQL)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function urls(): array
    {
        $urls = array_merge($this->siteUrls(), array_map(
            static fn(int $id) => self::ORIGIN . '/articles/' . $id,
            (new PdoArticleRepository($this->pdo))->publishedIds()
        ));
        $resolver = new RexResolver(
            new PdoRexReservationRepository($this->pdo),
            RexResolverRegistryFactory::build($this->pdo)
        );
        foreach ($this->candidateTokens() as $token) {
            try {
                $result = $resolver->resolveToken($token, ['entry_point' => 'sitemap']);
                if (RexIndexingPolicy::allows($this->pdo, $result)) {
                    $urls[] = self::ORIGIN . '/t/' . rawurlencode($token);
                }
            } catch (Throwable $e) {
                error_log('REX sitemap: skipped unresolved public destination: ' . $e->getMessage());
            }
        }
        return array_values(array_unique($urls));
    }

    public static function xml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (array_unique($urls) as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>' . "\n";
        }
        return $xml . '</urlset>' . "\n";
    }
}
