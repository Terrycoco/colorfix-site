<?php
declare(strict_types=1);

namespace App\COLORS\Services;

use App\COLORS\Repos\PdoHexSearchRepository;
use App\Lib\ColorCompute;
use App\Lib\ColorDelta;
use InvalidArgumentException;

final class HexSearchService
{
    public function __construct(private PdoHexSearchRepository $repo) {}

    public static function normalize(string $input): string
    {
        $input = trim($input);
        if (!preg_match('/^#?([0-9a-f]{6})$/iD', $input, $matches)) {
            throw new InvalidArgumentException('Enter a six-digit hex color, with or without #.');
        }
        return strtoupper($matches[1]);
    }

    /** All exact paint matches, followed by the nearest 60 swatches. Never collapse brands or twins. */
    public function search(string $input): array
    {
        $hex = self::normalize($input);
        $computed = ColorCompute::computeAll(null, null, null, $hex);
        $lab = ['L' => $computed['lab_l'], 'a' => $computed['lab_a'], 'b' => $computed['lab_b']];
        $exact = $this->repo->exact($hex);
        $near = $this->repo->candidates($hex, $lab);
        foreach ($near as &$row) {
            $row['distance'] = ColorDelta::deltaE2000($lab['L'], $lab['a'], $lab['b'], (float)$row['lab_l'], (float)$row['lab_a'], (float)$row['lab_b']);
        }
        unset($row);
        usort($near, static fn(array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: ((int)$a['id'] <=> (int)$b['id']));
        $near = array_slice($near, 0, 60);
        $ranked = [];
        foreach ($exact as $id) $ranked[] = ['id' => (int)$id, 'hex_match_type' => 'exact', 'match_delta_e' => 0.0];
        foreach ($near as $row) $ranked[] = ['id' => (int)$row['id'], 'hex_match_type' => 'close', 'match_delta_e' => $row['distance']];
        $swatches = $this->repo->swatches(array_column($ranked, 'id'));
        $results = [];
        foreach ($ranked as $match) {
            if (isset($swatches[$match['id']])) {
                $results[] = array_replace($swatches[$match['id']], $match, ['item_type' => 'swatch']);
            }
        }
        return [
            'hex' => $hex,
            'reference_color' => ['item_type' => 'reference-swatch'] + array_intersect_key($computed, array_flip([
                'hex6', 'r', 'g', 'b', 'hcl_h', 'hcl_c', 'hcl_l',
            ])),
            'results' => $results,
        ];
    }
}
