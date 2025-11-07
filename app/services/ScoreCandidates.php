<?php
declare(strict_types=1);

namespace App\services;

use App\repos\ColorRepository;
use App\entities\Color;
use App\services\Rules;

final class ScoreCandidates
{
    public function __construct(private ColorRepository $repo) {}

    /**
     * Score a seed against candidate IDs.
     *
     * @param int   $seedId
     * @param int[] $candidateIds
     * @param string $metric  'de' = ΔE only, 'white' = enable near-white rule
     * @return array{
     *   metric:string,
     *   seed: array,
     *   results: array<int, array{
     *     id:int,
     *     sortKey: array{0:float,1:float},
     *     deltaE: float,
     *     nearWhite: ?float,
     *     color: array
     *   }>
     * }
     */
    public function run(int $seedId, array $candidateIds, string $metric = 'de'): array
    {
        $metric = strtolower($metric) === 'white' ? 'white' : 'de';
        $mode   = ($metric === 'de') ? 'delta' : 'auto';

        $seed = $this->repo->getById($seedId);
        if (!$seed) {
            throw new \InvalidArgumentException("Seed color not found: {$seedId}");
        }

        // Normalize candidate ids (dedupe, drop invalid, exclude seed)
        $ids = [];
        foreach ($candidateIds as $n) {
            $n = (int)$n;
            if ($n > 0 && $n !== $seedId) $ids[$n] = true;
        }
        $ids = array_keys($ids);
        if (!$ids) {
            return ['metric' => $metric, 'seed' => $seed->toArray(), 'results' => []];
        }

        // Load candidates
        $cands = [];
        foreach ($ids as $id) {
            $c = $this->repo->getById($id);
            if ($c) $cands[] = $c;
        }

        // Score each
        $rows = [];
        foreach ($cands as $c) {
            /** @var Color $c */
            $key = Rules::compositeKey($seed, $c, $mode);

            $dE        = (float)$key[0];
            $nearWhite = $key[1] ?? null; // present only when seed is near-white & mode!='delta'

            // If we're in 'white' mode AND we have a near-white score (seed qualified),
            // sort by near-white FIRST, then ΔE. Otherwise, ΔE first.
            if ($metric === 'white' && $nearWhite !== null) {
                $sortKey = [$nearWhite, $dE];
            } else {
                $sortKey = [$dE, $nearWhite ?? 0.0];
            }

            $rows[] = [
                'id'        => $c->id(),
                'sortKey'   => $sortKey,
                'deltaE'    => round($dE, 4),
                'nearWhite' => $nearWhite !== null ? round((float)$nearWhite, 6) : null,
                'color'     => $c->toArray(),
            ];
        }

        usort($rows, fn($a, $b) =>
            ($a['sortKey'][0] <=> $b['sortKey'][0]) ?: ($a['sortKey'][1] <=> $b['sortKey'][1])
        );

        return [
            'metric'  => $metric,
            'seed'    => $seed->toArray(),
            'results' => $rows,
        ];
    }
}
