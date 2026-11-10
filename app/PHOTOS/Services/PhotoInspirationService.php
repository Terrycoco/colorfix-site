<?php
declare(strict_types=1);

namespace App\PHOTOS\Services;

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

/** One PHOTOS-owned writer for artwork inspiration relationships. */
final class PhotoInspirationService
{
    private PdoPhotoLibraryRepository $photos;

    public function __construct(private PDO $pdo)
    {
        $this->photos = new PdoPhotoLibraryRepository($pdo);
    }

    /**
     * Assigns an existing design photograph to an existing art photograph.
     * Reassignment replaces its previous inspiration; palette_id is unchanged.
     * Joins an existing caller transaction if one is active.
     */
    public function setInspiration(int $photoId, int $artworkPhotoId): array
    {
        if ($photoId <= 0 || $artworkPhotoId <= 0 || $photoId === $artworkPhotoId) {
            throw new InvalidArgumentException('Select two different, valid photographs.');
        }
        return $this->changeInspiration($photoId, $artworkPhotoId);
    }

    public function removeInspiration(int $photoId): array
    {
        if ($photoId <= 0) {
            throw new InvalidArgumentException('Invalid photograph ID.');
        }
        return $this->changeInspiration($photoId, null);
    }

    /** Admin-level listing; NOT directly suitable for public display without visibility checks. */
    public function getInspiredDesigns(int $artworkPhotoId): array
    {
        if ($artworkPhotoId <= 0) {
            throw new InvalidArgumentException('Invalid artwork photograph ID.');
        }
        $artwork = $this->photos->findById($artworkPhotoId);
        if (!$artwork || $artwork['source_type'] !== 'art') {
            throw new InvalidArgumentException('Artwork photograph not found.');
        }
        return $this->photos->listInspiredDesigns($artworkPhotoId);
    }

    /** Search existing non-art Photo Library records for admin assignment. */
    public function searchDesigns(string $query, int $limit = 40): array
    {
        return $this->photos->searchInspirationDesigns($query, $limit);
    }

    private function changeInspiration(int $photoId, ?int $artworkPhotoId): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $photo = $this->photos->findForInspirationUpdate($photoId);
            if (!$photo) {
                throw new InvalidArgumentException('Design photograph not found.');
            }
            if ($photo['source_type'] === 'art') {
                throw new InvalidArgumentException('An artwork cannot be linked as an inspired design.');
            }
            if ($artworkPhotoId !== null) {
                $artwork = $this->photos->findById($artworkPhotoId);
                if (!$artwork || $artwork['source_type'] !== 'art') {
                    throw new InvalidArgumentException('Select an existing artwork photograph.');
                }
            }
            $this->photos->setInspirationPhotoId($photoId, $artworkPhotoId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return [
                'photo_library_id' => $photoId,
                'inspiration_photo_id' => $artworkPhotoId,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
