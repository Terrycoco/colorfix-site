<?php
declare(strict_types=1);

namespace App\REX\Endpoints;

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistryFactory;
use App\REX\Services\RexResolver;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class RexPreviewEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                self::respond([
                    'ok' => false,
                    'error' => 'POST only',
                ], 405);
                return;
            }

            $data = json_decode(
                file_get_contents('php://input') ?: '',
                true
            );

            if (!is_array($data)) {
                self::respond([
                    'ok' => false,
                    'error' => 'Invalid JSON',
                ], 400);
                return;
            }

            $resolverKey = trim(
                (string)($data['resolver_key'] ?? '')
            );

            $resourceType = trim(
                (string)($data['resource_type'] ?? '')
            );

            $resourceId = (int)(
                $data['resource_id'] ?? 0
            );

            $context = is_array(
                $data['context'] ?? null
            )
                ? $data['context']
                : [];

            if ($resolverKey === '') {
                throw new InvalidArgumentException(
                    'Resolver key required.'
                );
            }

            if ($resourceType === '') {
                throw new InvalidArgumentException(
                    'Resource type required.'
                );
            }

            if ($resourceId <= 0) {
                throw new InvalidArgumentException(
                    'Valid resource ID required.'
                );
            }

            $registry =
                RexResolverRegistryFactory::build(
                    $pdo
                );

            $rexResolver =
                new RexResolver(
                    new PdoRexReservationRepository(
                        $pdo
                    ),
                    $registry
                );

            $descriptor =
                $rexResolver->previewDescribe(
                    $resolverKey,
                    $resourceType,
                    $resourceId,
                    $context
                );

            self::respond([
                'ok' => true,
                'descriptor' => [
                    'title' =>
                        $descriptor->title,
                    'fields' =>
                        $descriptor->fields,
                ],
            ]);

        } catch (
            InvalidArgumentException |
            DomainException |
            RuntimeException $e
        ) {
            self::respond([
                'ok' => false,
                'error' =>
                    $e->getMessage(),
            ], 400);

        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }


    /**
     * @param array<string, mixed> $payload
     */
    private static function respond(
        array $payload,
        int $status = 200
    ): void {
        http_response_code($status);

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );
    }
}
