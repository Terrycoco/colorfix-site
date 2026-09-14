<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\PV\Repos\PdoPVRepository;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexPlaylistAudit;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RexPlaylistAuditEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
                self::respond([
                    'ok' => false,
                    'error' => 'GET only',
                ], 405);
            }

            $playlistId = (int)($_GET['playlist_id'] ?? 0);

            if ($playlistId <= 0) {
                throw new InvalidArgumentException(
                    'Valid playlist ID is required.'
                );
            }

            $audit = new RexPlaylistAudit(
                $pdo,
                new PdoPlaylistRepository($pdo),
                new PdoPVRepository($pdo),
                new PdoRexReservationRepository($pdo),
            );

            self::respond([
                'ok' => true,
                'data' => $audit->audit($playlistId),
            ]);

        } catch (InvalidArgumentException $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);

        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private static function respond(
        array $payload,
        int $status = 200,
    ): never {
        http_response_code($status);

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}
