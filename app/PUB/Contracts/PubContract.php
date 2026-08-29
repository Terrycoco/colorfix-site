<?php
declare(strict_types=1);

namespace App\PUB\Contracts;
use App\PUB\Create\YouTube\PlaylistVideoRecipe;

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
 * created physical asset plus any product-owned companion output
 * (for example, a video thumbnail).
 * PACKAGE consumes durable pub_assets rows and returns
 * channel/media-specific package arrays.
 * DISPATCH consumes packed package arrays and returns
 * shipping receipt arrays.
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

    public const DEFAULT_YOUTUBE_MUSIC_VOLUME = PlaylistVideoRecipe::DEFAULT_MUSIC_VOLUME;


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

                /*
                 * PUB STATE NAMING CONVENTION
                 *
                 * A gerund state means real work is actively happening now.
                 * It is transient by definition and the responsible runtime
                 * must eventually advance the row to a stable state.
                 *
                 * Never leave a row parked in a gerund state when no worker,
                 * driver, or manager is still responsible for advancing it.
                 * Error recovery, normal dependency waits, validation exits,
                 * failed launches, and repaired rows must resolve to a stable
                 * state instead. A no-op gerund is a lifecycle bug.
                 */
                'stateConvention' => [

                    'gerund' => [
                        'kind' =>
                            'processing',

                        'meaning' =>
                            'Active processing is happening now; please stand by.',

                        'ui' =>
                            'Treat as transient and poll/refresh more frequently while visible.',
                    ],

                    'stable' => [
                        'kind' =>
                            'waiting_or_complete',

                        'meaning' =>
                            'No active worker is implied. The row is waiting, recoverable, reviewed, or complete.',
                    ],

                    'recoveryInvariant' =>
                        'A gerund state may exist only while a real active process is responsible for advancing it. Recovery must never leave an idle row in a gerund state.',
                ],

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

                'states' => [

                    'analyzing' => [
                        'kind' =>
                            'processing',

                        'meaning' =>
                            'in process',
                    ],

                    'analyzed' => [
                        'kind' =>
                            'waiting',

                        'meaning' =>
                            'ready for review / MARK',
                    ],
                ],


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

                                [
                                    'label' =>
                                        'Cover',

                                    'ingredientPath' =>
                                        'cover.file_path',
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

                                [
                                    'key' =>
                                        'cover_image_slide',

                                    'note' =>
                                        'item_type = cover-image; must contain a usable prepared photo',
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
                                                'cover.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'cover.image_url',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'authored cover-image photo; CREATE turns this into the durable companion JPEG',
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
                                        'yt = 1; array order is playlist order; source may contain more fields than the Creator orders; cover-image is companion source material and is not emitted into slides[]',

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

                                [
                                    'key' =>
                                        'cover_image_slide',

                                    'note' =>
                                        'exactly one usable yt = 1 item_type = cover-image with prepared photo + title; used to create the YouTube thumbnail and excluded from playback slides',
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
                                                'cover',

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
                                                        'image_url',

                                                    'required' =>
                                                        true,
                                                ],

                                                [
                                                    'key' =>
                                                        'title',

                                                    'required' =>
                                                        true,
                                                ],
                                            ],

                                            'note' =>
                                                'prepared cover-image source; photo + authored title become the designed YouTube thumbnail and do not enter the video timeline',
                                        ],

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
                                                'one ordered prepared slide array for one complete YouTube video; cover-image is excluded; each playable item is culled to the exact fields ordered by the Creator for that item_type',

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

                'states' => [

                    'creating' => [
                        'kind' =>
                            'processing',

                        'meaning' =>
                            'in process',
                    ],

                    'created' => [
                        'kind' =>
                            'complete',

                        'meaning' =>
                            'asset complete',
                    ],

                    'redo_required' => [
                        'kind' =>
                            'waiting',

                        'meaning' =>
                            'remake needed',
                    ],

                    'error' => [
                        'kind' =>
                            'error',

                        'error_stage' =>
                            'create',

                        'meaning' =>
                            'create failed',
                    ],
                ],


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
                                'thumbnail_file_path',

                            'required' =>
                                false,

                            'note' =>
                                'durable companion JPEG written by a video Creator when that product has a thumbnail/cover',
                        ],

                        [
                            'key' =>
                                'thumbnail_url',

                            'required' =>
                                false,

                            'note' =>
                                'public URL for the durable companion JPEG',
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
                                                'cover.file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'cover.image_url',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'authored cover-image photo used by CREATE to produce the companion JPEG',
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
                                        'thumbnail_file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'durable companion JPEG produced from cover-image source',
                                ],

                                [
                                    'key' =>
                                        'thumbnail_url',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'public URL Pinterest later uses as cover_image_url',
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
                                                'cover',

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
                                                        'image_url',

                                                    'required' =>
                                                        true,
                                                ],

                                                [
                                                    'key' =>
                                                        'title',

                                                    'required' =>
                                                        true,
                                                ],
                                            ],

                                            'note' =>
                                                'prepared cover-image source; Creator renders the designed thumbnail JPEG from this photo + title',
                                        ],

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
                                        'thumbnail_file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'durable designed thumbnail JPEG uploaded separately to YouTube',
                                ],

                                [
                                    'key' =>
                                        'thumbnail_url',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'public/browser-facing URL for the same durable thumbnail JPEG',
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
             *
             * PACKAGE starts after the creative asset already exists.
             *
             * Downstream routing is based on publishing channel + physical
             * media type, not the CREATE recipe that produced the asset.
             *
             * PackageManager moves an eligible durable pub_assets row into
             * pipeline_stage = packing only while a Packager is actively working,
             * then persists the Packager's returned PHP array into pub_assets.package.
             *
             * A Packager owns product/channel-specific readiness checks.
             * Missing-but-expected dependencies are reported through PubCom
             * as PENDING; active packing ends and the row moves to package_pending.
             * stage_note may explain what is still missing.
             */
            'package' => [

                'stage' =>
                    'package',

                'referenceRole' =>
                    'Packing',

                'nextStage' =>
                    'schedule',

                'states' => [

                    'ready_to_pack' => [
                        'kind' =>
                            'waiting',

                        'meaning' =>
                            'waiting to start',
                    ],

                    'packing' => [
                        'kind' =>
                            'processing',

                        'meaning' =>
                            'in process',
                    ],

                    'package_pending' => [
                        'kind' =>
                            'waiting',

                        'meaning' =>
                            'waiting on dependency',
                    ],

                    'packed' => [
                        'kind' =>
                            'complete',

                        'meaning' =>
                            'package complete',
                    ],

                    'error' => [
                        'kind' =>
                            'error',

                        'error_stage' =>
                            'package',

                        'meaning' =>
                            'package failed',
                    ],
                ],


                'manager' => [

                    /*
                     * Manager routing/intake fields.
                     *
                     * PackageManager does not inspect product-specific fields.
                     * It needs only enough durable asset identity to choose the
                     * correct specialist and persist that specialist's result.
                     */
                    'input' => [
                        [
                            'key' =>
                                'pub_asset_id',
                        ],

                        [
                            'key' =>
                                'channel',
                        ],

                        [
                            'key' =>
                                'mime_type',
                        ],

                        [
                            'key' =>
                                'pipeline_stage',

                            'note' =>
                                'packing; active processing only',
                        ],
                    ],

                    /*
                     * Successful departmental result.
                     *
                     * The specialist returns package{} as a PHP array.
                     * PackageManager passes it unchanged to the repository,
                     * which JSON-encodes it and marks the row packed.
                     */
                    'output' => [
                        [
                            'key' =>
                                'pub_asset_id',
                        ],

                        [
                            'key' =>
                                'package',

                            'type' =>
                                'object',
                        ],

                        [
                            'key' =>
                                'pipeline_stage',

                            'note' =>
                                'packed',
                        ],

                        [
                            'key' =>
                                'stage_note',

                            'required' =>
                                false,

                            'note' =>
                                'cleared on successful packing; may explain a PENDING dependency while the row is package_pending',
                        ],
                    ],
                ],

                /*
                 * PACKAGE SPECIALIST LINES
                 *
                 * These keys describe downstream shipping/media classes,
                 * not CREATE asset_type values.
                 */
                'assetTypes' => [

                    /*
                     * ----------------------------------------------------
                     * PINTEREST VIDEO PACKAGER
                     * ----------------------------------------------------
                     *
                     * Produces one sealed outbound manifest for a finished
                     * Pinterest video plus its Creator-owned companion cover.
                     */
                    'pinterest_video' => [

                        'label' =>
                            'Pinterest Video',

                        'channel' =>
                            'pinterest',

                        'mimeTypePrefix' =>
                            'video/',

                        'packager' => [

                            'input' => [
                                [
                                    'key' =>
                                        'channel',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'must be pinterest',
                                ],

                                [
                                    'key' =>
                                        'mime_type',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'must begin video/',
                                ],

                                [
                                    'key' =>
                                        'file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'finished local video file uploaded by Shipping',
                                ],

                                [
                                    'key' =>
                                        'thumbnail_file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'durable companion JPEG must exist before packing',
                                ],

                                [
                                    'key' =>
                                        'thumbnail_url',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'public companion JPEG URL; Packager resolves to an absolute Pinterest cover_image_url',
                                ],

                                [
                                    'key' =>
                                        'search_title',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'becomes package.title',
                                ],

                                [
                                    'key' =>
                                        'description',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'becomes package.description',
                                ],

                                [
                                    'key' =>
                                        'pingback',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'becomes package.link after resolving absolute URL and applying src=pin',
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'title',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'description',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'link',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'absolute destination URL with src=pin',
                                ],

                                [
                                    'key' =>
                                        'video_file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'local MP4 supplied unchanged to Pinterest video Shipping',
                                ],

                                [
                                    'key' =>
                                        'cover_image_url',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'absolute public companion JPEG URL used when the Pin is created',
                                ],
                            ],
                        ],
                    ],


                    /*
                     * ----------------------------------------------------
                     * PINTEREST IMAGE PACKAGER
                     * ----------------------------------------------------
                     *
                     * Accepts any finished Pinterest image regardless of
                     * whether CREATE produced a Composite, Idea, Palette,
                     * or a future Pinterest JPEG/image recipe.
                     */
                    'pinterest_image' => [

                        'label' =>
                            'Pinterest Image',

                        'channel' =>
                            'pinterest',

                        'mimeTypePrefix' =>
                            'image/',

                        'packager' => [

                            /*
                             * Exact durable pub_assets fields this worker
                             * reads during preflight()/pack().
                             */
                            'input' => [
                                [
                                    'key' =>
                                        'channel',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'must be pinterest',
                                ],

                                [
                                    'key' =>
                                        'mime_type',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'must begin image/',
                                ],

                                [
                                    'key' =>
                                        'file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'finished local image must exist',
                                ],

                                [
                                    'key' =>
                                        'url',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'browser-facing asset URL; Packager resolves to an absolute public URL',
                                ],

                                [
                                    'key' =>
                                        'search_title',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'becomes package.title',
                                ],

                                [
                                    'key' =>
                                        'description',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'becomes package.description',
                                ],

                                [
                                    'key' =>
                                        'pingback',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'becomes package.link after resolving absolute URL and applying src=pin',
                                ],
                            ],

                            /*
                             * Exact PHP array returned to PackageManager.
                             *
                             * The repository serializes this object into
                             * pub_assets.package. No asset IDs or lookup
                             * instructions belong inside the package.
                             */
                            'output' => [
                                [
                                    'key' =>
                                        'title',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'description',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'link',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'absolute destination URL with src=pin',
                                ],

                                [
                                    'key' =>
                                        'media_source',

                                    'type' =>
                                        'object',

                                    'required' =>
                                        true,

                                    'fields' => [
                                        [
                                            'key' =>
                                                'source_type',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'worker-owned constant: image_url',
                                        ],

                                        [
                                            'key' =>
                                                'url',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'absolute public image URL Pinterest can fetch',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],

                    /*
                     * ----------------------------------------------------
                     * YOUTUBE VIDEO PACKAGER
                     * ----------------------------------------------------
                     *
                     * Produces one sealed outbound manifest for a finished
                     * YouTube video plus its Creator-owned authored thumbnail.
                     *
                     * Publication privacy is NOT package data. It is a
                     * YouTube shipping policy owned by YouTubeShippingConfig.
                     */
                    'youtube_video' => [

                        'label' =>
                            'YouTube Video',

                        'channel' =>
                            'youtube',

                        'mimeTypePrefix' =>
                            'video/',

                        'packager' => [

                            'input' => [
                                [
                                    'key' =>
                                        'channel',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'must be youtube',
                                ],

                                [
                                    'key' =>
                                        'mime_type',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'must begin video/',
                                ],

                                [
                                    'key' =>
                                        'file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'finished local MP4 uploaded by YouTube Shipping',
                                ],

                                [
                                    'key' =>
                                        'thumbnail_file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'durable authored JPEG produced by CREATE and uploaded separately with thumbnails.set',
                                ],

                                [
                                    'key' =>
                                        'search_title',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'becomes package.title',
                                ],

                                [
                                    'key' =>
                                        'description',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'becomes package.description; key is required even when copy is empty',
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'title',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'description',

                                    'required' =>
                                        true,
                                ],

                                [
                                    'key' =>
                                        'video_file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'local MP4 supplied unchanged to YouTube resumable upload Shipping',
                                ],

                                [
                                    'key' =>
                                        'thumbnail_file_path',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'local authored JPEG supplied unchanged to YouTube thumbnails.set Shipping',
                                ],
                            ],
                        ],
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

                'states' => [

                    'packed' => [
                        'kind' =>
                            'waiting',

                        'meaning' =>
                            'waiting to schedule',
                    ],

                    'shipping' => [
                        'kind' =>
                            'processing',

                        'meaning' =>
                            'dispatch in process',
                    ],
                ],


                /*
                 * Schedule is intentionally product-agnostic.
                 *
                 * It will eventually wake itself, determine which channel
                 * is due, select one eligible packed asset, and hand pub_asset_id
                 * to DispatchManager. DispatchManager owns packed -> shipping
                 * and must start real shipping work when that gerund is persisted.
                 *
                 * Product/channel dependency rules must already be resolved
                 * upstream before an asset reaches packed.
                 *
                 * The Scheduler itself is not built yet, so no specialist
                 * assetTypes are declared here.
                 */
            ],


            /*
             * ============================================================
             * STAGE 5 — DISPATCH
             * ============================================================
             *
             * DISPATCH is Shipping.
             *
             * DispatchManager receives the asset selected by Schedule,
             * routes only far enough to choose the correct Shipper, and
             * persists the Shipper's returned receipt.
             *
             * The Shipper is the courier:
             *   - reads the sealed package
             *   - supplies its own fixed connection/config/secrets
             *   - authenticates
             *   - performs the external API protocol
             *   - returns the API receipt/result to DispatchManager
             *
             * Shipping never edits pub_assets.package.
             */
            'dispatch' => [

                'stage' =>
                    'dispatch',

                'referenceRole' =>
                    'Shipping',

                'nextStage' =>
                    null,

                'states' => [

                    'shipping' => [
                        'kind' =>
                            'processing',

                        'meaning' =>
                            'in process',
                    ],

                    'shipped' => [
                        'kind' =>
                            'complete',

                        'meaning' =>
                            'sent out, receipt posted',
                    ],

                    'error' => [
                        'kind' =>
                            'error',

                        'error_stage' =>
                            'dispatch',

                        'meaning' =>
                            'dispatch failed',
                    ],
                ],


                'manager' => [

                    'input' => [
                        [
                            'key' =>
                                'pub_asset_id',
                        ],

                        [
                            'key' =>
                                'channel',
                        ],

                        [
                            'key' =>
                                'mime_type',
                        ],

                        [
                            'key' =>
                                'pipeline_stage',

                            'note' =>
                                'shipping',
                        ],

                        [
                            'key' =>
                                'package',

                            'type' =>
                                'object',

                            'required' =>
                                true,
                        ],
                    ],

                    /*
                     * DispatchManager persists the specialist receipt into
                     * shipping_receipt, stamps dispatched_at, and moves the
                     * asset to its terminal shipped stage.
                     */
                    'output' => [
                        [
                            'key' =>
                                'pub_asset_id',
                        ],

                        [
                            'key' =>
                                'shipping_receipt',

                            'type' =>
                                'object',
                        ],

                        [
                            'key' =>
                                'dispatched_at',

                            'note' =>
                                'manager-owned shipment timestamp used by Schedule history',
                        ],

                        [
                            'key' =>
                                'pipeline_stage',

                            'note' =>
                                'shipped',
                        ],
                    ],
                ],

                /*
                 * DISPATCH SPECIALIST LINES
                 *
                 * Like PACKAGE, these are channel/media shipping classes,
                 * not CREATE recipe names.
                 */
                'assetTypes' => [

                    /*
                     * ----------------------------------------------------
                     * PINTEREST VIDEO SHIPPER
                     * ----------------------------------------------------
                     */
                    'pinterest_video' => [

                        'label' =>
                            'Pinterest Video',

                        'channel' =>
                            'pinterest',

                        'mimeTypePrefix' =>
                            'video/',

                        'shipper' => [

                            'input' => [
                                [
                                    'key' =>
                                        'package',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'title',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'description',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'link',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'video_file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'cover_image_url',

                                            'required' =>
                                                true,
                                        ],
                                    ],

                                    'note' =>
                                        'Shipper registers media, uploads video_file_path using Pinterest transient upload parameters, polls media status to succeeded, then creates the Pin with source_type=video_id and cover_image_url. Transient upload credentials are never persisted.',
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'external_id',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'Pinterest Pin ID',
                                ],

                                [
                                    'key' =>
                                        'external_url',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'Pinterest Pin URL',
                                ],

                                [
                                    'key' =>
                                        'response',

                                    'type' =>
                                        'object',

                                    'required' =>
                                        false,

                                    'note' =>
                                        'Pinterest create-Pin response retained as receipt detail when useful; transient upload parameters must not be retained',
                                ],
                            ],
                        ],
                    ],


                    /*
                     * ----------------------------------------------------
                     * PINTEREST IMAGE SHIPPER
                     * ----------------------------------------------------
                     */
                    'pinterest_image' => [

                        'label' =>
                            'Pinterest Image',

                        'channel' =>
                            'pinterest',

                        'mimeTypePrefix' =>
                            'image/',

                        'shipper' => [

                            /*
                             * Exact package supplied by Packing.
                             *
                             * Fixed board destination, Pinterest connection,
                             * OAuth secrets, endpoint configuration, and other
                             * courier constants belong to the Shipper/config
                             * and are intentionally NOT package fields.
                             */
                            'input' => [
                                [
                                    'key' =>
                                        'package',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'title',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'description',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'link',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'media_source.source_type',

                                            'required' =>
                                                true,

                                            'note' =>
                                                'image_url',
                                        ],

                                        [
                                            'key' =>
                                                'media_source.url',

                                            'required' =>
                                                true,
                                        ],
                                    ],
                                ],
                            ],

                            /*
                             * Successful Shipper result returned to
                             * DispatchManager. The Manager persists this
                             * object as pub_assets.shipping_receipt and owns
                             * the dispatched_at / shipped lifecycle stamps.
                             */
                            'output' => [
                                [
                                    'key' =>
                                        'external_id',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'Pinterest Pin ID',
                                ],

                                [
                                    'key' =>
                                        'external_url',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'Pinterest Pin URL',
                                ],

                                [
                                    'key' =>
                                        'response',

                                    'type' =>
                                        'object',

                                    'required' =>
                                        false,

                                    'note' =>
                                        'Pinterest API response retained as receipt detail when useful',
                                ],
                            ],
                        ],
                    ],

                    /*
                     * ----------------------------------------------------
                     * YOUTUBE VIDEO SHIPPER
                     * ----------------------------------------------------
                     */
                    'youtube_video' => [

                        'label' =>
                            'YouTube Video',

                        'channel' =>
                            'youtube',

                        'mimeTypePrefix' =>
                            'video/',

                        'shipper' => [

                            'input' => [
                                [
                                    'key' =>
                                        'package',

                                    'type' =>
                                        'object',

                                    'fields' => [
                                        [
                                            'key' =>
                                                'title',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'description',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'video_file_path',

                                            'required' =>
                                                true,
                                        ],

                                        [
                                            'key' =>
                                                'thumbnail_file_path',

                                            'required' =>
                                                true,
                                        ],
                                    ],

                                    'note' =>
                                        'Shipper starts a YouTube resumable videos.insert session, uploads video_file_path, obtains the YouTube video ID, then uploads thumbnail_file_path with thumbnails.set. OAuth tokens, API endpoints, retry policy, and requested privacy status belong to YouTube Auth/Shipping config and are not persisted in the package.',
                                ],
                            ],

                            'output' => [
                                [
                                    'key' =>
                                        'external_id',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'YouTube video ID',
                                ],

                                [
                                    'key' =>
                                        'external_url',

                                    'required' =>
                                        true,

                                    'note' =>
                                        'YouTube watch URL',
                                ],

                                [
                                    'key' =>
                                        'response',

                                    'type' =>
                                        'object',

                                    'required' =>
                                        false,

                                    'note' =>
                                        'useful receipt detail such as requested/actual privacy status and thumbnail_set; resumable session URI and OAuth tokens must not be retained',
                                ],
                            ],
                        ],
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
             * Only storage fields relevant to PUB handoffs are listed here.
             * Most lifecycle timestamps and error bookkeeping are intentionally
             * omitted. dispatched_at is retained because Schedule consumes
             * successful shipment history.
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
                                'thumbnail_file_path',

                            'type' =>
                                'varchar(1024)',

                            'note' =>
                                'nullable durable companion JPEG path on the same asset row; used by video products that require a cover/thumbnail',
                        ],

                        [
                            'key' =>
                                'thumbnail_url',

                            'type' =>
                                'varchar(1024)',

                            'note' =>
                                'nullable public URL for the same companion JPEG',
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

                        [
                            'key' =>
                                'pipeline_stage',

                            'type' =>
                                'varchar(50)',

                            'note' =>
                                'current PUB factory stage/state; gerund values are reserved for real active processing and must never be idle parking states',
                        ],

                        [
                            'key' =>
                                'stage_note',

                            'type' =>
                                'varchar(255)',

                            'note' =>
                                'nullable human-readable note for the current stage; PACKAGE may use it to explain package_pending dependencies',
                        ],

                        [
                            'key' =>
                                'package',

                            'type' =>
                                'json',

                            'note' =>
                                'sealed outbound package produced by PACKAGE and consumed unchanged by Shipping',
                        ],

                        [
                            'key' =>
                                'shipping_receipt',

                            'type' =>
                                'json',

                            'note' =>
                                'receipt/result returned by Shipping after successful external dispatch',
                        ],

                        [
                            'key' =>
                                'dispatched_at',

                            'type' =>
                                'datetime',

                            'note' =>
                                'successful shipment time; consumed by Schedule history',
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