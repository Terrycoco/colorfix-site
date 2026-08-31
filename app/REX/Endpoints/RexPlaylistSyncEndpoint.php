<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\PV\Repos\PdoPVRepository;
use App\Repos\PdoPlaylistRepository;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexPlaylistAudit;
use App\REX\Services\RexPlaylistLinker;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RexPlaylistSyncEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                self::respond([
                    'ok' => false,
                    'error' => 'POST only',
                ], 405);
            }

            $input = self::jsonInput();

            $playlistId = (int)($input['playlist_id'] ?? 0);

            if ($playlistId <= 0) {
                throw new InvalidArgumentException(
                    'Valid playlist ID is required.'
                );
            }

            $rexRepo = new PdoRexReservationRepository($pdo);

            $audit = new RexPlaylistAudit(
                $pdo,
                new PdoPlaylistRepository($pdo),
                new PdoPVRepository($pdo),
                $rexRepo,
            );

            $linker = new RexPlaylistLinker(
                $audit,
                new PdoPVRepository($pdo),
                $rexRepo,
                new RexReservationRelationships($rexRepo),
                new RexReserver(
                    $rexRepo,
                    new RexTokenGenerator()
                ),
            );

            self::respond([
                'ok' => true,
                'result' => $linker->sync($playlistId),
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

    private static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                'Request body must be valid JSON.'
            );
        }

        return $decoded;
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
