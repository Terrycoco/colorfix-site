<?php
declare(strict_types=1);

namespace App\entities;

/**
 * Color = FULL row from the `colors` table.
 * No transformations, no computed fields.
 */
final class Color
{
    private array $row;

    public function __construct(array $row) { $this->row = $row; }

    public function toArray(): array { return $this->row; }

    // --- Minimal getters used by Rules/ScoreCandidates/tests ---
    public function L(): float { return (float)($this->row['lab_l'] ?? 0.0); }
    public function a(): float { return (float)($this->row['lab_a'] ?? 0.0); }
    public function b(): float { return (float)($this->row['lab_b'] ?? 0.0); }

    // Optional helpers often referenced
    public function id(): int { return (int)($this->row['id'] ?? 0); }
    public function brand(): string { return (string)($this->row['brand'] ?? ''); }
    public function name(): string { return (string)($this->row['name'] ?? ''); }
    public function hex6(): ?string {
        $hx = $this->row['hex6'] ?? null;
        return is_string($hx) && $hx !== '' ? strtoupper($hx) : null;
    }
    public function clusterId(): ?int {
        return isset($this->row['cluster_id']) ? (int)$this->row['cluster_id'] : null;
    }
}
