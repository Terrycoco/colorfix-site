<?php
declare(strict_types=1);

namespace App\POD\Endpoints;

use App\POD\Managers\PodManager;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class GetPodEndpoint
{
    public static function handle(PDO $pdo): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'OPTIONS') {
            self::sendJson(200, ['ok' => true]);
            return;
        }

        if ($method !== 'GET') {
            if (!headers_sent()) {
                header('Allow: GET, OPTIONS');
            }
            self::sendJson(405, ['ok' => false, 'error' => 'GET only']);
            return;
        }

        try {
            $photoIds = self::parsePhotoIds();
            $includeBefore = self::parseBoolean('before', true);
            $includeCaptions = self::parseBoolean('captions', true);
            $src = self::stringParam('src');
            $sourceAttribution = $src !== '' ? $src : null;

            $manager = new PodManager($pdo);
            $plan = $manager->buildPlaybackPlan(
                photoIds: $photoIds,
                includeBefore: $includeBefore,
                includeCaptions: $includeCaptions,
                sourceAttribution: $sourceAttribution
            );

            self::sendJson(200, [
                'ok' => true,
                'plan' => $plan,
            ]);
        } catch (InvalidArgumentException | DomainException $e) {
       
            self::sendJson(400, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            self::sendJson(500, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return int[] */
    private static function parsePhotoIds(): array
    {
        $raw = self::stringParam('photos');
        if ($raw === '') {
            throw new InvalidArgumentException('photos required');
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (!preg_match('/^[1-9][0-9]*$/D', $part)) {
                throw new InvalidArgumentException('photos must be comma-separated positive integer IDs');
            }
            $id = filter_var($part, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($id === false) {
                throw new InvalidArgumentException('Photo ID is outside the supported integer range');
            }
            $ids[$id] = $id;
        }

        // Keep first-seen order while eliminating duplicate selections.
        return array_values($ids);
    }

    private static function parseBoolean(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $_GET)) {
            return $default;
        }
        $value = self::stringParam($key);
        if ($value !== '0' && $value !== '1') {
            throw new InvalidArgumentException("{$key} must be 0 or 1");
        }
        return $value === '1';
    }

    private static function stringParam(string $key): string
    {
        if (!array_key_exists($key, $_GET)) {
            return '';
        }
        if (!is_string($_GET[$key])) {
            throw new InvalidArgumentException("{$key} must be a single query parameter value");
        }
        return trim($_GET[$key]);
    }

    private static function sendJson(int $status, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $status = 500;
            $json = '{"ok":false,"error":"Could not encode POD response as JSON."}';
        }

        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo $json;
    }
}
