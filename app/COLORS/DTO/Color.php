<?php

declare(strict_types=1);

namespace App\COLORS\DTO;

/**
 * Complete color data contract.
 *
 * Intentionally lossless: the repository determines the available full-detail
 * fields, and COLOR does not round measurements or strip metadata.
 */
final class Color
{
    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        if (!isset($row['id']) || (int) $row['id'] <= 0) {
            throw new \InvalidArgumentException('A COLOR requires a positive id.');
        }

        return new self($row);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->fields;
    }
}
