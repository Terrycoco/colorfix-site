<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPaletteRepository;
use PDO;

final class PaletteTierAService
{
    public function __construct(
        private PDO $pdo,
        private PdoPaletteRepository $palRepo
    ) {}

    /**
     * Wrap your existing generator (no logic changes).
     * Uses api/functions/generateTierAPalettes.php
     */
    public function generateForPivot(int $pivotClusterId, int $maxK = 6, int $timeBudgetMs = 4000): array
    {
        $fn = dirname(__DIR__, 2) . '/api/functions/generateTierAPalettes.php';
        if (is_file($fn)) require_once $fn;

        if (!function_exists('generateTierAPalettes')) {
            return ['ok'=>false,'error'=>'generateTierAPalettes() not found'];
        }
        return generateTierAPalettes($this->pdo, $pivotClusterId, $maxK, $timeBudgetMs);
    }

    /**
     * Query palettes that include the pivot cluster, with members.
     * (We keep it cluster-level; expansion to swatches can be layered later.)
     */
    public function fetchByPivot(int $pivotClusterId, string $tier = 'A'): array
    {
        $rows = $this->palRepo->getPalettesByPivot($pivotClusterId, $tier, 'active');
        $out  = [];
        foreach ($rows as $p) {
            $pid = (int)$p['id'];
            $out[] = [
                'id'      => $pid,
                'size'    => (int)($p['size'] ?? 0),
                'tier'    => (string)($p['tier'] ?? 'A'),
                'status'  => (string)($p['status'] ?? ''),
                'hash'    => (string)($p['palette_hash'] ?? ''),
                'members' => $this->palRepo->getPaletteMembers($pid), // cluster ids in order
            ];
        }
        return $out;
    }
}
