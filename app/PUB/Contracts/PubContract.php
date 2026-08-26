<?php
declare(strict_types=1);

namespace App\PUB\Contracts;

/**
 * PUB CONTRACTS
 *
 * Compact reference for the shape of each PUB handoff.
 *
 * Managers own the standard department handoff.
 * Specialists publish the exact input they require and
 * the exact output they return.
 *
 * ANALYZE prepares asset-specific ingredients.
 * CREATE consumes those ingredients and returns the
 * created physical asset.
 */
final class PubContract
{
    /*
     * ============================================================
     * PUB DEFAULT PANTRY SETTINGS
     * ============================================================
     *
     * Human-editable raw pantry selections.
     *
     * These values are for ANALYZE/procurement only. A Creator never
     * receives an Asset Library ID and never looks anything up.
     *
     * Set DEFAULT_YOUTUBE_MUSIC_ASSET_LIBRARY_ID to the Asset Library
     * row that should be used when a new YouTube video has no authored
     * music selection.
     */
    public const DEFAULT_YOUTUBE_MUSIC_ASSET_LIBRARY_ID = 734;

    public const DEFAULT_YOUTUBE_MUSIC_VOLUME = 0.35;


    public static function all(): array
    {
        return [

            /*
             * ============================================================
             * PUB DEFAULTS / DEFAULT PANTRY
             * ============================================================
             *
             * An Analyzer may use a default ONLY when the product contract
             * explicitly declares that ingredient as defaultable.
             *
             * These are raw pantry references, not Creator ingredients.
             * ANALYZE must resolve them into the complete standard ingredient
             * shape before handing the Box to CREATE.
             */
            'defaults' => [

                'label' =>
                    'Default Pantry',

                'assetTypes' => [

                    'youtube_video' => [

                        'music' => [

                            'asset_library_id' =>
                                self::DEFAULT_YOUTUBE_MUSIC_ASSET_LIBRARY_ID,

                            'volume' =>
                                self::DEFAULT_YOUTUBE_MUSIC_VOLUME,
                        ],
                    ],
                ],
            ],


            /*
             * ============================================================
             * SHARED PUB BOX LABELS
             * ============================================================
             */
            'shared' => [

                'boxFields' => [

                    [
                        'key' =>
                            'channel',

                        'label' =>
                            'Dispatch Channel',

                        'type' =>
                            'text',

                        'requiredAtHandoff' =>
                            true,

                        'systemSupplied' =>
                            true,
                    ],

                    [
                        'key' =>
                            'asset_type',

                        'label' =>
                            'Asset Type',

                        'type' =>
                            'text',

                        'requiredAtHandoff' =>
                            true,

                        'systemSupplied' =>
                            true,
                    ],

                    [
                        'key' =>
                            'source_type',

                        'label' =>
                            'Source Type',

                        'type' =>
                            'text',

                        'requiredAtHandoff' =>
                            true,

                        'systemSupplied' =>
                            true,
                    ],

                    [
                        'key' =>
                            'source_id',

                        'label' =>
                            'Source ID',

                        'type' =>
                            'number',

                        'requiredAtHandoff' =>
                            true,

                        'systemSupplied' =>
                            true,
                    ],

                    [
                        'key' =>
                            'pub_run_id',

                        'label' =>
                            'PUB Run ID',

                        'type' =>
                            'number',

                        'requiredAtHandoff' =>
                            true,

                        'systemSupplied' =>
                            true,
                    ],
                ],
            ],


            /*
             * ============================================================
             * STAGE 1 — ANALYZE
             * ============================================================
             */
            'analyze' => [

                'stage' =>
                    'analyze',

                'referenceRole' =>
                    'Procurement & Prep',

                'nextStage' =>
                    'create',


                /*
                 * ANALYZE MANAGER
                 *
                 * Receives the prepared source and performs only the
                 * destination/channel cull before specialist routing.
                 */
                'manager' => [

                    'input' => [
                        [
                            'key' =>
                                'source_type',
                        ],

                        [
                            'key' =>
                                'source_id',
                        ],

                        [
                            'key' =>
                                'title',
                        ],

                        [
                            'key' =>
                                'items[]',
                        ],

                        [
    'key' =>
        'linked_pvs[]',

    'type' =>
        'array',

    'fields' => [
        [
            'key' =>
                'pv_id',

            'type' =>
                'number',
        ],

        [
            'key' =>
                'kicker',

            'type' =>
                'text',
        ],

        [
            'key' =>
                'intro',

            'type' =>
                'text',
        ],

        [
            'key' =>
                'photo_palettes[]',

            'type' =>
                'array',

            'fields' => [
                [
                    'key' =>
                        'photo_library_id',

                    'type' =>
                        'number',
                ],

                [
                    'key' =>
                        'hex6s[]',

                    'type' =>
                        'array',
                ],
            ],
        ],
    ],
],
                    ],

                    /*
                     * Complete Box handed from ANALYZE to CREATE.
                     *
                     * The specialist Analyzer supplies ingredients{}.
                     * AnalyzeManager adds the standard Box fields.
                     */
                    'output' => [
                        [
                            'key' =>
                                'pub_run_id',
                        ],

                        [
                            'key' =>
                                'channel',
                        ],

                        [
                            'key' =>
                                'asset_type',
                        ],

                        [
                            'key' =>
                                'source_type',
                        ],

                        [
                            'key' =>
                                'source_id',
                        ],

                        [
                            'key' =>
                                'search_title',
                        ],

                        [
                            'key' =>
                                'description',
                        ],

                        [
                            'key' =>
                                'pingback',
                        ],

                        [
                            'key' =>
                                'ingredients',

                            'type' =>
                                'object',
                        ],
                    ],
                ],


                'assetTypes' => [

                    /*
                     * ----------------------------------------------------
                     * PINTEREST COMPOSITE
                     * ----------------------------------------------------
                     */
                    'composite' => [

                        'label' =>
                            'Composite',

                        'channel' =>
                            'pinterest',

                        'createsAssetType' =>
                            'pin_composite',


                        /*
                         * SPECIALIST ANALYZER CONTRACT
                         */
                        'workbench' => [

                            'sourceColumns' => [
                                [
                                    'label' =>
                                        'Before',

                                    'ingredientPath' =>
                                        'before.file_path',
                                ],

                                [
                                    'label' =>
                                        'After',

                                    'ingredientPath' =>
                                        'after.file_path',
                                ],
                            ],
                        ],


'analyzer' => [

    'input' => [
        [
            'key' =>
                'items[]',

            'note' =>
                'pin = 1',
        ],

        [
            'key' =>
                'linked_pvs[]',

            'fields' => [
                [
                    'key' =>
                        'kicker',

                    'note' =>
                        'may return as search_title',
                ],

                [
                    'key' =>
                        'intro',

                    'note' =>
                        'may return as description',
                ],

                [
                    'key' =>
                        'photo_palettes[].photo_library_id',

                    'note' =>
                        'pairs PV with the correct photo',
                ],
            ],
        ],
    ],

    'requires' => [
        [
            'key' =>
                'before_slide',

            'note' =>
                'analyzer_role = before',
        ],

        [
            'key' =>
                'after_slide',

            'note' =>
                'analyzer_role = after',
        ],

        [
            'key' =>
                'valid_transformation_pair',
        ],
    ],

    'output' => [
        [
            'key' =>
                'asset_type',
        ],

        [
            'key' =>
                'search_title',

            'required' =>
                false,
        ],

        [
            'key' =>
                'description',

            'required' =>
                false,
        ],

        [
            'key' =>
                'ingredients',

            'type' =>
                'object',

            'fields' => [
                [
                    'key' =>
                        'before.file_path',

                    'required' =>
                        true,
                ],

                [
                    'key' =>
                        'after.file_path',

                    'required' =>
                        true,
                ],
            ],
        ],
    ],
],
                    ],


                    /*
                     * ----------------------------------------------------
                     * PINTEREST BEFORE / AFTER VIDEO
                     * ----------------------------------------------------
                     */
                    'before_after_video' => [

                        'label' =>
                            'Before / After Video',

                        'channel' =>
                            'pinterest',

                        'createsAssetType' =>
                            'pin_before_after_video',

                        'workbench' => [

                            'sourceColumns' => [
                                [
                                    'label' =>
                                        'Before',

                                    'ingredientPath' =>
                                        'before.file_path',
                                ],

                                [
                                    'label' =>
                                        'After',

                                    'ingredientPath' =>
                                        'after.file_path',
                                ],
                            ],
                        ],

                        'analyzer' => [

                            'input' => [
                                [
                                    'key' =>
                                        'items[]',

                                    'note' =>
                                        'pin = 1',
                                ],

                                [
                                    'key' =>
                                        'linked_pvs[]',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'kicker',

                                            'note' =>
                                                'may return as search_title',
                                        ],

                                        [
                                            'key' =>
                                                'intro',

                                            'note' =>
                                                'may return as description',
                                        ],

                                        [
                                            'key' =>
                                                'photo_palettes[].photo_library_id',

                                            'note' =>
                                                'pairs PV with the correct After photo',
                                        ],
                                    ],
                                ],
                            ],

                            'requires' => [
                                [
                                    'key' =>
                                        'before_slide',

                                    'note' =>
                                        'analyzer_role = before',
                                ],

                                [
                                    'key' =>
                                        'after_slide',

                                    'note' =>
                                        'analyzer_role = after',
                                ],

                                [
                                    'key' =>
                                        'valid_transformation_pair',
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'asset_type',
                                ],

                                [
                                    'key' =>
                                        'search_title',

                                    'required' =>
                                        false,
                                ],

                                [
                                    'key' =>
                                        'description',

                                    'required' =>
                                        false,
                                ],

                                [
                                    'key' =>
                                        'ingredients',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'before.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'before.image_url',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'after.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'after.image_url',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'search_title',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'baked into video',
                                        ],

                                        [
                                            'key' =>
                                                'end_slide_text',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'inside production copy; editable for REDO',
                                        ],
                                    ],
                                ],
                            ],
                        ],

                        'ingredientBindings' => [
                            [
                                'boxField' =>
                                    'search_title',

                                'ingredientPath' =>
                                    'search_title',
                            ],
                        ],
                    ],


                    /*
                     * ----------------------------------------------------
                     * PINTEREST IDEA
                     * ----------------------------------------------------
                     */
'idea' => [

    'label' =>
        'Idea',

    'channel' =>
        'pinterest',

    'createsAssetType' =>
        'pin_idea',

    'workbench' => [

        'sourceColumns' => [
            [
                'label' =>
                    'After',

                'ingredientPath' =>
                    'source.file_path',
            ],
        ],
    ],

    'analyzer' => [

        'input' => [
            [
                'key' =>
                    'items[]',

                'note' =>
                    'pin = 1',
            ],

            [
                'key' =>
                    'linked_pvs[]',

                'fields' => [
                    [
                        'key' =>
                            'kicker',

                        'note' =>
                            'may return as search_title',
                    ],

                    [
                        'key' =>
                            'intro',

                        'note' =>
                            'may return as description',
                    ],

                    [
                        'key' =>
                            'photo_palettes[].photo_library_id',

                        'note' =>
                            'pairs PV with source photo',
                    ],
                ],
            ],
        ],

        'requires' => [
            [
                'key' =>
                    'idea_source_slide',

                'note' =>
                    'analyzer_role = after or single',
            ],

            [
                'key' =>
                    'photo.file_path',

                'note' =>
                    'prepared physical source',
            ],
        ],

        'output' => [
            [
                'key' =>
                    'asset_type',
            ],

            [
                'key' =>
                    'search_title',

                'required' =>
                    false,
            ],

            [
                'key' =>
                    'description',

                'required' =>
                    false,
            ],

            [
                'key' =>
                    'ingredients',

                'type' =>
                    'object',

                'fields' => [
                    [
                        'key' =>
                            'source.file_path',

                        'required' =>
                            true,
                    ],

                    [
                        'key' =>
                            'search_title',

                        'required' =>
                            true,

                        'note' =>
                            'baked into JPEG',
                    ],
                ],
            ],
        ],
    ],

    'ingredientBindings' => [
        [
            'boxField' =>
                'search_title',

            'ingredientPath' =>
                'search_title',
        ],
    ],
],


                    /*
                     * ----------------------------------------------------
                     * PINTEREST IDEA + PALETTE
                     * ----------------------------------------------------
                     */
'idea_palette' => [

    'label' =>
        'Idea + Palette',

    'channel' =>
        'pinterest',

    'createsAssetType' =>
        'pin_idea_palette',

    'workbench' => [

        'sourceColumns' => [
            [
                'label' =>
                    'Source',

                'ingredientPath' =>
                    'source.file_path',
            ],
        ],
    ],

    'analyzer' => [

        'input' => [
            [
                'key' =>
                    'items[]',

                'note' =>
                    'pin = 1',
            ],

            [
                'key' =>
                    'linked_pvs[]',

                'fields' => [
                    [
                        'key' =>
                            'pv_id',
                    ],

                    [
                        'key' =>
                            'kicker',

                        'note' =>
                            'may return as search_title',
                    ],

                    [
                        'key' =>
                            'intro',

                        'note' =>
                            'may return as description',
                    ],

                    [
                        'key' =>
                            'photo_palettes[].photo_library_id',

                        'note' =>
                            'joins PV to PhotoEntity',
                    ],

                    [
                        'key' =>
                            'photo_palettes[].hex6s[]',

                        'note' =>
                            'actual palette colors',
                    ],
                ],
            ],
        ],

        'requires' => [
            [
                'key' =>
                    'linked_palette_viewer',
            ],

            [
                'key' =>
                    'matching_pinterest_photo',
            ],

            [
                'key' =>
                    'palette_colors',

                'note' =>
                    '1-4 valid hex6 colors',
            ],
        ],

        'output' => [
            [
                'key' =>
                    'asset_type',
            ],

            [
                'key' =>
                    'search_title',

                'required' =>
                    false,
            ],

            [
                'key' =>
                    'description',

                'required' =>
                    false,
            ],

            [
                'key' =>
                    'ingredients',

                'type' =>
                    'object',

                'fields' => [
                    [
                        'key' =>
                            'source.file_path',

                        'required' =>
                            true,
                    ],

                    [
                        'key' =>
                            'search_title',

                        'required' =>
                            true,

                        'note' =>
                            'baked into JPEG',
                    ],

                    [
                        'key' =>
                            'palette_colors[].color_hex6',

                        'required' =>
                            true,

                        'note' =>
                            '1-4 colors rendered by Creator',
                    ],
                ],
            ],
        ],
    ],

    'ingredientBindings' => [
        [
            'boxField' =>
                'search_title',

            'ingredientPath' =>
                'search_title',
        ],
    ],
],


                    /*
                     * ----------------------------------------------------
                     * YOUTUBE VIDEO
                     * ----------------------------------------------------
                     */
                    'youtube_video' => [

                        'label' =>
                            'YouTube Video',

                        'channel' =>
                            'youtube',

                        'createsAssetType' =>
                            'youtube_video',

                        /*
                         * SPECIALIST ANALYZER CONTRACT
                         *
                         * AnalyzeManager has already culled items[] to yt = 1.
                         * PlaylistVideoAnalyzer prepares ONE proposal for the
                         * complete playlist video and returns only the fields
                         * declared by the YouTube Creator.
                         *
                         * Slide preparation is ITEM-TYPE SPECIFIC. The Sous Chef
                         * does not pass every source field through just because
                         * it exists on the authored playlist slide.
                         */
                        'analyzer' => [

                            'input' => [
                                [
                                    'key' =>
                                        'items[]',

                                    'note' =>
                                        'yt = 1; array order is playlist order; source may contain more fields than the Creator orders',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'item_type',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'photo.file_path',

                                            'required' =>
                                                false,
                                        ],

                                        [
                                            'key' =>
                                                'photo.image_url',

                                            'required' =>
                                                false,
                                        ],

                                        [
                                            'key' =>
                                                'title',

                                            'required' =>
                                                false,
                                        ],

                                        [
                                            'key' =>
                                                'subtitle',

                                            'required' =>
                                                false,
                                        ],

                                        [
                                            'key' =>
                                                'body',

                                            'required' =>
                                                false,

                                            'note' =>
                                                'source field only; pass downstream only for an item type whose Creator contract explicitly asks for it',
                                        ],
                                    ],
                                ],
                            ],

                            'requires' => [
                                [
                                    'key' =>
                                        'youtube_slides',

                                    'note' =>
                                        'at least one yt = 1 slide',
                                ],

                                [
                                    'key' =>
                                        'youtube_slide_type_rules',

                                    'note' =>
                                        'eligibility is item-type specific; do not apply one universal photo-or-text rule to every slide',
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'asset_type',
                                ],

                                [
                                    'key' =>
                                        'ingredients',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'music',

                                            'type' =>
                                                'object',

                                            'required' =>
                                                true,

                                            'defaultFrom' =>
                                                'defaults.assetTypes.youtube_video.music',

                                            'fields' => [
                                                [
                                                    'key' =>
                                                        'file_path',

                                                    'required' =>
                                                        true,
                                                ],

                                                [
                                                    'key' =>
                                                        'audio_url',

                                                    'required' =>
                                                        true,
                                                ],

                                                [
                                                    'key' =>
                                                        'volume',

                                                    'required' =>
                                                        true,
                                                ],
                                            ],

                                            'note' =>
                                                'defaultable ingredient; if source music is missing, Analyzer may use the declared default pantry reference, but must resolve it into this complete standard audio ingredient before handoff; Asset Library ID does not go to CREATE',
                                        ],

                                        [
                                            'key' =>
                                                'slides[]',

                                            'type' =>
                                                'array',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'one ordered prepared slide array for one complete YouTube video; each item is culled to the exact fields ordered by the Creator for that item_type',

                                            'fields' => [
                                                [
                                                    'key' =>
                                                        'item_type',

                                                    'required' =>
                                                        true,
                                                ],
                                            ],

                                            'itemTypes' => [

                                                'intro' => [
                                                    'photo' =>
                                                        'optional',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'note' =>
                                                        'photo is optional; when supplied it must include both photo.file_path and photo.image_url',
                                                ],

                                                'text' => [
                                                    'photo' =>
                                                        'optional',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'requiresAny' => [
                                                        'title',
                                                        'subtitle',
                                                    ],

                                                    'note' =>
                                                        'photo is optional; title and subtitle remain distinct because CREATE gives them different text treatments',
                                                ],

                                                'palette' => [
                                                    'photo' =>
                                                        'required',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'photo.file_path',

                                                            'required' =>
                                                                true,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'photo.image_url',

                                                            'required' =>
                                                                true,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'note' =>
                                                        'photo required; text optional',
                                                ],

                                                'non-palette' => [
                                                    'photo' =>
                                                        'required',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'photo.file_path',

                                                            'required' =>
                                                                true,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'photo.image_url',

                                                            'required' =>
                                                                true,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'note' =>
                                                        'photo required; text optional',
                                                ],


                                                'hue-wheel' => [
                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'hue_wheel.spokes[]',

                                                            'type' =>
                                                                'array',

                                                            'required' =>
                                                                true,

                                                            'fields' => [
                                                                [
                                                                    'key' =>
                                                                        'hue',

                                                                    'required' =>
                                                                        true,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'color',

                                                                    'required' =>
                                                                        true,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'animate',

                                                                    'required' =>
                                                                        false,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'delay_ms',

                                                                    'required' =>
                                                                        false,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'duration_ms',

                                                                    'required' =>
                                                                        false,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'start_radius',

                                                                    'required' =>
                                                                        false,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'end_radius',

                                                                    'required' =>
                                                                        false,
                                                                ],
                                                            ],
                                                        ],
                                                    ],

                                                    'note' =>
                                                        'Sous Chef parses authored hue-wheel body JSON and keeps only renderer-independent spoke ingredients plus slide title/subtitle; spoke labels and wheel-level authored presentation settings are not passed to CREATE',
                                                ],

                                                'brand-bumper' => [
                                                    'fields' => [],

                                                    'note' =>
                                                        'item_type is the complete ingredient for this item: end the video with the standard ColorFix brand bumper; do not pass title, subtitle, body, photo, or authored bumper configuration',
                                                ],

                                                'normal' => [
                                                    'photo' =>
                                                        'optional',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'photo.file_path',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'photo.image_url',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'body',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'requiresAny' => [
                                                        'photo',
                                                        'title',
                                                        'subtitle',
                                                        'body',
                                                    ],

                                                    'note' =>
                                                        'temporary compatibility rule until normal gets its own specifically tuned Chef order',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],

                    /*
                     * ----------------------------------------------------
                     * YOUTUBE TEASER PIN
                     * ----------------------------------------------------
                     */
                    'youtube_teaser_pin' => [

                        'label' =>
                            'YouTube Teaser Pin',

                        'channel' =>
                            'pinterest',

                        'requiredIngredients' => [
                            'source_item',
                            'search_title',
                            'description',
                        ],
                    ],
                ],
            ],


            /*
             * ============================================================
             * STAGE 2 — CREATE
             * ============================================================
             */
            'create' => [

                'stage' =>
                    'create',

                'referenceRole' =>
                    'Production',

                'nextStage' =>
                    'package',


                /*
                 * CREATE MANAGER
                 *
                 * Receives the complete Box, reserves/files the asset,
                 * sends only ingredients to the selected Creator, then
                 * writes the Creator result to pub_assets.
                 */
                'manager' => [

                    'input' => [
                        [
                            'key' =>
                                'pub_run_id',
                        ],

                        [
                            'key' =>
                                'channel',
                        ],

                        [
                            'key' =>
                                'asset_type',
                        ],

                        [
                            'key' =>
                                'source_type',
                        ],

                        [
                            'key' =>
                                'source_id',
                        ],

                        [
                            'key' =>
                                'search_title',
                        ],

                        [
                            'key' =>
                                'description',
                        ],

                        [
                            'key' =>
                                'pingback',
                        ],

                        [
                            'key' =>
                                'ingredients',

                            'type' =>
                                'object',
                        ],
                    ],

                    'outputToCreator' => [
                        [
                            'key' =>
                                'ingredients',

                            'type' =>
                                'object',
                        ],
                    ],

                    'output' => [
                        [
                            'key' =>
                                'pub_asset_id',
                        ],

                        [
                            'key' =>
                                'pub_run_id',
                        ],

                        [
                            'key' =>
                                'channel',
                        ],

                        [
                            'key' =>
                                'asset_type',
                        ],

                        [
                            'key' =>
                                'creator_key',
                        ],

                        [
                            'key' =>
                                'source_type',
                        ],

                        [
                            'key' =>
                                'source_id',
                        ],

                        [
                            'key' =>
                                'search_title',
                        ],

                        [
                            'key' =>
                                'description',
                        ],

                        [
                            'key' =>
                                'pingback',
                        ],

                        [
                            'key' =>
                                'file_path',
                        ],

                        [
                            'key' =>
                                'url',
                        ],

                        [
                            'key' =>
                                'mime_type',
                        ],

                        [
                            'key' =>
                                'width',
                        ],

                        [
                            'key' =>
                                'height',
                        ],

                        [
                            'key' =>
                                'duration_ms',
                        ],

                        [
                            'key' =>
                                'file_size_bytes',
                        ],

                        [
                            'key' =>
                                'checksum',
                        ],

                        [
                            'key' =>
                                'pipeline_stage',

                            'note' =>
                                'created',
                        ],

                        [
                            'key' =>
                                'local_file_status',

                            'note' =>
                                'present',
                        ],
                    ],
                ],


                'assetTypes' => [

                    /*
                     * ----------------------------------------------------
                     * PINTEREST COMPOSITE CREATOR
                     * ----------------------------------------------------
                     */
                    'pin_composite' => [

                        'label' =>
                            'Pinterest Composite',

                        'channel' =>
                            'pinterest',

                        'creator' => [

                            'input' => [
                                [
                                    'key' =>
                                        'ingredients',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'before.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'after.file_path',

                                            'required' =>
                                                true,
                                        ],
                                    ],
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'file_path',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'url',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'mime_type',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'image/jpeg',
                                ],

                                [
                                    'key' =>
                                        'width',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'height',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'duration_ms',

                                    'required' =>
                                        false,

                                    'note' =>
                                        'null',
                                ],

                                [
                                    'key' =>
                                        'file_size_bytes',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'checksum',

                                    'required' =>
                                        true,
                                ],
                            ],
                        ],
                    ],


                    /*
                     * ----------------------------------------------------
                     * PINTEREST BEFORE / AFTER VIDEO CREATOR
                     * ----------------------------------------------------
                     */
                    'pin_before_after_video' => [

                        'label' =>
                            'Pinterest Before / After Video',

                        'channel' =>
                            'pinterest',

                        'creator' => [

                            'input' => [
                                [
                                    'key' =>
                                        'ingredients',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'before.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'before.image_url',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'after.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'after.image_url',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'search_title',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'baked into video',
                                        ],

                                        [
                                            'key' =>
                                                'end_slide_text',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'inside production copy',
                                        ],
                                    ],
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'file_path',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'url',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'mime_type',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'video/mp4',
                                ],

                                [
                                    'key' =>
                                        'width',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'height',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'duration_ms',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'file_size_bytes',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'checksum',

                                    'required' =>
                                        true,
                                ],
                            ],
                        ],
                    ],

                    /*
                     * ----------------------------------------------------
                     * PINTEREST IDEA CREATOR
                     * ----------------------------------------------------
                     */
                    'pin_idea' => [

                        'label' =>
                            'Pinterest Idea',

                        'channel' =>
                            'pinterest',

                        'creator' => [

                            'input' => [
                                [
                                    'key' =>
                                        'ingredients',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'source.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'search_title',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'baked into JPEG',
                                        ],
                                    ],
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'file_path',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'url',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'mime_type',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'image/jpeg',
                                ],

                                [
                                    'key' =>
                                        'width',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'height',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'duration_ms',

                                    'required' =>
                                        false,

                                    'note' =>
                                        'null',
                                ],

                                [
                                    'key' =>
                                        'file_size_bytes',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'checksum',

                                    'required' =>
                                        true,
                                ],
                            ],
                        ],
                    ],

                    /*
                     * ----------------------------------------------------
                     * PINTEREST IDEA + PALETTE CREATOR
                     * ----------------------------------------------------
                     */
                    'pin_idea_palette' => [

                        'label' =>
                            'Pinterest Idea + Palette',

                        'channel' =>
                            'pinterest',

                        'creator' => [

                            'input' => [
                                [
                                    'key' =>
                                        'ingredients',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'source.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'search_title',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'baked into JPEG',
                                        ],

                                        [
                                            'key' =>
                                                'palette_colors[].color_hex6',

                                            'required' =>
                                                true,

                                            'note' =>
                                                '1-4 colors rendered as paint-can lids',
                                        ],
                                    ],
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'file_path',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'url',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'mime_type',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'image/jpeg',
                                ],

                                [
                                    'key' =>
                                        'width',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'height',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'duration_ms',

                                    'required' =>
                                        false,

                                    'note' =>
                                        'null',
                                ],

                                [
                                    'key' =>
                                        'file_size_bytes',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'checksum',

                                    'required' =>
                                        true,
                                ],
                            ],
                        ],
                    ],

                    /*
                     * ----------------------------------------------------
                     * YOUTUBE PLAYLIST VIDEO CREATOR
                     * ----------------------------------------------------
                     *
                     * Creator is the authority for the finished YouTube
                     * playlist video and therefore declares the exact
                     * prepared ingredients it requires from ANALYZE.
                     *
                     * The Chef receives ingredients only. Slide requirements
                     * are item-type specific so the Sous Chef can strip away
                     * everything the Chef did not order.
                     */
                    'youtube_video' => [

                        'label' =>
                            'YouTube Playlist Video',

                        'channel' =>
                            'youtube',

                        'creator' => [

                            'input' => [
                                [
                                    'key' =>
                                        'ingredients',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'music',

                                            'type' =>
                                                'object',

                                            'required' =>
                                                true,

                                            'fields' => [
                                                [
                                                    'key' =>
                                                        'file_path',

                                                    'required' =>
                                                        true,
                                                ],

                                                [
                                                    'key' =>
                                                        'audio_url',

                                                    'required' =>
                                                        true,
                                                ],

                                                [
                                                    'key' =>
                                                        'volume',

                                                    'required' =>
                                                        true,
                                                ],
                                            ],

                                            'note' =>
                                                'fully prepared audio ingredient; Creator uses it directly and performs no Asset Library lookup',
                                        ],

                                        [
                                            'key' =>
                                                'slides[]',

                                            'type' =>
                                                'array',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'ordered prepared YouTube slides; array order is playback order; each item contains only what this Chef requires for its item_type',

                                            'fields' => [
                                                [
                                                    'key' =>
                                                        'item_type',

                                                    'required' =>
                                                        true,

                                                    'note' =>
                                                        'production discriminator used by the Creator to choose the correct treatment',
                                                ],
                                            ],

                                            'itemTypes' => [

                                                'intro' => [
                                                    'photo' =>
                                                        'optional',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'note' =>
                                                        'may be text-only or photo + text; if photo is supplied, both photo.file_path and photo.image_url are required',
                                                ],

                                                'text' => [
                                                    'photo' =>
                                                        'optional',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'requiresAny' => [
                                                        'title',
                                                        'subtitle',
                                                    ],

                                                    'note' =>
                                                        'must contain title or subtitle; photo is optional; title and subtitle use different font treatments',
                                                ],

                                                'palette' => [
                                                    'photo' =>
                                                        'required',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'photo.file_path',

                                                            'required' =>
                                                                true,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'photo.image_url',

                                                            'required' =>
                                                                true,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'note' =>
                                                        'photo required; title/subtitle optional',
                                                ],

                                                'non-palette' => [
                                                    'photo' =>
                                                        'required',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'photo.file_path',

                                                            'required' =>
                                                                true,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'photo.image_url',

                                                            'required' =>
                                                                true,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'note' =>
                                                        'photo required; title/subtitle optional',
                                                ],


                                                'hue-wheel' => [
                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'hue_wheel.spokes[]',

                                                            'type' =>
                                                                'array',

                                                            'required' =>
                                                                true,

                                                            'fields' => [
                                                                [
                                                                    'key' =>
                                                                        'hue',

                                                                    'required' =>
                                                                        true,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'color',

                                                                    'required' =>
                                                                        true,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'animate',

                                                                    'required' =>
                                                                        false,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'delay_ms',

                                                                    'required' =>
                                                                        false,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'duration_ms',

                                                                    'required' =>
                                                                        false,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'start_radius',

                                                                    'required' =>
                                                                        false,
                                                                ],

                                                                [
                                                                    'key' =>
                                                                        'end_radius',

                                                                    'required' =>
                                                                        false,
                                                                ],
                                                            ],
                                                        ],
                                                    ],

                                                    'note' =>
                                                        'Chef receives exact hue/hex spoke ingredients; wheel size, standard radii/timing, typography, and overall slide treatment come from PlaylistVideoRecipe',
                                                ],

                                                'brand-bumper' => [
                                                    'fields' => [],

                                                    'note' =>
                                                        'item_type is the only ingredient: append the standard ColorFix brand bumper; size and all timing are production settings owned by PlaylistVideoRecipe',
                                                ],

                                                'normal' => [
                                                    'photo' =>
                                                        'optional',

                                                    'fields' => [
                                                        [
                                                            'key' =>
                                                                'photo.file_path',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'photo.image_url',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'title',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'subtitle',

                                                            'required' =>
                                                                false,
                                                        ],

                                                        [
                                                            'key' =>
                                                                'body',

                                                            'required' =>
                                                                false,
                                                        ],
                                                    ],

                                                    'requiresAny' => [
                                                        'photo',
                                                        'title',
                                                        'subtitle',
                                                        'body',
                                                    ],

                                                    'note' =>
                                                        'temporary compatibility rule until normal gets its own specifically tuned Chef order',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'file_path',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'url',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'mime_type',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'video/mp4',
                                ],

                                [
                                    'key' =>
                                        'width',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'height',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'duration_ms',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'file_size_bytes',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'checksum',

                                    'required' =>
                                        true,
                                ],
                            ],
                        ],
                    ],

                    'youtube_teaser_pin' => [
                        'channel' =>
                            'pinterest',
                    ],
                ],
            ],


            /*
             * ============================================================
             * STAGE 3 — PACKAGE
             * ============================================================
             */
            'package' => [

                'stage' =>
                    'package',

                'referenceRole' =>
                    'Label',

                'nextStage' =>
                    'schedule',

                'assetTypes' => [

                    'composite' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'before_after_video' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'idea' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'idea_palette' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'youtube_video' => [
                        'channel' =>
                            'youtube',
                    ],

                    'youtube_teaser_pin' => [
                        'channel' =>
                            'pinterest',
                    ],
                ],
            ],


            /*
             * ============================================================
             * STAGE 4 — SCHEDULE
             * ============================================================
             */
            'schedule' => [

                'stage' =>
                    'schedule',

                'referenceRole' =>
                    'Queue',

                'nextStage' =>
                    'dispatch',

                'assetTypes' => [

                    'composite' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'before_after_video' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'idea' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'idea_palette' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'youtube_video' => [
                        'channel' =>
                            'youtube',
                    ],

                    'youtube_teaser_pin' => [
                        'channel' =>
                            'pinterest',
                    ],
                ],
            ],


            /*
             * ============================================================
             * STAGE 5 — DISPATCH
             * ============================================================
             */
            'dispatch' => [

                'stage' =>
                    'dispatch',

                'referenceRole' =>
                    'Shipping',

                'nextStage' =>
                    null,

                'assetTypes' => [

                    'composite' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'before_after_video' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'idea' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'idea_palette' => [
                        'channel' =>
                            'pinterest',
                    ],

                    'youtube_video' => [
                        'channel' =>
                            'youtube',
                    ],

                    'youtube_teaser_pin' => [
                        'channel' =>
                            'pinterest',
                    ],
                ],
            ],


            /*
             * ============================================================
             * REPOSITORIES — STORAGE / FILE CABINETS
             * ============================================================
             *
             * These are NOT pipeline stages.
             *
             * They document the storage-facing field contracts that
             * specialists may rely on when handing durable data to PUB.
             *
             * Only content / routing fields relevant to PUB handoffs are
             * listed here. Lifecycle timestamps and error bookkeeping are
             * intentionally omitted from this reference.
             */
            'repositories' => [

                /*
                 * --------------------------------------------------------
                 * PUB ASSETS
                 * --------------------------------------------------------
                 *
                 * Durable identity, publishing metadata, and the finished
                 * physical asset fields accepted by PdoPubAssetRepository.
                 */
                'pub_assets' => [

                    'label' =>
                        'PUB Assets',

                    'fields' => [

                        [
                            'key' =>
                                'pub_asset_id',

                            'type' =>
                                'bigint(20) unsigned',
                        ],

                        [
                            'key' =>
                                'pub_run_id',

                            'type' =>
                                'bigint(20) unsigned',
                        ],

                        [
                            'key' =>
                                'channel',

                            'type' =>
                                'varchar(50)',
                        ],

                        [
                            'key' =>
                                'asset_type',

                            'type' =>
                                'varchar(100)',
                        ],

                        [
                            'key' =>
                                'creator_key',

                            'type' =>
                                'varchar(150)',
                        ],

                        [
                            'key' =>
                                'source_type',

                            'type' =>
                                'varchar(50)',
                        ],

                        [
                            'key' =>
                                'source_id',

                            'type' =>
                                'bigint(20) unsigned',
                        ],

                        [
                            'key' =>
                                'sort_order',

                            'type' =>
                                'int(11)',
                        ],

                        [
                            'key' =>
                                'file_path',

                            'type' =>
                                'varchar(1024)',
                        ],

                        [
                            'key' =>
                                'url',

                            'type' =>
                                'varchar(1024)',
                        ],

                        [
                            'key' =>
                                'mime_type',

                            'type' =>
                                'varchar(100)',
                        ],

                        [
                            'key' =>
                                'width',

                            'type' =>
                                'int(11)',
                        ],

                        [
                            'key' =>
                                'height',

                            'type' =>
                                'int(11)',
                        ],

                        [
                            'key' =>
                                'duration_ms',

                            'type' =>
                                'int(11)',
                        ],

                        [
                            'key' =>
                                'file_size_bytes',

                            'type' =>
                                'bigint(20) unsigned',
                        ],

                        [
                            'key' =>
                                'checksum',

                            'type' =>
                                'char(64)',
                        ],

                        [
                            'key' =>
                                'search_title',

                            'type' =>
                                'text',
                        ],

                        [
                            'key' =>
                                'description',

                            'type' =>
                                'text',
                        ],

                        [
                            'key' =>
                                'pingback',

                            'type' =>
                                'varchar(1000)',
                        ],
                    ],
                ],


                /*
                 * --------------------------------------------------------
                 * PUB ASSET ORDERS
                 * --------------------------------------------------------
                 *
                 * Temporary in-house production memory used for REDO.
                 *
                 * Only the relationship fields plus ingredients are shown.
                 * pub_asset_order_id / timestamps are ordinary repository
                 * bookkeeping and are intentionally omitted here.
                 */
                'pub_asset_orders' => [

                    'label' =>
                        'PUB Asset Orders',

                    'fields' => [

                        [
                            'key' =>
                                'pub_asset_id',

                            'type' =>
                                'bigint(20) unsigned',

                            'note' =>
                                'duplicate/link → pub_assets.pub_asset_id',
                        ],

                        [
                            'key' =>
                                'creator_key',

                            'type' =>
                                'varchar(120)',

                            'note' =>
                                'duplicate → pub_assets.creator_key',
                        ],

                        [
                            'key' =>
                                'ingredients',

                            'type' =>
                                'json',

                            'note' =>
                                'Creator remake ingredients',
                        ],
                    ],
                ],
            ],
        ];
    }


    /**
     * Return the configured PUB default pantry.
     */
    public static function defaults(): array
    {
        return self::all()[
            'defaults'
        ][
            'assetTypes'
        ]
            ?? [];
    }


    /**
     * Return one raw default pantry reference.
     *
     * The returned value is procurement input for ANALYZE only.
     * It is not a prepared Creator ingredient.
     */
    public static function defaultIngredient(
        string $assetType,
        string $ingredient
    ): ?array {
        $assetType =
            strtolower(
                trim(
                    $assetType
                )
            );

        $ingredient =
            strtolower(
                trim(
                    $ingredient
                )
            );


        if (
            $assetType === ''
            || $ingredient === ''
        ) {
            return null;
        }


        $value =
            self::defaults()[
                $assetType
            ][
                $ingredient
            ]
            ?? null;


        return is_array(
            $value
        )
            ? $value
            : null;
    }


    /**
     * Return one stage contract.
     */
    public static function stage(
        string $stage
    ): ?array {
        $stage =
            strtolower(
                trim(
                    $stage
                )
            );

        return self::all()[
            $stage
        ]
            ?? null;
    }


    /**
     * Return one output-type contract
     * at one stage.
     */
    public static function get(
        string $stage,
        string $assetType
    ): ?array {
        $stage =
            strtolower(
                trim(
                    $stage
                )
            );

        $assetType =
            strtolower(
                trim(
                    $assetType
                )
            );

        return self::all()[
            $stage
        ][
            'assetTypes'
        ][
            $assetType
        ]
            ?? null;
    }


    /**
     * Return every output type available
     * at one PUB stage.
     */
    public static function assetTypes(
        string $stage
    ): array {
        $stageContract =
            self::stage(
                $stage
            );

        if (
            $stageContract === null
        ) {
            return [];
        }

        return $stageContract[
            'assetTypes'
        ]
            ?? [];
    }


    /**
     * Return PUB-wide fields inherited
     * by every Box.
     */
    public static function boxFields(): array
    {
        return self::all()[
            'shared'
        ][
            'boxFields'
        ]
            ?? [];
    }


    /**
     * Return the effective contract for
     * one stage + output type.
     */
    public static function effective(
        string $stage,
        string $assetType
    ): ?array {
        $stageContract =
            self::stage(
                $stage
            );

        $assetContract =
            self::get(
                $stage,
                $assetType
            );

        if (
            $stageContract === null
            ||
            $assetContract === null
        ) {
            return null;
        }

        return [
            'stage' =>
                $stageContract[
                    'stage'
                ]
                ?? $stage,

            'nextStage' =>
                $stageContract[
                    'nextStage'
                ]
                ?? null,

            ...$assetContract,

            'boxFields' =>
                self::boxFields(),
        ];
    }


    /**
     * Find metadata -> ingredient bindings for
     * an already-created asset type.
     *
     * Example:
     *
     *   Analyze product: idea_palette
     *   creates asset:    pin_idea_palette
     */
    public static function ingredientBindingsForCreatedAssetType(
        string $assetType
    ): array {
        $assetType =
            strtolower(
                trim(
                    $assetType
                )
            );


        if ($assetType === '') {
            return [];
        }


        foreach (
            self::assetTypes(
                'analyze'
            )
            as $contract
        ) {
            $createsAssetType =
                strtolower(
                    trim(
                        (string)(
                            $contract[
                                'createsAssetType'
                            ]
                            ?? ''
                        )
                    )
                );


            if (
                $createsAssetType !==
                $assetType
            ) {
                continue;
            }


            return is_array(
                $contract[
                    'ingredientBindings'
                ]
                ?? null
            )
                ? $contract[
                    'ingredientBindings'
                ]
                : [];
        }


        return [];
    }





}