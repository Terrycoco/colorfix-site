<?php
declare(strict_types=1);

namespace App\COLORS\Endpoints;

use App\COLORS\Repos\PdoMaterializedSwatchRepository;
use PDO;
use Throwable;

/** Explicit admin action only; no page-load, search, save or delete hooks. */
final class RefreshSwatchesEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            self::respond(405, ['ok' => false, 'error' => 'POST required.']);
            return;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null) {
            $parts = parse_url($origin);
            $host = strtolower((string)($parts['host'] ?? ''));
            if (isset($parts['port'])) $host .= ':' . $parts['port'];
            if ($host === '' || $host !== strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) {
                self::respond(403, ['ok' => false, 'error' => 'Cross-origin request refused.']);
                return;
            }
        }
        if (!str_starts_with(strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? ''))), 'application/json')) {
            self::respond(415, ['ok' => false, 'error' => 'JSON content type required.']);
            return;
        }
        try {
            @set_time_limit(0);
            $summary = (new PdoMaterializedSwatchRepository($pdo))->refresh();
            self::respond(200, ['ok' => true, 'summary' => $summary]);
        } catch (Throwable $e) {
            error_log('COLORS explicit swatch refresh failed: ' . $e->getMessage());
            self::respond(500, ['ok' => false, 'error' => 'Swatch refresh failed. Existing swatches were retained; check server logs.']);
        }
    }

    private static function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
