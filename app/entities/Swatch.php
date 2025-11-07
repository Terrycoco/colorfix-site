<?php
declare(strict_types=1);

namespace App\entities;

/**
 * Swatch = hydrated UI-facing data (from swatch_view or swatch_enriched).
 * Keeps the original row so the UI shape stays identical today.
 */
final class Swatch
{
    /** @var array<string,mixed> */
    private array $row;

    /**
     * @param array<string,mixed> $row Raw DB row
     */
    public function __construct(array $row)
    {
        $this->row = $row;
    }

    // Typed conveniences (only common fields)
    public function id(): int         { return (int)($this->row['id'] ?? 0); }
    public function name(): string    { return (string)($this->row['name'] ?? ''); }
    public function brand(): string   { return (string)($this->row['brand'] ?? ''); }
    public function code(): string    { return (string)($this->row['code'] ?? ''); }
    public function hex(): string     { return strtoupper((string)($this->row['hex'] ?? ($this->row['hex6'] ?? ''))); }

    /** NEW: expose cluster id everywhere swatches are used */
    public function clusterId(): ?int
    {
        if (isset($this->row['cluster_id'])) {
            $cid = (int)$this->row['cluster_id'];
            return $cid > 0 ? $cid : null;
        }
        return null;
    }

    // Optional LAB accessors if present in the row
    public function L(): ?float { return isset($this->row['lab_l']) ? (float)$this->row['lab_l'] : null; }
    public function a(): ?float { return isset($this->row['lab_a']) ? (float)$this->row['lab_a'] : null; }
    public function b(): ?float { return isset($this->row['lab_b']) ? (float)$this->row['lab_b'] : null; }

    /** Return exactly what the UI expects today (original row shape). */
    public function toArray(): array
    {
        return $this->row;
    }
}
