<?php
declare(strict_types=1);

namespace App\PUB\Support;

use JsonException;

/**
 * PRODUCTION SIGNATURE
 *
 * Creates one deterministic SHA-256 fingerprint from a complete
 * Creator ingredients box.
 *
 * Associative/object keys are sorted recursively because key order
 * is not production meaning. List order is preserved because sequence
 * can be meaningful production input.
 */
final class ProductionSignature
{
    public static function fromIngredients(
        array $ingredients
    ): string {
        $canonical =
            self::canonicalize(
                $ingredients
            );

        $json =
            json_encode(
                $canonical,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
            );

        return hash(
            'sha256',
            $json
        );
    }


    private static function canonicalize(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }


        /*
         * Ordered lists stay ordered.
         */
        if (array_is_list($value)) {
            return array_map(
                static fn (
                    mixed $item
                ): mixed =>
                    self::canonicalize(
                        $item
                    ),
                $value
            );
        }


        /*
         * Associative/object keys do not carry production order.
         */
        ksort(
            $value,
            SORT_STRING
        );


        foreach (
            $value
            as $key => $item
        ) {
            $value[
                $key
            ] =
                self::canonicalize(
                    $item
                );
        }


        return $value;
    }
}
