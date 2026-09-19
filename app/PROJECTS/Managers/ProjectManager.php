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
        private PDO $pdo a
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
            isset(
                $payload[
                    'project_id'
                ]
            )
                ? (int)$payload[
                    'project_id'
                ]
                : 0;

        $clientId =
            (int)(
                $payload[
                    'client_id'
                ]
                ?? 0
            );

        $propertyId =
            (int)(
                $payload[
                    'property_id'
                ]
                ?? 0
            );

        $playlistId =
            (int)(
                $payload[
                    'playlist_id'
                ]
                ?? 0
            );

        if (
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
                    $clientId,
                    $propertyId,
                    $playlistId
                );

            return $projectId;
        }

        return $this->projects
            ->create(
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
}
