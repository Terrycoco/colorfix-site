<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Support;

use PDO;
use RuntimeException;

/**
 * PUB DEFAULT PANTRY
 *
 * Shared ANALYZE-side procurement equipment.
 *
 * Resolves a raw default Asset Library reference into a complete,
 * standard Creator-ready ingredient shape.
 *
 * Creators never use this class and never receive Asset Library IDs.
 */
final class DefaultPantry
{
    public function __construct(
        private PDO $pdo,
        private string $projectRoot,
    ) {}


    /**
     * Resolve one raw audio pantry reference into:
     *
     *   file_path
     *   audio_url
     *   volume
     *
     * audio_url is browser-facing and may remain root-relative.
     * A video Creator may make it absolute later when preparing a
     * renderer job, just as it already does for image_url.
     */
    public function prepareAudio(
        array $reference
    ): array {
        $assetLibraryId =
            (int)(
                $reference[
                    'asset_library_id'
                ]
                ?? 0
            );


        if ($assetLibraryId <= 0) {
            throw new RuntimeException(
                'Default audio pantry reference has no valid asset_library_id.'
            );
        }


        if (
            !is_numeric(
                $reference[
                    'volume'
                ]
                ?? null
            )
        ) {
            throw new RuntimeException(
                'Default audio pantry reference has no valid volume.'
            );
        }


        $volume =
            (float)$reference[
                'volume'
            ];


        if (
            $volume < 0
            || $volume > 1
        ) {
            throw new RuntimeException(
                'Default audio volume must be between 0 and 1.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<'SQL'
                SELECT
                    asset_library_id,
                    asset_kind,
                    mime_type,
                    rel_path,
                    title

                FROM asset_library

                WHERE asset_library_id =
                    :asset_library_id

                  AND (
                      asset_kind = 'audio'
                      OR mime_type LIKE 'audio/%'
                      OR LOWER(rel_path) REGEXP '\\.(mp3|wav|m4a|aac|flac|ogg)(\\?.*)?$'
                  )

                  AND is_inactive = 0
                  AND is_retired = 0

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'asset_library_id' =>
                $assetLibraryId,
        ]);


        $asset =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$asset) {
            throw new RuntimeException(
                "Default audio Asset Library item #{$assetLibraryId} was not found or is not active audio."
            );
        }


        $relPath =
            trim(
                (string)(
                    $asset[
                        'rel_path'
                    ]
                    ?? ''
                )
            );


        if ($relPath === '') {
            throw new RuntimeException(
                "Default audio Asset Library item #{$assetLibraryId} has no rel_path."
            );
        }


        return [
            'file_path' =>
                $this->resolveFilePath(
                    $relPath
                ),

            'audio_url' =>
                $this->publicUrlForRelPath(
                    $relPath
                ),

            'volume' =>
                $volume,
        ];
    }


    private function resolveFilePath(
        string $relPath
    ): string {
        $urlPath =
            parse_url(
                $relPath,
                PHP_URL_PATH
            );


        $normalized =
            ltrim(
                str_replace(
                    '\\',
                    '/',
                    (string)(
                        $urlPath !== false
                        && $urlPath !== null
                            ? $urlPath
                            : $relPath
                    )
                ),
                '/'
            );


        $projectRoot =
            rtrim(
                $this->projectRoot,
                DIRECTORY_SEPARATOR
            );


        $candidates = [
            $projectRoot
                . '/'
                . $normalized,

            $projectRoot
                . '/public/'
                . $normalized,
        ];


        foreach (
            array_unique(
                $candidates
            )
            as $candidate
        ) {
            $real =
                realpath(
                    $candidate
                );


            if (
                $real !== false
                && is_file(
                    $real
                )
            ) {
                return $real;
            }
        }


        throw new RuntimeException(
            "Default audio file '{$relPath}' could not be resolved on the server."
        );
    }


    private function publicUrlForRelPath(
        string $relPath
    ): string {
        $relPath =
            trim(
                $relPath
            );


        if (
            preg_match(
                '~^https?://~i',
                $relPath
            ) === 1
        ) {
            return $relPath;
        }


        return
            '/'
            . ltrim(
                $relPath,
                '/'
            );
    }
}
