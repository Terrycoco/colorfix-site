<?php
declare(strict_types=1);

namespace App\PV;

use App\PV\Repos\PdoPVRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PVService
{
    private PdoPVRepository $repo;

    public function __construct(PDO $pdo)
    {
        $this->repo = new PdoPVRepository($pdo);
    }

    public function getPV(int $pvId): array
    {
        if ($pvId <= 0) {
            throw new InvalidArgumentException(
                'palette viewer id required'
            );
        }

        $pv = $this->repo->findById($pvId);

        if (!$pv) {
            throw new RuntimeException(
                "Palette Viewer {$pvId} not found"
            );
        }

        if (!$pv->isActive) {
            throw new RuntimeException(
                "Palette Viewer {$pvId} is inactive"
            );
        }

        return $pv->toArray();
    }


public function getLinkedPVs(int $playlistId): array
{
    if ($playlistId <= 0) {
        throw new InvalidArgumentException(
            'playlist id required'
        );
    }

    $links = $this->repo->findLinkedByPlaylistId($playlistId);

    $linkedPVs = [];

    foreach ($links as $link) {
        $pvId = (int)$link['pv_id'];

        // Important: one canonical definition of a complete PV.
        $pv = $this->getPV($pvId);

        // Relationship data belongs to getLinkedPVs(), not getPV().
        $pv['rex_url'] = $link['rex_url'];
        $pv['rex_reservation_id'] = $link['rex_reservation_id'];
        $pv['rex_sort_order'] = $link['sort_order'];

        $linkedPVs[] = $pv;
    }

    return $linkedPVs;
}

public function getLinkedPubData(int $playlistId): array
{
    if ($playlistId <= 0) {
        throw new InvalidArgumentException(
            'playlist id required'
        );
    }

    return $this->repo->findLinkedPubData($playlistId);
}



}