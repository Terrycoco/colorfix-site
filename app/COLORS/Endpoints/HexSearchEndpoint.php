<?php
declare(strict_types=1);

namespace App\COLORS\Endpoints;

use App\COLORS\Repos\PdoHexSearchRepository;
use App\COLORS\Services\HexSearchService;
use InvalidArgumentException;
use PDO;
use Throwable;

final class HexSearchEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Allow: POST');
            http_response_code(405);
            echo json_encode(['error' => 'POST required.']);
            return;
        }
        try {
            $input = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($input)) throw new InvalidArgumentException('Invalid JSON.');
            $params = $input['params'] ?? [];
            if (!is_array($params)) throw new InvalidArgumentException('Invalid search parameters.');
            $hex = $params['hex'] ?? null;
            if (!is_string($hex)) throw new InvalidArgumentException('A six-digit hex color is required.');
            // Hex lookup deliberately searches every brand, regardless of global filters.
            $out = (new HexSearchService(new PdoHexSearchRepository($pdo)))->search($hex);
            echo json_encode([
                'success' => true,
                'meta' => [
                    'meta_id' => 18, 'item_type' => 'swatch', 'search_mode' => 'hex',
                    'preserve_match_order' => true, 'params' => ['hex' => $out['hex']],
                    'reference_color' => $out['reference_color'],
                    'has_header' => 1, 'header_title' => 'Matches for #' . $out['hex'],
                    'header_subtitle' => 'Exact matches first, followed by the closest colors.',
                    'header_content' => '',
                ],
                'results' => $out['results'], 'inserts' => [], 'rowCount' => count($out['results']),
            ], JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('Hex search: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Unable to search colors.']);
        }
    }
}
