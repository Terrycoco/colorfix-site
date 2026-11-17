<?php
declare(strict_types=1);

namespace App\PALETTES\Services;

use App\COLORS\DTO\Swatch;
use App\PALETTES\Repos\PdoSavedPaletteRepository;
use PDO;

/** Read-only, display-only saved-palette swatch assembly. */
final class PaletteService
{
    public function __construct(private PDO $pdo) {}

    /**
     * One membership/MV JOIN, then in-memory COLORS DTO construction.
     * No role, note, or other palette-member metadata in this display contract.
     * Missing/inactive display swatches are omitted.
     *
     * @return array<int,array<string,int|float|string>>
     */
    public function getSwatches(int $paletteId): array
    {
        $rows = (new PdoSavedPaletteRepository($this->pdo))
            ->getDisplaySwatchRows($paletteId);

        return array_map(
            static fn(array $row): array => Swatch::fromRow($row)->toArray(),
            $rows
        );
    }
}
