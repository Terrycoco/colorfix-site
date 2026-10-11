<?php
declare(strict_types=1);

namespace App\SAS\Services;

use App\SAS\Repos\PdoPhotoSearchRepository;
use PDO;

/**
 * Stable service boundary for SAS photo searches.
 * Future materialized-index searching should replace the repository,
 * not the endpoint or the frontend response contract.
 */
final class PhotoSearchService
{
    public function __construct(private PdoPhotoSearchRepository $photos) {}

    public static function fromPdo(PDO $pdo): self
    {
        return new self(new PdoPhotoSearchRepository($pdo));
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int} */
    public function search(string $query, int $page = 1, int $perPage = 30): array
    {
        return $this->photos->search($query, $page, $perPage);
    }
}
