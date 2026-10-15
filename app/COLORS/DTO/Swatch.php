<?php

declare(strict_types=1);

namespace App\COLORS\DTO;

/**
 * Lightweight, render-ready payload for PaletteSwatch.jsx.
 * No detail-only properties; HCL values are rounded for display.
 */
final class Swatch
{
    /** @param array<string, int|float|string> $fields */
    private function __construct(private array $fields) {}

    /** @param array<string, mixed> $row Full precision source record. */
    public static function fromRow(array $row): self
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A SWATCH requires a positive id.');
        }

        $hex = (string) ($row['hex'] ?? $row['hex6'] ?? '');
        $hex = ltrim(trim($hex), '#');

        return new self([
            'id'         => $id,
            'name'       => (string) ($row['name'] ?? ''),
            'brand'      => (string) ($row['brand'] ?? ''),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'hex'        => $hex,
            'r'          => (int) ($row['r'] ?? 0),
            'g'          => (int) ($row['g'] ?? 0),
            'b'          => (int) ($row['b'] ?? 0),
            'hcl_h'      => (int) round((float) ($row['hcl_h'] ?? 0)),
            'hcl_c'      => (int) round((float) ($row['hcl_c'] ?? 0)),
            'hcl_l'      => (int) round((float) ($row['hcl_l'] ?? 0)),
            'hue_cats'   => (string) ($row['hue_cats'] ?? ''),
            'chip_num'   => (string) ($row['chip_num'] ?? ''),
            'is_stain'   => !empty($row['is_stain']) ? 1 : 0,
        ]);
    }

    /** @return array<string, int|float|string> */
    public function toArray(): array
    {
        return $this->fields;
    }
}
