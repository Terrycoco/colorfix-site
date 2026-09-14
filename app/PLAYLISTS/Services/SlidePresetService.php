<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Services;

use App\PLAYLISTS\Repos\PdoSlidePresetRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class SlidePresetService
{
    private const INSERT_POSITIONS = [
        'top',
        'bottom',
        'after_selected',
        'before_selected',
    ];

    private PdoSlidePresetRepository $repo;

    public function __construct(
        PDO $pdo
    ) {
        $this->repo =
            new PdoSlidePresetRepository(
                $pdo
            );
    }

    /**
     * Raw admin rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPresets(
        bool $includeDisabled = true
    ): array {
        return array_map(
            fn(array $row): array =>
                $this->normalizeRow(
                    $row
                ),
            $this->repo
                ->listAll(
                    $includeDisabled
                )
        );
    }

    /**
     * Editor-facing enabled presets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEditorPresets(): array
    {
        return array_map(
            fn(array $row): array =>
                $this->toEditorPreset(
                    $this->normalizeRow(
                        $row
                    )
                ),
            $this->repo
                ->listAll(
                    false
                )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getEditorPreset(
        string $presetKey
    ): array {
        $row =
            $this->repo
                ->findByKey(
                    trim($presetKey)
                );

        if ($row === null) {
            throw new RuntimeException(
                "Playlist slide preset not found: {$presetKey}"
            );
        }

        return $this->toEditorPreset(
            $this->normalizeRow(
                $row
            )
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function savePreset(
        array $payload
    ): array {
        $presetKey =
            trim(
                (string)(
                    $payload['preset_key']
                    ?? ''
                )
            );

        $label =
            trim(
                (string)(
                    $payload['label']
                    ?? ''
                )
            );

        $itemType =
            trim(
                (string)(
                    $payload['item_type']
                    ?? ''
                )
            );

        $insertPosition =
            trim(
                (string)(
                    $payload['insert_position']
                    ?? 'after_selected'
                )
            );

        if ($presetKey === '') {
            throw new InvalidArgumentException(
                'preset_key is required.'
            );
        }

        if ($label === '') {
            throw new InvalidArgumentException(
                'label is required.'
            );
        }

        if ($itemType === '') {
            throw new InvalidArgumentException(
                'item_type is required.'
            );
        }

        if (
            !in_array(
                $insertPosition,
                self::INSERT_POSITIONS,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid insert_position.'
            );
        }

        $saved =
            $this->repo
                ->save([
                    ':preset_key' =>
                        $presetKey,

                    ':label' =>
                        $label,

                    ':item_type' =>
                        $itemType,

                    ':insert_position' =>
                        $insertPosition,

                    ':default_title' =>
                        $this->nullableString(
                            $payload['default_title']
                            ?? null
                        ),

                    ':default_subtitle' =>
                        $this->nullableString(
                            $payload['default_subtitle']
                            ?? null
                        ),

                    ':default_subtitle_2' =>
                        $this->nullableString(
                            $payload['default_subtitle_2']
                            ?? null
                        ),

                    ':default_layout' =>
                        trim(
                            (string)(
                                $payload['default_layout']
                                ?? 'default'
                            )
                        ) ?: 'default',

                    ':default_title_mode' =>
                        $this->nullableString(
                            $payload['default_title_mode']
                            ?? null
                        ),

                    ':default_star' =>
                        $this->boolInt(
                            $payload['default_star']
                            ?? true
                        ),

                    ':default_transition' =>
                        $this->nullableString(
                            $payload['default_transition']
                            ?? null
                        ),

                    ':default_duration_ms' =>
                        $this->nullableInt(
                            $payload['default_duration_ms']
                            ?? null
                        ),

                    ':default_is_active' =>
                        $this->boolInt(
                            $payload['default_is_active']
                            ?? true
                        ),

                    ':default_exclude_from_thumbs' =>
                        $this->boolInt(
                            $payload['default_exclude_from_thumbs']
                            ?? false
                        ),

                    ':default_is_share_image' =>
                        $this->boolInt(
                            $payload['default_is_share_image']
                            ?? false
                        ),

                    ':default_site' =>
                        $this->boolInt(
                            $payload['default_site']
                            ?? true
                        ),

                    ':default_yt' =>
                        $this->boolInt(
                            $payload['default_yt']
                            ?? true
                        ),

                    ':default_pin' =>
                        $this->boolInt(
                            $payload['default_pin']
                            ?? true
                        ),

                    ':default_concept' =>
                        $this->boolInt(
                            $payload['default_concept']
                            ?? true
                        ),

                    ':default_client' =>
                        $this->boolInt(
                            $payload['default_client']
                            ?? true
                        ),

                    ':default_analyzer_role' =>
                        trim(
                            (string)(
                                $payload['default_analyzer_role']
                                ?? 'ignore'
                            )
                        ) ?: 'ignore',

                    ':default_finder_start' =>
                        trim(
                            (string)(
                                $payload['default_finder_start']
                                ?? 'auto'
                            )
                        ) ?: 'auto',

                    ':default_version_number' =>
                        max(
                            1,
                            (int)(
                                $payload['default_version_number']
                                ?? 1
                            )
                        ),

                    ':default_is_final' =>
                        $this->boolInt(
                            $payload['default_is_final']
                            ?? false
                        ),

                    ':sort_order' =>
                        (int)(
                            $payload['sort_order']
                            ?? 0
                        ),

                    ':is_enabled' =>
                        $this->boolInt(
                            $payload['is_enabled']
                            ?? true
                        ),
                ]);

        return $this->normalizeRow(
            $saved
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toEditorPreset(
        array $row
    ): array {
        return [
            'preset_key' =>
                $row['preset_key'],

            'label' =>
                $row['label'],

            'item_type' =>
                $row['item_type'],

            'insert_position' =>
                $row['insert_position'],

            'defaults' => [
                'title' =>
                    $row['default_title'],

                'subtitle' =>
                    $row['default_subtitle'],

                'subtitle_2' =>
                    $row['default_subtitle_2'],

                'layout' =>
                    $row['default_layout'],

                'title_mode' =>
                    $row['default_title_mode'],

                'star' =>
                    $row['default_star'],

                'transition' =>
                    $row['default_transition'],

                'duration_ms' =>
                    $row['default_duration_ms'],

                'is_active' =>
                    $row['default_is_active'],

                'exclude_from_thumbs' =>
                    $row['default_exclude_from_thumbs'],

                'is_share_image' =>
                    $row['default_is_share_image'],

                'site' =>
                    $row['default_site'],

                'yt' =>
                    $row['default_yt'],

                'pin' =>
                    $row['default_pin'],

                'concept' =>
                    $row['default_concept'],

                'client' =>
                    $row['default_client'],

                'analyzer_role' =>
                    $row['default_analyzer_role'],

                'finder_start' =>
                    $row['default_finder_start'],

                'version_number' =>
                    $row['default_version_number'],

                'is_final' =>
                    $row['default_is_final'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(
        array $row
    ): array {
        foreach ([
            'default_star',
            'default_is_active',
            'default_exclude_from_thumbs',
            'default_is_share_image',
            'default_site',
            'default_yt',
            'default_pin',
            'default_concept',
            'default_client',
            'default_is_final',
            'is_enabled',
        ] as $key) {
            if (
                array_key_exists(
                    $key,
                    $row
                )
            ) {
                $row[$key] =
                    (bool)(
                        (int)$row[$key]
                    );
            }
        }

        foreach ([
            'default_duration_ms',
            'default_version_number',
            'sort_order',
        ] as $key) {
            if (
                array_key_exists(
                    $key,
                    $row
                )
                && $row[$key] !== null
            ) {
                $row[$key] =
                    (int)$row[$key];
            }
        }

        return $row;
    }

    private function boolInt(
        mixed $value
    ): int {
        return $value ? 1 : 0;
    }

    private function nullableInt(
        mixed $value
    ): ?int {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        return (int)$value;
    }

    private function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value =
            trim(
                (string)$value
            );

        return $value !== ''
            ? $value
            : null;
    }
}
