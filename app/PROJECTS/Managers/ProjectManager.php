<?php
declare(strict_types=1);

namespace App\PROJECTS\Managers;

use App\PROJECTS\Repos\PdoProjectRepository;
use PDO;
use RuntimeException;

final class ProjectManager
{
    private PdoProjectRepository $projects;

    public function __construct(
        private PDO $pdo
    ) {
        $this->projects =
            new PdoProjectRepository(
                $this->pdo
            );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAdminProjects(): array
    {
        return $this->projects
            ->listAdminRows();
    }

    /** @return array<int, array<string, mixed>> */
    public function listProjectsByPropertyId(int $propertyId): array
    {
        return $this->projects->listByPropertyId($propertyId);
    }

    public function countProjectsByPropertyId(int $propertyId): int
    {
        return $this->projects->countByPropertyId($propertyId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProject(
        int $projectId
    ): ?array {
        return $this->projects
            ->findById(
                $projectId
            );
    }

    public function saveProject(
        array $payload
    ): int {
        $projectId =
            isset($payload['project_id'])
                ? (int)$payload['project_id']
                : 0;

        $projectName =
            self::nullableString(
                $payload['project_name']
                ?? null
            );

        $clientId =
            self::nullableId(
                $payload['client_id']
                ?? null
            );

        $propertyId =
            self::nullableId(
                $payload['property_id']
                ?? null
            );

        $playlistId =
            self::nullableId(
                $payload['playlist_id']
                ?? null
            );

        $rooms = array_key_exists('rooms', $payload)
            ? self::validateRooms($payload['rooms'])
            : null;

        if (
            $playlistId !== null
            &&
            $this->projects
                ->playlistInUseByAnotherProject(
                    $playlistId,
                    $projectId > 0
                        ? $projectId
                        : null
                )
        ) {
            throw new RuntimeException(
                "Playlist {$playlistId} is already attached to another Project."
            );
        }

        if ($projectId > 0) {
            $existing =
                $this->projects
                    ->findById(
                        $projectId
                    );

            if ($existing === null) {
                throw new RuntimeException(
                    "Project {$projectId} was not found."
                );
            }

            $this->projects
                ->update(
                    $projectId,
                    $projectName,
                    $clientId,
                    $propertyId,
                    $playlistId,
                    $rooms
                );

            return $projectId;
        }

        return $this->projects
            ->create(
                $projectName,
                $clientId,
                $propertyId,
                $playlistId,
                $rooms
            );
    }

    public function deleteProject(
        int $projectId
    ): bool {
        if ($projectId <= 0) {
            return false;
        }

        return $this->projects
            ->deleteById(
                $projectId
            ) === 1;
    }

    private static function validateRooms(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Rooms must be an array.');
        }

        $rooms = [];
        $ids = [];
        $names = [];
        foreach ($value as $room) {
            if (!is_array($room) || !is_string($room['id'] ?? null) || !is_string($room['name'] ?? null)) {
                throw new RuntimeException('Each area requires an ID and name.');
            }
            $id = trim($room['id']);
            $name = trim($room['name']);
            if ($id === '' || $id === '__all__' || $name === '') {
                throw new RuntimeException('Each area requires a valid ID and name.');
            }
            $nameKey = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            if (isset($ids[$id]) || isset($names[$nameKey])) {
                throw new RuntimeException('Each area must have a unique ID and name.');
            }
            $ids[$id] = true;
            $names[$nameKey] = true;
            $rooms[] = ['id' => $id, 'name' => $name];
        }
        return $rooms;
    }

    private static function nullableId(
        mixed $value
    ): ?int {
        if (
            $value === null
            ||
            $value === ''
            ||
            $value === false
        ) {
            return null;
        }

        $id =
            (int)$value;

        return $id > 0
            ? $id
            : null;
    }

    private static function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $text =
            trim(
                (string)$value
            );

        return $text !== ''
            ? $text
            : null;
    }
}
