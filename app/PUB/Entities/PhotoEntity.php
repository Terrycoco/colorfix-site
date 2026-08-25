<?php
declare(strict_types=1);

namespace App\PUB\Entities;

use InvalidArgumentException;

final class PhotoEntity
{
    public function __construct(
        public readonly int $photoLibraryId,
        public readonly string $imageUrl,
        public readonly string $filePath,
        public readonly ?string $title = null,
    ) {
        if ($this->photoLibraryId <= 0) {
            throw new InvalidArgumentException(
                'PhotoEntity requires a valid photoLibraryId.'
            );
        }

        if (trim($this->imageUrl) === '') {
            throw new InvalidArgumentException(
                'PhotoEntity requires imageUrl.'
            );
        }

        if (trim($this->filePath) === '') {
            throw new InvalidArgumentException(
                'PhotoEntity requires filePath.'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'photo_library_id' => $this->photoLibraryId,
            'image_url' => $this->imageUrl,
            'file_path' => $this->filePath,
            'title' => $this->title,
        ];
    }
}