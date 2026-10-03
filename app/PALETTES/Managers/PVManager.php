<?php
declare(strict_types=1);

namespace App\PALETTES\Managers;

use App\PALETTES\Repos\PdoPVRepository;
use App\PALETTES\Repos\PdoSavedPaletteRepository;
use App\PROJECTS\Repos\PdoProjectPaletteRepository;
use App\REX\Repos\PdoRexReservationRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PVManager
{
    private PdoPVRepository $pvs;
    private PdoSavedPaletteRepository $savedPalettes;
    private PdoProjectPaletteRepository $projectPalettes;

    public function __construct(
        private PDO $pdo
    ) {
        $this->pvs = new PdoPVRepository($this->pdo);
        $this->savedPalettes = new PdoSavedPaletteRepository($this->pdo);
        $this->projectPalettes = new PdoProjectPaletteRepository($this->pdo);
    }

    /** @param int[] $projectPaletteIds */
    public function createPV(
        int $savedPaletteId,
        string $experience,
        string $title,
        int $projectId = 0,
        array $projectPaletteIds = []
    ): array {
        $experience = strtolower(trim($experience));
        $allowed = ['public', 'concept', 'client', 'showcase', 'painter'];

        if (!in_array($experience, $allowed, true)) {
            throw new InvalidArgumentException(
                'experience must be Public, Concept, Client, Showcase, or Painter'
            );
        }

        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('title required');
        }

        if ($experience === 'painter') {
            if ($projectId <= 0) {
                throw new InvalidArgumentException('project_id required for Painter');
            }

            $requestedIds = array_values(array_unique(array_filter(
                array_map('intval', $projectPaletteIds),
                static fn(int $id): bool => $id > 0
            )));

            if ($requestedIds === []) {
                throw new InvalidArgumentException(
                    'Select at least one project palette for Painter'
                );
            }

            $allowedRows = $this->projectPalettes->listForProject($projectId);
            $allowedIds = array_map(
                static fn(array $row): int => (int)($row['project_palette_id'] ?? 0),
                $allowedRows
            );

            foreach ($requestedIds as $id) {
                if (!in_array($id, $allowedIds, true)) {
                    throw new InvalidArgumentException(
                        "Project Palette {$id} does not belong to Project {$projectId}"
                    );
                }
            }

            $pvId = $this->pvs->createPainter(
                projectId: $projectId,
                projectPaletteIds: $requestedIds,
                title: $title,
            );
        } else {
            if ($savedPaletteId <= 0) {
                throw new InvalidArgumentException('saved_palette_id required');
            }

            if (!$this->savedPalettes->paletteExists($savedPaletteId)) {
                throw new RuntimeException(
                    "Saved Palette {$savedPaletteId} not found"
                );
            }

            $pvId = $this->pvs->create(
                savedPaletteId: $savedPaletteId,
                format: $experience,
                title: $title,
            );
        }

        $row = $this->pvs->findGridRowById($pvId);
        if ($row === null) {
            throw new RuntimeException(
                "Palette Viewer {$pvId} was created but could not be reloaded."
            );
        }

        return $this->gridPayload($row);
    }

    /** @param int[] $savedPaletteIds */
    public function listPVsForProject(
        int $projectId,
        array $savedPaletteIds
    ): array {
        return array_map(
            fn(array $row): array => $this->gridPayload($row),
            $this->pvs->listGridRowsForProject($projectId, $savedPaletteIds)
        );
    }

    private function gridPayload(array $row): array
    {
        $title = trim((string)($row['title'] ?? '')) ?: 'Untitled';
        $experience = strtolower(trim((string)($row['format'] ?? '')));
        $savedPaletteId = isset($row['saved_palette_id']) && $row['saved_palette_id'] !== null
            ? (int)$row['saved_palette_id']
            : null;

        return [
            'palette_viewer_id' => (int)($row['palette_viewer_id'] ?? 0),
            'saved_palette_id' => $savedPaletteId,
            'project_id' => isset($row['project_id']) && $row['project_id'] !== null
                ? (int)$row['project_id']
                : null,
            'format' => $experience,
            'experience_key' => $experience,
            'title' => $title,
            'handle' => $title . ' - ' . ($experience !== '' ? ucfirst($experience) : 'Unknown'),
            'palette_name' => trim((string)($row['palette_name'] ?? '')),
            'kicker_text' => $row['kicker_text'] ?? null,
            'intro' => $row['intro'] ?? null,
            'photo_count' => (int)($row['photo_count'] ?? 0),
            'is_active' => (int)($row['is_active'] ?? 0),
        ];
    }


    /**
     * Permanently delete one PV.
     *
     * Reservations follow object lifetime; links follow relationships.
     *
     * Preserved:
     * - Saved Palette
     * - Photo Library photos
     *
     * Removed:
     * - every REX reservation for this PV
     * - every REX link involving those reservations
     * - palette_viewer_photos rows
     * - palette_viewers row
     */
    public function deletePV(
        int $pvId
    ): bool {
        if ($pvId <= 0) {
            throw new InvalidArgumentException(
                'palette_viewer_id required'
            );
        }

        $existing =
            $this->pvs
                ->findById(
                    $pvId
                );

        if ($existing === null) {
            return false;
        }

        $ownsTransaction =
            !$this->pdo
                ->inTransaction();

        if ($ownsTransaction) {
            $this->pdo
                ->beginTransaction();
        }

        try {
            $rex =
                new PdoRexReservationRepository(
                    $this->pdo
                );

            /*
             * REX is the deletion gate.
             *
             * A PV may own more than one REX reservation over its lifetime.
             * Every specific reservation must pass deleteREX() before any
             * PV-owned rows are touched.
             *
             * If any reservation is locked, deleteREX() refuses it and this
             * outer transaction rolls back any earlier REX deletions.
             */
            $reservations =
                $rex->findByResource(
                    'palette_viewer',
                    $pvId,
                    500
                );

            foreach ($reservations as $reservation) {
                $rexDelete =
                    $rex->deleteREX(
                        (int)$reservation->id
                    );

                if (
                    ($rexDelete['ok'] ?? false)
                    !== true
                ) {
                    throw new RuntimeException(
                        (string)(
                            $rexDelete['message']
                            ?? "Palette Viewer {$pvId} cannot be deleted because its REX identity is protected."
                        )
                    );
                }
            }

            /*
             * The PV owns these presentation relationships.
             * Photo Library source images are preserved.
             */
            $this->pvs
                ->deletePhotosByPVId(
                    $pvId
                );

            $deleted =
                $this->pvs
                    ->deleteById(
                        $pvId
                    );

            if ($deleted !== 1) {
                throw new RuntimeException(
                    "Palette Viewer {$pvId} was not deleted."
                );
            }

            if (
                $ownsTransaction
                &&
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->commit();
            }

            return true;

        } catch (Throwable $e) {
            if (
                $ownsTransaction
                &&
                $this->pdo
                    ->inTransaction()
            ) {
                $this->pdo
                    ->rollBack();
            }

            throw $e;
        }
    }
}
