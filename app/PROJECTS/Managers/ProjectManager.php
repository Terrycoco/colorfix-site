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
                    $playlistId
                );

            return $projectId;
        }

        return $this->projects
            ->create(
                $projectName,
                $clientId,
                $propertyId,
                $playlistId
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
