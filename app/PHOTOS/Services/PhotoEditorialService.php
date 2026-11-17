<?php
declare(strict_types=1);

namespace App\PHOTOS\Services;

use App\PHOTOS\Repos\PdoPhotoEditorialRepository;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use InvalidArgumentException;
use PDO;

/** Admin-only photo-level editorial data. No SEO generation or AI processing here. */
final class PhotoEditorialService
{
    private PdoPhotoLibraryRepository $photos;
    private PdoPhotoEditorialRepository $editorial;

    public function __construct(PDO $pdo)
    {
        $this->photos = new PdoPhotoLibraryRepository($pdo);
        $this->editorial = new PdoPhotoEditorialRepository($pdo);
    }

    public function get(int $photoId): array
    {
        $this->assertPhoto($photoId);
        return $this->editorial->find($photoId) ?? [
            'photo_library_id' => $photoId,
            'design_notes' => null,
            'refined_commentary' => null,
            'photo_description' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /** Read-only bulk interface for internal analyzers and article assembly. */
    public function getMany(array $photoIds): array
    {
        return $this->editorial->findMany($photoIds);
    }

    /** All three keys must be supplied: an omitted key must not erase saved writing. */
    public function save(int $photoId, array $fields): array
    {
        $this->assertPhoto($photoId);
        foreach (['design_notes', 'refined_commentary', 'photo_description'] as $key) {
            if (!array_key_exists($key, $fields) || !is_null($fields[$key]) && !is_string($fields[$key])) {
                throw new InvalidArgumentException($key . ' must be text or null.');
            }
        }
        $this->editorial->save($photoId, $fields['design_notes'], $fields['refined_commentary'], $fields['photo_description']);
        return $this->get($photoId);
    }

    private function assertPhoto(int $photoId): void
    {
        if ($photoId <= 0 || $this->photos->findById($photoId) === null) {
            throw new InvalidArgumentException('Photo not found.');
        }
    }
}
