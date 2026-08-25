<?php
declare(strict_types=1);

namespace App\PUB\Create\Pinterest;

use App\PUB\Create\CreateBoxValidator;
use App\PUB\Create\Pinterest\Support\PinterestCreatorTools;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use RuntimeException;

/**
 * PINTEREST IDEA CREATOR
 *
 * Product specialist for a regular Pinterest Idea pin.
 *
 * Receives:
 *
 *   pub_asset_id
 *   Creator-specific ingredients
 *
 * INGREDIENTS:
 *
 *   source.file_path
 *   search_title
 *
 * search_title intentionally exists both:
 *
 *   - outside ingredients as durable asset metadata
 *   - inside ingredients because it is physically baked
 *     into this JPEG and is therefore required for REDO
 *
 * RECIPE:
 *
 *   1. Validate supplied ingredients.
 *   2. Create a 1000 x 1500 Pinterest canvas.
 *   3. Draw search_title in the 280px title band.
 *   4. Cover-fill the source image beneath the title.
 *   5. Add the ColorFix logo.
 *   6. Write directly to the permanent PUB asset filename.
 *   7. Verify the physical JPEG exists.
 *   8. Return one finished asset{} to CreateManager.
 *
 * This Creator does NOT:
 *
 *   - fetch source material
 *   - invent search_title
 *   - reserve pub_asset_id
 *   - persist pub_assets
 *   - persist pub_asset_orders
 *   - package
 *   - schedule
 *   - dispatch
 */
final class IdeaCreator implements PubComWorkerContract
{
    private const REQUIRED_INGREDIENTS = [
        'source',
        'search_title',
    ];

    /*
     * PRODUCT RECIPE CONSTANTS.
     *
     * These belong to the Idea product, not to the shared
     * drawing tools.
     */
    private const TITLE_HEIGHT = 280;
    private const TITLE_PADDING_X = 70;
    private const TITLE_Y = 34;
    private const TITLE_INNER_HEIGHT = 218;
    private const TITLE_FONT_SIZE = 58;
    private const TITLE_MIN_FONT_SIZE = 42;
    private const TITLE_MAX_LINES = 2;

    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private PinterestCreatorTools $tools,
        private string $projectRoot,
        private string $logoPath,
    ) {}


    /**
     * PUBCOM ONBOARDING
     */
    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * PUBCOM READINESS
     */
    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Idea Creator is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * PUBCOM PREFLIGHT
     *
     * Expected ingredient/source problems are operational
     * conditions and therefore report INELIGIBLE.
     */
    public function preflight(
        array $ingredients
    ): PubComSignal {
        try {
            CreateBoxValidator::assertRequired(
                self::REQUIRED_INGREDIENTS,
                $ingredients,
                'Idea'
            );

            CreateBoxValidator::assertArray(
                $ingredients,
                'source',
                'Idea'
            );

        } catch (RuntimeException $e) {
            return PubComSignal::ineligible(
                'idea_invalid_ingredients',
                'Idea cannot be created because its ingredients are incomplete or invalid.',
                [
                    'worker' =>
                        self::class,

                    'reason' =>
                        $e->getMessage(),
                ]
            );
        }


        $sourcePath =
            $this->sourcePath(
                $ingredients
            );

        $searchTitle =
            $this->searchTitle(
                $ingredients
            );


        if ($sourcePath === '') {
            return PubComSignal::ineligible(
                'idea_source_file_path_missing',
                'Idea cannot be created because source.file_path is missing.',
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        if ($searchTitle === '') {
            return PubComSignal::ineligible(
                'idea_search_title_missing',
                'Idea cannot be created because search_title is missing.',
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        if (!is_file($sourcePath)) {
            return PubComSignal::ineligible(
                'idea_source_file_missing',
                'Idea cannot be created because the source file does not exist.',
                [
                    'worker' =>
                        self::class,

                    'file_path' =>
                        $sourcePath,
                ]
            );
        }


        return PubComSignal::ready(
            'Idea assignment is eligible.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * CREATE THE PHYSICAL ASSET
     *
     * NEW and REDO arrive in exactly the same form:
     *
     *   pub_asset_id + ingredients{}
     */
    public function create(
        int $pubAssetId,
        array $ingredients
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Idea Creator requires a valid pub_asset_id.'
            );
        }


        CreateBoxValidator::assertRequired(
            self::REQUIRED_INGREDIENTS,
            $ingredients,
            'Idea'
        );

        CreateBoxValidator::assertArray(
            $ingredients,
            'source',
            'Idea'
        );


        return $this->render(
            $pubAssetId,
            $ingredients
        );
    }


    private function render(
        int $pubAssetId,
        array $ingredients
    ): array {
        $sourcePath =
            $this->sourcePath(
                $ingredients
            );

        $searchTitle =
            $this->searchTitle(
                $ingredients
            );


        if ($sourcePath === '') {
            throw new RuntimeException(
                'Idea Creator cannot start: source.file_path is missing.'
            );
        }


        if ($searchTitle === '') {
            throw new RuntimeException(
                'Idea Creator cannot start: search_title is missing.'
            );
        }


        if (!is_file($sourcePath)) {
            throw new RuntimeException(
                "Idea Creator source file does not exist: {$sourcePath}"
            );
        }


        /*
         * CUTTING BOARD.
         */
        $canvas =
            $this->tools
                ->createCanvas();


        /*
         * BAKED TITLE.
         */
        $this->tools
            ->drawTitleBlock(
                $canvas,
                $searchTitle,
                self::TITLE_PADDING_X,
                self::TITLE_Y,
                PinterestCreatorTools::WIDTH
                    -
                    (
                        self::TITLE_PADDING_X
                        * 2
                    ),
                self::TITLE_INNER_HEIGHT,
                self::TITLE_FONT_SIZE,
                self::TITLE_MIN_FONT_SIZE,
                self::TITLE_MAX_LINES,
                true
            );


        /*
         * SOURCE PHOTO.
         */
        $this->tools
            ->copyImageCover(
                $canvas,
                $sourcePath,
                0,
                self::TITLE_HEIGHT,
                PinterestCreatorTools::WIDTH,
                PinterestCreatorTools::HEIGHT
                    - self::TITLE_HEIGHT
            );


        /*
         * BRANDING.
         */
        $this->tools
            ->drawLogo(
                $canvas,
                $this->logoPath
            );


        /*
         * PERMANENT PHYSICAL LOCATION.
         *
         * REDO writes to this same file.
         */
        $filePath =
            rtrim(
                $this->projectRoot,
                DIRECTORY_SEPARATOR
            )
            . '/public/pub_assets/pinterest/'
            . $pubAssetId
            . '.jpg';


        $url =
            '/public/pub_assets/pinterest/'
            . $pubAssetId
            . '.jpg';


        $written =
            $this->tools
                ->writeJpeg(
                    $canvas,
                    $filePath
                );


        if (
            !is_file(
                $filePath
            )
            ||
            filesize(
                $filePath
            ) <= 0
        ) {
            throw new RuntimeException(
                "Idea Creator produced no valid file for PUB asset {$pubAssetId}."
            );
        }


        /*
         * ORDER UP.
         *
         * Repository mapping belongs to CreateManager /
         * PdoPubAssetRepository.
         */
        return [
            'file_path' =>
                $filePath,

            'url' =>
                $url,

            'mime_type' =>
                'image/jpeg',

            'width' =>
                PinterestCreatorTools::WIDTH,

            'height' =>
                PinterestCreatorTools::HEIGHT,

            'duration_ms' =>
                null,

            'file_size_bytes' =>
                $written[
                    'file_size_bytes'
                ]
                ?? filesize(
                    $filePath
                ),

            'checksum' =>
                $written[
                    'checksum'
                ]
                ?? hash_file(
                    'sha256',
                    $filePath
                ),
        ];
    }


    private function sourcePath(
        array $ingredients
    ): string {
        return trim(
            (string)(
                $ingredients[
                    'source'
                ][
                    'file_path'
                ]
                ?? ''
            )
        );
    }


    private function searchTitle(
        array $ingredients
    ): string {
        return trim(
            (string)(
                $ingredients[
                    'search_title'
                ]
                ?? ''
            )
        );
    }
}