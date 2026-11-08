<?php
declare(strict_types=1);

namespace App\ARTWORK\Services;

use App\ARTWORK\Repos\PdoArtworkRepository;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use DomainException;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;

/** Artwork metadata rules; PHOTOS retains ownership of the original image. */
final class ArtworkService
{
    private PdoArtworkRepository $artwork;
    private PdoPhotoLibraryRepository $photos;

    public function __construct(PDO $pdo)
    {
        $this->artwork = new PdoArtworkRepository($pdo);
        $this->photos = new PdoPhotoLibraryRepository($pdo);
    }

    public function findById(int $artworkId): array
    {
        $row = $this->artwork->findById($artworkId);
        if (!$row) { throw new OutOfBoundsException('Artwork not found.'); }
        return $row;
    }

    public function findByPhotoId(int $photoLibraryId): ?array
    {
        $this->requirePhoto($photoLibraryId);
        return $this->artwork->findByPhotoId($photoLibraryId);
    }

    public function list(int $limit = 100, int $offset = 0): array
    {
        return $this->artwork->list($limit, $offset);
    }

    /** A photo may have at most one ARTWORK metadata record. */
    public function create(int $photoLibraryId, array $fields): array
    {
        $this->requirePhoto($photoLibraryId);
        if ($this->artwork->findByPhotoId($photoLibraryId) !== null) {
            throw new DomainException('Artwork already exists for this photograph. Edit the existing record.');
        }
        if (!$fields) { throw new InvalidArgumentException('At least one artwork field is required.'); }
        $id = $this->artwork->create($photoLibraryId, $fields);
        return $this->findById($id);
    }

    public function update(int $photoLibraryId, array $fields): array
    {
        $this->requirePhoto($photoLibraryId);
        if (!$fields) { throw new InvalidArgumentException('At least one artwork field is required.'); }
        if (!$this->artwork->updateByPhotoId($photoLibraryId, $fields)) {
            throw new OutOfBoundsException('Artwork not found for this photograph.');
        }
        return $this->artwork->findByPhotoId($photoLibraryId);
    }

    /** Deletes only the ARTWORK metadata; never the original photo or palette. */
    public function delete(int $photoLibraryId): void
    {
        $this->requirePhoto($photoLibraryId);
        if (!$this->artwork->deleteByPhotoId($photoLibraryId)) {
            throw new OutOfBoundsException('Artwork not found for this photograph.');
        }
    }

    private function requirePhoto(int $photoLibraryId): void
    {
        if ($photoLibraryId < 1) { throw new InvalidArgumentException('A valid photo_library_id is required.'); }
        if ($this->photos->findById($photoLibraryId) === null) {
            throw new OutOfBoundsException('Photograph not found in Photo Library.');
        }
    }
}
