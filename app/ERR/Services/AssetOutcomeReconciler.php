<?php
declare(strict_types=1);

namespace App\ERR\Services;

use App\ERR\Repos\PdoErrorRepository;
use PDO;
use Throwable;

/**
 * Receive an already-settled CREATE outcome. The caller owns the asset/job state;
 * ERR owns outstanding incident reconciliation and the interactive status feed.
 * This service does not alter PUB lifecycle fields or require a browser session.
 */
final class AssetOutcomeReconciler
{
    private PdoErrorRepository $errors;
    private ErrorStatusBroadcaster $statuses;

    public function __construct(PDO $pdo)
    {
        $this->errors = new PdoErrorRepository($pdo);
        $this->statuses = new ErrorStatusBroadcaster($pdo, null);
    }

    public function videoJobSettled(int $assetId, int $jobId, string $status, string $detail = ''): void
    {
        if ($assetId <= 0 || $jobId <= 0 || !in_array($status, ['complete', 'failed'], true)) {
            throw new \InvalidArgumentException('Invalid CREATE video-job outcome.');
        }

        // Read the browser destinations BEFORE closing the original incidents.
        $incidents = $this->errors->unresolvedForResource('pub_asset', $assetId);
        $destinations = [];
        foreach ($incidents as $incident) {
            $logId = (int)$incident['id'];
            foreach ($this->statuses->recoveryTargetsForLog($logId) as $target) {
                $destinations[$target['session_token'] . ':' . $target['operation_id']] = $target;
            }
        }

        if ($status === 'complete') {
            // One confirmed asset completion clears all outstanding incidents for this asset.
            // History remains in err_log; only unfinished err_working rows are removed.
            $this->errors->resolveUnresolvedForResource(
                'pub_asset', $assetId,
                "CREATE video job #{$jobId} completed; asset #{$assetId} was created successfully."
            );
        } else {
            // A normal failure is still an ERR occurrence, even without Recover/UI.
            // Do not create a duplicate if this asset already has an unresolved CREATE incident.
            $hasCreateIncident = false;
            foreach ($incidents as $incident) {
                if (strtoupper((string)($incident['component'] ?? '')) === 'CREATE') {
                    $hasCreateIncident = true;
                    break;
                }
            }
            if (!$hasCreateIncident) {
                $this->errors->createLog(
                    null, 'PUB',
                    $detail !== '' ? $detail : 'Video rendering failed.',
                    'CREATE', 'settle_video_job', 'pub_asset', $assetId,
                    null, ['video_job_id' => $jobId], 'failed'
                );
            }
        }

        $visualStatus = $status === 'complete' ? 'success' : 'failed';
        $message = $status === 'complete'
            ? "Asset #{$assetId} created successfully (video job #{$jobId})."
            : "Asset #{$assetId} video creation failed (job #{$jobId}).";
        foreach ($destinations as $target) {
            $this->statuses->broadcastToSessionHash(
                $target['session_token'], $target['operation_id'],
                "CREATE — Asset {$assetId}", $visualStatus, $message,
                $status === 'failed' ? $detail : ''
            );
        }
    }
}
