<?php
declare(strict_types=1);

namespace App\entities;

/**
 * Cluster = first-class representation of a rounded HCL bucket.
 * Mirrors the `clusters` table: (id, h_r, c_r, l_r).
 * Keep it tiny and immutable; add derived methods later if needed.
 */
final class Cluster
{
    public function __construct(
        private int $id,
        private int $h_r,
        private int $c_r,
        private int $l_r
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['id']   ?? 0),
            (int)($row['h_r']  ?? 0),
            (int)($row['c_r']  ?? 0),
            (int)($row['l_r']  ?? 0),
        );
    }

    public function id(): int  { return $this->id; }
    public function h(): int   { return $this->h_r; }
    public function c(): int   { return $this->c_r; }
    public function l(): int   { return $this->l_r; }

    /** Minimal array form for logs/JSON */
    public function toArray(): array
    {
        return [
            'id'  => $this->id,
            'h_r' => $this->h_r,
            'c_r' => $this->c_r,
            'l_r' => $this->l_r,
        ];
    }
}
