<?php
declare(strict_types=1);

namespace App\PALETTES\Managers;

use App\PALETTES\Repos\PdoPVRepository;
use App\REX\Repos\PdoRexReservationRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PVManager
{
    private PdoPVRepository $pvs;

    public function __construct(
        private PDO $pdo
    ) {
        $this->pvs =
            new PdoPVRepository(
                $this->pdo
            );
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
