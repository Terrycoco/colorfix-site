<?php
declare(strict_types=1);
namespace App\PALETTES\Endpoints;

use App\PALETTES\Services\PaletteViewerAccessService;
use PDO;
use OutOfBoundsException;
use Throwable;

final class ArtInspiredPhotoPVEndpoint
{
    /** Narrow public entry: validates a public inspiration relationship before reserving anything. */
    public static function handle(PDO $pdo): void
    {
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405); header('Allow: GET'); return;
        }
        try {
            $photoId = filter_var($_GET['photo_library_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$photoId || $photoId < 1) { http_response_code(400); return; }
            $item = (new PaletteViewerAccessService($pdo))->openForArtInspiredPhoto($photoId);
            $url = $item['public_url'];
            $src = (string)($_GET['src'] ?? '');
            if ($src !== '' && strlen($src) <= 100) $url .= '?src=' . rawurlencode($src);
            header('Location: ' . $url, true, 302);
        } catch (OutOfBoundsException $e) {
            http_response_code(404); echo 'Art-inspired photograph unavailable.';
        } catch (Throwable $e) {
            error_log('Art-inspired PV failed: ' . get_class($e));
            http_response_code(503); echo 'Unable to open this palette. Please try again.';
        }
    }
}
