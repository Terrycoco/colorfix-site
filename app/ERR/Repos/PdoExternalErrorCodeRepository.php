<?php
declare(strict_types=1);

namespace App\ERR\Repos;

use InvalidArgumentException;
use PDO;

/**
 * Read-only translation of a provider's error into a registered ColorFix error.
 * No logging, policy decisions, recovery, or notifications belong here.
 */
final class PdoExternalErrorCodeRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Find the ColorFix error number for an external error.
     *
     * Match order: exact provider code (and HTTP status when configured),
     * then case-insensitive literal message substring. A NULL HTTP status
     * in a mapping acts as a provider-specific wildcard.
     *
     * A matching code takes priority over a message, but ambiguous mappings
     * return null rather than selecting an arbitrary handler. Does not
     * interpret patterns as regex or SQL LIKE expressions.
     */
    public function resolve(
        string $provider,
        ?int $httpStatus = null,
        ?string $extErrNum = null,
        ?string $message = null
    ): ?int {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            throw new InvalidArgumentException('Provider is required.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('HTTP status must be between 100 and 599.');
        }

        $extErrNum = $extErrNum === null ? null : trim($extErrNum);
        $message = $message === null ? null : trim($message);
        if ($extErrNum === '') $extErrNum = null;
        if ($message === '') $message = null;

        $stmt = $this->pdo->prepare(
            'SELECT id, http_status, ext_err_num, message_pattern, cf_err_num
             FROM err_external_codes
             WHERE provider = :provider
               AND (http_status = :http_status OR http_status IS NULL)
             ORDER BY id ASC'
        );
        $stmt->execute(['provider' => $provider, 'http_status' => $httpStatus]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // More-specific HTTP matches outrank wildcard mappings.
        foreach (['native_exact', 'native_wildcard', 'message_exact', 'message_wildcard'] as $tier) {
            $found = [];
            foreach ($rows as $row) {
                $hasExactHttp = $row['http_status'] !== null;
                $wantExactHttp = str_ends_with($tier, '_exact');
                if ($hasExactHttp !== $wantExactHttp) continue;

                $matches = str_starts_with($tier, 'native_')
                    ? ($extErrNum !== null && $row['ext_err_num'] !== null
                        && $extErrNum === (string)$row['ext_err_num'])
                    : ($message !== null && $row['message_pattern'] !== null
                        && trim((string)$row['message_pattern']) !== ''
                        && stripos($message, (string)$row['message_pattern']) !== false);
                if ($matches) $found[(int)$row['cf_err_num']] = true;
            }
            if (count($found) === 1) return (int)array_key_first($found);
            if (count($found) > 1) return null;
        }

        return null;
    }
}
