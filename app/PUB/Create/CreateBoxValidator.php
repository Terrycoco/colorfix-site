<?php
declare(strict_types=1);

namespace App\PUB\Create;

use RuntimeException;

/**
 * CREATE BOX VALIDATOR
 *
 * Performs the creator-side sanity check on a sealed
 * Analyze box before a creator begins its recipe.
 *
 * Each Creator owns its own REQUIRED_INGREDIENTS list.
 * This class only checks that the supplied box satisfies it.
 *
 * It does NOT:
 *   - define ingredient contracts
 *   - analyze source material
 *   - fetch missing ingredients
 *   - call REX
 *   - query playlists
 *   - repair or supplement a box
 *
 * A failed check stops CREATE immediately.
 */
final class CreateBoxValidator
{
    /**
     * Verify that all ingredients required by a Creator
     * exist and contain usable values.
     */
    public static function assertRequired(
        array $required,
        array $box,
        string $creatorLabel
    ): void {
        foreach ($required as $ingredient) {
            $ingredient = trim((string)$ingredient);

            if ($ingredient === '') {
                continue;
            }

            if (!array_key_exists($ingredient, $box)) {
                throw new RuntimeException(
                    "{$creatorLabel} Creator cannot start: {$ingredient} is missing."
                );
            }

            if (self::isEmpty($box[$ingredient])) {
                throw new RuntimeException(
                    "{$creatorLabel} Creator cannot start: {$ingredient} is empty."
                );
            }
        }
    }

    /**
     * Verify that a specific ingredient is a non-empty array.
     */
    public static function assertArray(
        array $box,
        string $ingredient,
        string $creatorLabel
    ): void {
        if (
            !array_key_exists($ingredient, $box)
            || !is_array($box[$ingredient])
            || $box[$ingredient] === []
        ) {
            throw new RuntimeException(
                "{$creatorLabel} Creator cannot start: "
                . "{$ingredient} must be a non-empty array."
            );
        }
    }

    /**
     * Verify that a specific ingredient is a non-empty string.
     */
    public static function assertString(
        array $box,
        string $ingredient,
        string $creatorLabel
    ): void {
        if (
            !array_key_exists($ingredient, $box)
            || !is_string($box[$ingredient])
            || trim($box[$ingredient]) === ''
        ) {
            throw new RuntimeException(
                "{$creatorLabel} Creator cannot start: "
                . "{$ingredient} must be a non-empty string."
            );
        }
    }

    /**
     * Determine whether an ingredient should be considered empty.
     *
     * false, 0, and other legitimate scalar values are NOT
     * automatically treated as missing.
     */
    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}