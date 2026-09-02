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

        try {
            $token = trim((string)($_GET['token'] ?? ''));

            if ($token === '') {
                throw new \InvalidArgumentException('REX token is required.');
            }


          
            $registry = RexResolverRegistryFactory::build($pdo);
            $resolver = new RexResolver(
                new PdoRexReservationRepository($pdo),
                $registry,
            );

            $result = $resolver->resolveToken($token, [
                'entry_point' => 'rex-public',
                'src' => $_GET['src'] ?? null,
                'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);

            echo json_encode([
                'ok' => true,
            'data' => [
    'resolver_key' => $result->resolverKey,
    'destination' => $result->destination,

    'rex' => [
        'reservation_id' => $result->analyticsMetadata['reservation_id'] ?? null,

        'resolver_key' => $result->resolverKey,
        'resource_type' => $result->resourceType,
        'resource_id' => $result->resourceId,
        'experience_key' => $result->analyticsMetadata['experience_key'] ?? null,
        'analytics' => $result->analyticsMetadata,
    ],
],
            ], JSON_UNESCAPED_SLASHES);

        } catch (Throwable $e) {
            http_response_code(404);

            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES);
        }
    }
}