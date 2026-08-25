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
    public static function all(): array
    {
        return [

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

                        'requiredIngredients' => [
                            'source_items',
                            'search_title',
                            'description',
                            'tags',
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
             * STAGE 3 — PACKAGE
             * ============================================================
             */
            'package' => [

                'stage' =>
                    'package',

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