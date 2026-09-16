<?php
declare(strict_types=1);

namespace App\PALETTES\Managers;

use App\PALETTES\Repos\PdoSavedPaletteRepository;
use PDO;
use RuntimeException;
use Throwable;

final class SavedPaletteManager
{
    private PdoSavedPaletteRepository $repo;

    public function __construct(
        private PDO $pdo
    ) {
        $this->repo = new PdoSavedPaletteRepository($pdo);
    }

    public function get(int $savedPaletteId): ?array
    {
        if ($savedPaletteId <= 0) {
            return null;
        }

        return $this->repo->getFullPalette($savedPaletteId);
    }

    public function list(
        array $filters = [],
        int $limit = 50,
        int $offset = 0
    ): array {
        return $this->repo->listPalettes(
            $filters,
            $limit,
            $offset
        );
    }

    public function create(
        array $paletteData,
        array $members = []
    ): array {
        $this->pdo->beginTransaction();

        try {
            $savedPaletteId = $this->repo->createSavedPalette(
                $paletteData
            );

            if ($members !== []) {
                $this->repo->addMembers(
                    $savedPaletteId,
                    $members
                );
            }

            $this->pdo->commit();

            $palette = $this->repo->getFullPalette(
                $savedPaletteId
            );

            if ($palette === null) {
                throw new RuntimeException(
                    "Saved Palette {$savedPaletteId} was created but could not be reloaded."
                );
            }

            return $palette;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function update(
        int $savedPaletteId,
        array $paletteData,
        ?array $members = null
    ): array {
        if (
            $savedPaletteId <= 0
            || !$this->repo->paletteExists($savedPaletteId)
        ) {
            throw new RuntimeException(
                "Saved Palette {$savedPaletteId} not found."
            );
        }

        $this->pdo->beginTransaction();

        try {
            if ($paletteData !== []) {
                $this->repo->updateSavedPalette(
                    $savedPaletteId,
                    $paletteData
                );
            }

            if ($members !== null) {
                $this->repo->replaceMembers(
                    $savedPaletteId,
                    $members
                );
            }

            $this->pdo->commit();

            $palette = $this->repo->getFullPalette(
                $savedPaletteId
            );

            if ($palette === null) {
                throw new RuntimeException(
                    "Saved Palette {$savedPaletteId} could not be reloaded after update."
                );
            }

            return $palette;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function setFavorite(
        int $savedPaletteId,
        bool $favorite
    ): array {
        if (
            $savedPaletteId <= 0
            || !$this->repo->paletteExists($savedPaletteId)
        ) {
            throw new RuntimeException(
                "Saved Palette {$savedPaletteId} not found."
            );
        }

        $this->repo->setFavorite(
            $savedPaletteId,
            $favorite
        );

        $palette = $this->repo->getFullPalette(
            $savedPaletteId
        );

        if ($palette === null) {
            throw new RuntimeException(
                "Saved Palette {$savedPaletteId} could not be reloaded."
            );
        }

        return $palette;
    }
}