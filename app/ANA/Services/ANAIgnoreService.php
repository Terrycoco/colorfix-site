<?php
declare(strict_types=1);

namespace App\ANA\Services;

use App\Repos\PdoAppConfigRepository;
use InvalidArgumentException;

final class ANAIgnoreService
{
    public const CONFIG_KEY = 'ana_ignore';
    public const DEFAULT_PATTERNS = ['ElevenlabsCrawler', 'Googlebot', 'Google-InspectionTool'];
    private ?array $patterns = null;

    public function __construct(private PdoAppConfigRepository $configs) {}

    /** Request-local cache; the next request sees the latest saved config. */
    public function patterns(): array
    {
        if ($this->patterns === null) {
            $config = $this->configs->getJson(self::CONFIG_KEY);
            $this->patterns = self::normalize($config['patterns'] ?? self::DEFAULT_PATTERNS);
        }
        return $this->patterns;
    }

    public function matches(string $userAgent): bool
    {
        foreach ($this->patterns() as $pattern) {
            if (stripos($userAgent, $pattern) !== false) return true;
        }
        return false;
    }

    public function save(array $patterns): array
    {
        $normalized = self::normalize($patterns);
        $this->configs->setJson(self::CONFIG_KEY, ['patterns' => $normalized]);
        return $this->patterns = $normalized;
    }

    private static function normalize(mixed $patterns): array
    {
        if (!is_array($patterns) || !array_is_list($patterns) || count($patterns) > 100) {
            throw new InvalidArgumentException('Use a list of at most 100 crawler user-agent patterns.');
        }
        $result = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) throw new InvalidArgumentException('Each crawler pattern must be text.');
            $pattern = trim($pattern);
            if ($pattern === '') continue;
            if (strlen($pattern) < 3 || strlen($pattern) > 200 || preg_match('/[\x00-\x1f\x7f]/', $pattern)
                || in_array(strtolower($pattern), ['bot', 'crawler'], true)
                || preg_match('/^(mozilla|chrome|safari|applewebkit|gecko)(\/|$)/i', $pattern)) {
                throw new InvalidArgumentException('Use specific crawler tokens, such as Googlebot; generic browser patterns are not allowed.');
            }
            $result[strtolower($pattern)] = $pattern;
        }
        return array_values($result);
    }
}
