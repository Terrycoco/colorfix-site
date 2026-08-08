<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoUrlReservationRepository;
use App\Services\UrlReservations\UrlReservationResolverRegistry;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

final class UrlReservationService
{
    public function __construct(
        private PdoUrlReservationRepository $repo,
        private UrlReservationResolverRegistry $registry,
        private string $baseUrl = 'https://colorfix.terrymarr.com'
    ) {}

    public function listTypes(bool $includeInactive = true): array
    {
        return array_map(fn(array $row): array => $this->typePayload($row), $this->repo->listTypes($includeInactive));
    }

    public function reserveUrl(array $input): array
    {
        $typeKey = $this->requiredString($input['type_key'] ?? null, 'type_key required');
        $type = $this->repo->findTypeByKey($typeKey);
        if (!$type || (int)($type['is_active'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Reservation type is not active');
        }

        $resourceId = (int)($input['resource_id'] ?? 0);
        if ($resourceId <= 0) {
            throw new InvalidArgumentException('resource_id required');
        }
        $params = $this->normalizeParams($input['params'] ?? null);
        $rowForValidation = [
            'reservation_type_id' => (int)$type['id'],
            'resource_id' => $resourceId,
            'experience_key' => $this->optionalString($input['experience_key'] ?? null),
            'source_key' => $this->optionalSourceKey($input['source_key'] ?? null),
            'resolver_key' => (string)$type['resolver_key'],
            'delivery_mode' => (string)$type['delivery_mode'],
            'type_key' => (string)$type['type_key'],
        ];
        $this->registry->get((string)$type['resolver_key'])->resolve($rowForValidation, $params);

        $paramsJson = $params === [] ? null : $this->encodeJson($params);
        $id = 0;
        $token = '';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $token = $this->generateToken();
            if ($this->repo->tokenExists($token)) {
                continue;
            }
            try {
                $id = $this->repo->insertReservation([
                    'token' => $token,
                    'reservation_type_id' => (int)$type['id'],
                    'resource_id' => $resourceId,
                    'experience_key' => $rowForValidation['experience_key'],
                    'source_key' => $rowForValidation['source_key'],
                    'params_json' => $paramsJson,
                    'label' => $this->optionalString($input['label'] ?? null),
                    'og_title' => $this->optionalString($input['og_title'] ?? null),
                    'og_description' => $this->optionalString($input['og_description'] ?? null),
                    'og_image_url' => $this->optionalTrustedUrl($input['og_image_url'] ?? null),
                    'expires_at' => $this->optionalDateTime($input['expires_at'] ?? null),
                ]);
                break;
            } catch (PDOException $e) {
                if ($this->repo->isDuplicateTokenError($e)) {
                    continue;
                }
                throw $e;
            }
        }
        if ($id <= 0 || $token === '') {
            throw new RuntimeException('Unable to allocate unique reservation token');
        }

        return [
            'reservation_id' => $id,
            'token' => $token,
            'public_url' => $this->publicUrl($token),
        ];
    }

    public function resolveReservation(string $token): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,80}$/', $token)) {
            throw new InvalidArgumentException('Reservation not found');
        }
        $row = $this->repo->findByToken($token);
        if (!$row || !$this->isResolvable($row)) {
            throw new InvalidArgumentException('Reservation not found');
        }
        $params = $this->decodeJson($row['params_json'] ?? null);
        $resolved = $this->registry->get((string)$row['resolver_key'])->resolve($row, $params);
        $ogDefaults = is_array($resolved['og_defaults'] ?? null) ? $resolved['og_defaults'] : [];
        return [
            'reservation' => $this->reservationPayload($row),
            'resolution' => $resolved,
            'og' => [
                'title' => $this->firstString($row['og_title'] ?? null, $ogDefaults['title'] ?? null, 'ColorFix by Terry'),
                'description' => $this->firstString($row['og_description'] ?? null, $ogDefaults['description'] ?? null, 'A ColorFix experience by Terry Marr.'),
                'image_url' => $this->firstString($row['og_image_url'] ?? null, $ogDefaults['image_url'] ?? null, '/apple-touch-icon-teal-20260712.png'),
            ],
        ];
    }

    public function getReservation(int $id): array
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            throw new InvalidArgumentException('Reservation not found');
        }
        return $this->reservationPayload($row);
    }

    public function listReservations(array $filters = [], int $limit = 200): array
    {
        return array_map(fn(array $row): array => $this->reservationPayload($row), $this->repo->listReservations($filters, $limit));
    }

    public function getActiveProjectExperienceReservation(int $projectId, string $experienceKey): ?array
    {
        $row = $this->repo->findActiveProjectExperienceReservation(
            $projectId,
            $this->normalizeProjectExperienceKey($experienceKey),
            true
        );
        return $row ? $this->reservationPayload($row) : null;
    }

    public function reserveProjectExperienceAdminToken(int $projectId, string $experienceKey, bool $regenerate = false): array
    {
        $experienceKey = $this->normalizeProjectExperienceKey($experienceKey);
        if ($regenerate) {
            $this->repo->revokeActiveProjectExperienceReservations($projectId, $experienceKey, true);
        } else {
            $existing = $this->getActiveProjectExperienceReservation($projectId, $experienceKey);
            if ($existing) {
                return [
                    'reservation_id' => $existing['id'],
                    'token' => $existing['token'],
                    'public_url' => $existing['public_url'],
                    'reused' => true,
                ];
            }
        }

        $created = $this->reserveUrl([
            'type_key' => 'project_experience',
            'resource_id' => $projectId,
            'experience_key' => $experienceKey,
            'source_key' => null,
            'label' => 'Admin test ' . ucfirst($experienceKey),
        ]);
        $created['reused'] = false;
        return $created;
    }

    public function updateReservation(int $id, array $fields): array
    {
        $payload = [];
        foreach (['label', 'og_title', 'og_description'] as $key) {
            if (array_key_exists($key, $fields)) {
                $payload[$key] = $this->optionalString($fields[$key]);
            }
        }
        if (array_key_exists('og_image_url', $fields)) {
            $payload['og_image_url'] = $this->optionalTrustedUrl($fields['og_image_url']);
        }
        if (array_key_exists('expires_at', $fields)) {
            $payload['expires_at'] = $this->optionalDateTime($fields['expires_at']);
        }
        if (array_key_exists('is_active', $fields)) {
            $payload['is_active'] = (bool)$fields['is_active'];
        }
        $this->repo->updateMutableFields($id, $payload);
        return $this->getReservation($id);
    }

    public function revokeReservation(int $id): bool
    {
        return $this->repo->revoke($id);
    }

    public function publicUrl(string $token): string
    {
        return rtrim($this->baseUrl, '/') . '/t/' . rawurlencode($token);
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function isResolvable(array $row): bool
    {
        if ((int)($row['is_active'] ?? 0) !== 1 || (int)($row['type_is_active'] ?? 0) !== 1) {
            return false;
        }
        if (!empty($row['revoked_at'])) {
            return false;
        }
        $expiresAt = $this->optionalString($row['expires_at'] ?? null);
        return $expiresAt === null || strtotime($expiresAt) >= time();
    }

    private function reservationPayload(array $row): array
    {
        $token = (string)($row['token'] ?? '');
        return [
            'id' => (int)$row['id'],
            'token' => $token,
            'public_url' => $token !== '' ? $this->publicUrl($token) : '',
            'reservation_type_id' => (int)$row['reservation_type_id'],
            'type_key' => (string)($row['type_key'] ?? ''),
            'type_label' => (string)($row['type_label'] ?? ''),
            'resolver_key' => (string)($row['resolver_key'] ?? ''),
            'delivery_mode' => (string)($row['delivery_mode'] ?? ''),
            'resource_id' => (int)$row['resource_id'],
            'experience_key' => $row['experience_key'] ?? null,
            'source_key' => $row['source_key'] ?? null,
            'params' => $this->decodeJson($row['params_json'] ?? null),
            'label' => $row['label'] ?? null,
            'og_title' => $row['og_title'] ?? null,
            'og_description' => $row['og_description'] ?? null,
            'og_image_url' => $row['og_image_url'] ?? null,
            'is_active' => (bool)($row['is_active'] ?? false),
            'is_expired' => !empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time(),
            'is_revoked' => !empty($row['revoked_at']),
            'revoked_at' => $row['revoked_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function typePayload(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'type_key' => (string)$row['type_key'],
            'label' => (string)$row['label'],
            'resolver_key' => (string)$row['resolver_key'],
            'delivery_mode' => (string)$row['delivery_mode'],
            'route_template' => $row['route_template'] ?? null,
            'parameter_schema' => $this->decodeJson($row['parameter_schema_json'] ?? null),
            'is_active' => (bool)$row['is_active'],
        ];
    }

    private function normalizeParams(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('params must be JSON object');
            }
            $value = $decoded;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('params must be an object');
        }
        return $value;
    }

    private function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Invalid JSON payload');
        }
        return $json;
    }

    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string)$value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function requiredString(mixed $value, string $message): string
    {
        return $this->optionalString($value) ?? throw new InvalidArgumentException($message);
    }

    private function optionalDateTime(mixed $value): ?string
    {
        $text = $this->optionalString($value);
        if ($text === null) {
            return null;
        }
        $time = strtotime($text);
        if ($time === false) {
            throw new InvalidArgumentException('Invalid expiration date');
        }
        return date('Y-m-d H:i:s', $time);
    }

    private function optionalSourceKey(mixed $value): ?string
    {
        $text = $this->optionalString($value);
        if ($text === null) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $text)) {
            throw new InvalidArgumentException('Invalid source_key');
        }
        return strtolower($text);
    }

    private function normalizeProjectExperienceKey(string $value): string
    {
        $key = strtolower(trim($value));
        if (!in_array($key, ['public', 'concept', 'client', 'painter'], true)) {
            throw new InvalidArgumentException('Invalid project experience');
        }
        return $key;
    }

    private function optionalTrustedUrl(mixed $value): ?string
    {
        $text = $this->optionalString($value);
        if ($text === null) {
            return null;
        }
        if (preg_match('#^https?://#i', $text) || str_starts_with($text, '/')) {
            return $text;
        }
        throw new InvalidArgumentException('URL must be absolute http(s) or root-relative');
    }

    private function firstString(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = $this->optionalString($value);
            if ($text !== null) {
                return $text;
            }
        }
        return '';
    }
}
