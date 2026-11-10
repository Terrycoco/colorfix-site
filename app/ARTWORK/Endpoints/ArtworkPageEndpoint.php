<?php
declare(strict_types=1);

namespace App\ARTWORK\Endpoints;

use App\ARTWORK\Services\ArtworkPageService;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use Throwable;

/** Public read-only HTTP adapter. Doorbell supplies PDO; no administrator session required. */
final class ArtworkPageEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            header('Allow: GET');
            self::send(['ok' => false, 'error' => 'GET only'], 405);
            return;
        }
        try {
            if (($_GET['action'] ?? '') === 'list') {
                self::send(['ok' => true, 'items' => (new ArtworkPageService($pdo))->listGallery()]);
                return;
            }
            $artworkId = self::positiveOptional('artwork_id');
            $photoId = self::positiveOptional('photo_library_id');
            $page = (new ArtworkPageService($pdo))->get($artworkId, $photoId);
            self::send(['ok' => true, 'data' => $page]);
        } catch (InvalidArgumentException $e) {
            self::send(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (OutOfBoundsException $e) {
            self::send(['ok' => false, 'error' => 'Artwork not found.'], 404);
        } catch (Throwable $e) {
            error_log('ARTWORK public page: ' . $e::class . ': ' . $e->getMessage());
            self::send(['ok' => false, 'error' => 'Artwork temporarily unavailable.'], 503);
        }
    }

    private static function positiveOptional(string $name): ?int
    {
        if (!isset($_GET[$name]) || $_GET[$name] === '') return null;
        $number = filter_var($_GET[$name], FILTER_VALIDATE_INT);
        if ($number === false || $number < 1) throw new InvalidArgumentException('Invalid ' . $name . '.');
        return (int)$number;
    }
    private static function send(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
