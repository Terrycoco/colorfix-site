<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Repos\PdoProjectActivityRepository;
use PDO;
use Throwable;

final class ProjectActivitySaveEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new \InvalidArgumentException('POST required.');
            }

            $raw = file_get_contents('php://input');
            $payload = json_decode((string)$raw, true);

            if (!is_array($payload)) {
                throw new \InvalidArgumentException(
                    'Valid JSON body is required.'
                );
            }

            $projectId = (int)($payload['project_id'] ?? 0);
            $activityDate = trim((string)($payload['activity_date'] ?? ''));
            $description = trim((string)($payload['description'] ?? ''));

            if ($projectId <= 0) {
                throw new \InvalidArgumentException(
                    'Valid project ID required.'
                );
            }

            if ($activityDate === '') {
                throw new \InvalidArgumentException(
                    'Activity date required.'
                );
            }

            if ($description === '') {
                throw new \InvalidArgumentException(
                    'Activity description required.'
                );
            }

            $hours = self::nullableFloat($payload['hours'] ?? null);
            $miles = self::nullableFloat($payload['miles'] ?? null);
            $amount = self::nullableFloat($payload['amount'] ?? null);

            $repository = new PdoProjectActivityRepository($pdo);

            $activityId = $payload['activity_id'] ?? null;
            if ($activityId !== null) {
                $activityId = filter_var($activityId, FILTER_VALIDATE_INT);
                if ($activityId === false || $activityId <= 0) {
                    throw new \InvalidArgumentException('Valid activity ID required.');
                }
                $activity = $repository->updateEntry(
                    activityId: $activityId,
                    projectId: $projectId,
                    activityDate: $activityDate,
                    description: $description,
                    hours: $hours,
                    miles: $miles,
                    amount: $amount
                );
            } else {
                $activity = $repository->createManual(
                    projectId: $projectId,
                    activityDate: $activityDate,
                    description: $description,
                    hours: $hours,
                    miles: $miles,
                    amount: $amount
                );
            }

            echo json_encode([
                'ok' => true,
                'item' => $activity,
            ], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(400);

            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES);
        }
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                'Hours, miles, and amount must be numeric.'
            );
        }

        return (float)$value;
    }
}
