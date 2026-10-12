<?php
declare(strict_types=1);

namespace App\ANA\Repos;

use App\ANA\Contracts\ANAEventRepositoryInterface;
use App\ANA\Contracts\ANASourceRepositoryInterface;
use App\ANA\DTO\ANAEvent;
use PDO;

final class PdoANAEventRepository implements ANAEventRepositoryInterface
{
    private ANASourceRepositoryInterface $sources;

    public function __construct(private PDO $pdo, ?ANASourceRepositoryInterface $sources = null)
    {
        $this->sources = $sources ?? new PdoANASourceRepository($pdo);
    }

    public function record(ANAEvent $event): int
    {
        // Registered page visits derive their resource identity from the URL,
        // never from a numeric ID supplied by the browser.
        $resolvedPageId = null;
        $isDirectPageVisit = $event->resourceType === 'page'
            && $event->eventKey === 'visit';
        if ($isDirectPageVisit) {
            $pathname = parse_url((string)($event->path ?? ''), PHP_URL_PATH);
            $pathname = is_string($pathname) ? $pathname : '';
            $pathname = $pathname !== '/' ? rtrim($pathname, '/') : '/';
            // Legacy alternate homepage URL continues to count as ColorFix Home.
            if ($pathname === '/results/4') $pathname = '/';
            $pageStmt = $this->pdo->prepare(
                'SELECT id FROM ana_pages WHERE path = :path AND track_enabled = 1 LIMIT 1'
            );
            $pageStmt->execute([':path' => $pathname]);
            $found = $pageStmt->fetchColumn();
            if ($found === false) return 0; // Not a registered, enabled page.
            $resolvedPageId = (int)$found;
        }

        // Preserve NULL attribution and the existing rejection of unknown keys.
        if ($event->src !== null && !$this->sources->isValidSource($event->src)) {
            throw new \RuntimeException('Unrecognized analytics source.');
        }

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
            ':resource_id' => $resolvedPageId ?? $event->resourceId,
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
