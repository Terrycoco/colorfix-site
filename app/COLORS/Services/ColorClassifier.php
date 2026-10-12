<?php
declare(strict_types=1);

namespace App\COLORS\Services;

use InvalidArgumentException;

/**
 * Pure HCL classifier: matches the legacy CategoriesService boundary semantics.
 * Returns ALL category IDs (including calc_only and overlapping categories).
 * No cached columns, no database access, no display-name canonicalization.
 */
final class ColorClassifier
{
    private const EPS = 1e-9;

    /**
     * @param array<string,list<array<string,mixed>>> $definitions
     * @return list<int>
     */
    public function classify(float $h, float $c, float $l, array $definitions): array
    {
        if (!is_finite($h) || !is_finite($c) || !is_finite($l)) {
            throw new InvalidArgumentException('HCL values must be finite.');
        }
        $ids = [];
        foreach ($definitions as $type => $defs) {
            foreach ($defs as $def) {
                if ($this->inHueRange($h, $def['hue_min'], $def['hue_max'])
                    && $this->inRange($c, $def['chroma_min'], $def['chroma_max'])
                    && $this->inRange($l, $def['light_min'], $def['light_max'])) {
                    $ids[] = (int) $def['id'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function inRange(float $value, mixed $min, mixed $max): bool
    {
        if ($min === null && $max === null) return true;
        if ($min !== null && $value < (float)$min - self::EPS) return false;
        if ($max !== null && $value >= (float)$max - self::EPS) return false;
        return true;
    }

    private function normalize(float $value): float
    {
        $result = fmod($value, 360.0);
        return $result < 0 ? $result + 360.0 : $result;
    }

    private function inHueRange(float $h, mixed $min, mixed $max): bool
    {
        if ($min === null && $max === null) return true;
        $H = $this->normalize($h);
        if ($min !== null && $max === null) {
            $A = $this->normalize((float)$min);
            return $H >= $A || $A == 0.0;
        }
        if ($min === null && $max !== null) {
            $B = $this->normalize((float)$max);
            return $H <= $B || $B == 359.999;
        }
        $A = $this->normalize((float)$min);
        $B = $this->normalize((float)$max);
        return $A <= $B ? ($H >= $A && $H <= $B) : ($H >= $A || $H <= $B);
    }
}
