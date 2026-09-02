<?php
declare(strict_types=1);

namespace App\ANA\Repos;

use App\ANA\Contracts\ANAEventRepositoryInterface;
use App\ANA\DTO\ANAEvent;
use PDO;

final class PdoANAEventRepository implements ANAEventRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function record(ANAEvent $event): int
    {
        $payloadJson = $event->payload !== []
            ? json_encode($event->payload, JSON_UNESCAPED_SLASHES)
            : null;

        if ($payloadJson === false) {
            throw new \RuntimeException('ANA payload could not be encoded.');
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO analytics_events (
                event_key,
                is_test,
                reservation_id,
              
                resolver_key,
                resource_type,
                resource_id,
                experience_key,
                source_key,
                session_id,
                referrer,
                viewer_id,
                path,
                payload_json,
                created_at
            ) VALUES (
                :event_key,
                :is_test,
                :reservation_id,
     
                :resolver_key,
                :resource_type,
                :resource_id,
                :experience_key,
                :source_key,
                :session_id,
                :referrer,
                :viewer_id,
                :path,
                :payload_json,
                NOW()
            )"
        );

        $stmt->execute([
            ':event_key' => trim($event->eventKey),
            ':is_test' => $event->isTest ? 1 : 0,
            ':reservation_id' => $event->reservationId,
            ':resolver_key' => $event->resolverKey,
            ':resource_type' => $event->resourceType,
            ':resource_id' => $event->resourceId,
            ':experience_key' => $event->experienceKey,
            ':source_key' => $event->src,
            ':session_id' => $event->sessionId,
            ':referrer' => $event->referrer,
            ':viewer_id' => $event->viewerId,
            ':path' => $event->path,
            ':payload_json' => $payloadJson,
        ]);

        return (int)$this->pdo->lastInsertId();
    }
}