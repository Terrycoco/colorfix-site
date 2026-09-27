<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistryFactory;
use App\REX\Services\RexResolver;
use PDO;
use Throwable;

final class RexResolveEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                self::respond([
                    'ok' => false,
                    'error' => 'GET only',
                ], 405);
                return;
            }

            $token = trim((string)($_GET['token'] ?? ''));

            if ($token === '') {
                self::respond([
                    'ok' => false,
                    'error' => 'REX token is required.',
                ], 400);
                return;
            }

            $repo = new PdoRexReservationRepository($pdo);
            $reservation = $repo->findByToken($token);

            if (!$reservation) {
                self::respond([
                    'ok' => false,
                    'error' => 'Link not found.',
                ], 404);
                return;
            }

            $resolver = new RexResolver(
                $repo,
                RexResolverRegistryFactory::build($pdo)
            );

            $result = $resolver->resolveToken($token, [
                'entry_point' => 'resolve',
                'src' => $_GET['src'] ?? null,
                'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'return_to' => trim((string)($_GET['return_to'] ?? '')),
                'origin_playlist' => trim((string)($_GET['origin_playlist'] ?? '')),
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);

            self::respond([
                'ok' => true,
                'data' => [
                    'resolver_key' => $result->resolverKey,
                    'resource_type' => $result->resourceType,
                    'resource_id' => $result->resourceId,
                    'behavior' => $result->behavior->value,
                    'destination' => $result->destination,
                    'share_metadata' => [
                        'title' => $result->shareMetadata->title,
                        'description' => $result->shareMetadata->description,
                        'image_url' => $result->shareMetadata->imageUrl,
                    ],
                    'analytics_metadata' => $result->analyticsMetadata,
                    'rex' => [
                        'reservation_id' => $reservation->id,
                        'resolver_key' => $reservation->resolverKey,
                        'resource_type' => $reservation->resourceType,
                        'resource_id' => $reservation->resourceId,
                        'experience_key' => $reservation->experienceKey,
                    ],
                ],
            ]);

        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    private static function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );
    }
}
