<?php
declare(strict_types=1);

namespace App\PUB\Create\Pinterest;

use App\PUB\Create\CreateBoxValidator;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Create\Pinterest\Support\PinterestCreatorTools;
use RuntimeException;

/**
 * PINTEREST COMPOSITE CREATOR
 *
 * Receives:
 *
 *   pub_asset_id
 *   Creator-specific ingredients
 *
 * The Manager has already:
 *
 *   - reserved the asset ID
 *   - filed the exact order
 *   - determined NEW vs REDO
 *
 * This Creator does NOT:
 *
 *   - reserve IDs
 *   - file orders
 *   - determine NEW vs REDO
 *
 * It simply makes the requested asset.
 *
 * INGREDIENTS:
 *   before.file_path
 *   after.file_path
 *
 * RECIPE:
 *   1. Validate supplied ingredients.
 *   2. Create a 1000 x 1500 Pinterest canvas.
 *   3. Place BEFORE in the upper half.
 *   4. Place AFTER in the lower half.
 *   5. Add divider.
 *   6. Add BEFORE / ColorFixed labels.
 *   7. Add ColorFix logo.
 *   8. Write directly to the permanent asset filename.
 *   9. Verify the physical file exists.
 *  10. Return the completed asset{} to CreateManager.
 *
 * PUBCOM:
 *
 *   - reports worker readiness
 *   - preflights the supplied ingredients
 *   - may report expected production conditions
 *     upward through its assigned PubComChannel
 */
final class CompositeCreator implements PubComWorkerContract
{
    private const REQUIRED_INGREDIENTS = [
        'before',
        'after',
    ];


    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private PinterestCreatorTools $tools,
        private string $projectRoot,
        private string $logoPath,
    ) {}


    /**
     * PUBCOM ONBOARDING
     *
     * Connect this Creator to the communication channel
     * owned by the Create Manager supervising the work.
     */
    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * PUBCOM READINESS
     *
     * No separate workstation health probe currently
     * exists for this Creator.
     *
     * Assignment-specific source checks belong in
     * preflight().
     */
    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Composite Creator is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * PUBCOM PREFLIGHT
     *
     * Inspect the exact Creator ingredients before the
     * Manager authorizes production.
     *
     * Expected assignment problems are reported as
     * INELIGIBLE rather than thrown as system failures.
     */
    public function preflight(
        array $ingredients
    ): PubComSignal {
        /*
         * SAME BOX CONTRACT USED BY create().
         *
         * Keep CreateBoxValidator as the authority.
         */
        try {
            CreateBoxValidator::assertRequired(
                self::REQUIRED_INGREDIENTS,
                $ingredients,
                'Composite'
            );

            CreateBoxValidator::assertArray(
                $ingredients,
                'before',
                'Composite'
            );

            CreateBoxValidator::assertArray(
                $ingredients,
                'after',
                'Composite'
            );

        } catch (RuntimeException $e) {
            return PubComSignal::ineligible(
                'composite_invalid_ingredients',
                'Composite cannot be created because its ingredients are incomplete or invalid.',
                [
                    'worker' =>
                        self::class,

                    'reason' =>
                        $e->getMessage(),
                ]
            );
        }


        $beforePath =
            trim(
                (string)(
                    $ingredients[
                        'before'
                    ][
                        'file_path'
                    ]
                    ?? ''
                )
            );


        $afterPath =
            trim(
                (string)(
                    $ingredients[
                        'after'
                    ][
                        'file_path'
                    ]
                    ?? ''
                )
            );


        if ($beforePath === '') {
            return PubComSignal::ineligible(
                'composite_before_file_path_missing',
                'Composite cannot be created because the Before source file path is missing.',
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        if ($afterPath === '') {
            return PubComSignal::ineligible(
                'composite_after_file_path_missing',
                'Composite cannot be created because the After source file path is missing.',
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        if (!is_file($beforePath)) {
            return PubComSignal::ineligible(
                'composite_before_source_file_missing',
                'Composite cannot be created because the Before source file does not exist.',
                [
                    'worker' =>
                        self::class,

                    'file_path' =>
                        $beforePath,
                ]
            );
        }


        if (!is_file($afterPath)) {
            return PubComSignal::ineligible(
                'composite_after_source_file_missing',
                'Composite cannot be created because the After source file does not exist.',
                [
                    'worker' =>
                        self::class,

                    'file_path' =>
                        $afterPath,
                ]
            );
        }


        return PubComSignal::ready(
            'Composite assignment is eligible.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * CREATE THE ASSET
     *
     * The Create Manager is responsible for running
     * readiness and preflight before calling create().
     *
     * These validation checks remain here as a defensive
     * backstop in case this method is ever invoked outside
     * the normal Manager workflow.
     */
    public function create(
        int $pubAssetId,
        array $ingredients
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Composite Creator requires a valid pub_asset_id.'
            );
        }


        /*
         * STATION CHECK
         */
        CreateBoxValidator::assertRequired(
            self::REQUIRED_INGREDIENTS,
            $ingredients,
            'Composite'
        );

        CreateBoxValidator::assertArray(
            $ingredients,
            'before',
            'Composite'
        );

        CreateBoxValidator::assertArray(
            $ingredients,
            'after',
            'Composite'
        );


        /*
         * COOK.
         *
         * NEW and REDO both arrive here
         * in exactly the same form:
         *
         *   pub_asset_id + ingredients
         *
         * Persistence belongs to CreateManager.
         * This Creator returns one complete finished asset{}.
         */
        return $this->render(
            $pubAssetId,
            $ingredients
        );
    }


    private function render(
        int $pubAssetId,
        array $ingredients
    ): array {
        /*
         * GET PREPARED INGREDIENT FILES.
         *
         * ANALYZE already supplied exact
         * physical source paths.
         */
        $beforePath =
            trim(
                (string)(
                    $ingredients[
                        'before'
                    ][
                        'file_path'
                    ]
                    ?? ''
                )
            );

        $afterPath =
            trim(
                (string)(
                    $ingredients[
                        'after'
                    ][
                        'file_path'
                    ]
                    ?? ''
                )
            );


        if ($beforePath === '') {
            throw new RuntimeException(
                'Composite Creator cannot start: before.file_path is missing.'
            );
        }


        if ($afterPath === '') {
            throw new RuntimeException(
                'Composite Creator cannot start: after.file_path is missing.'
            );
        }


        if (!is_file($beforePath)) {
            throw new RuntimeException(
                "Composite Creator source file does not exist: {$beforePath}"
            );
        }


        if (!is_file($afterPath)) {
            throw new RuntimeException(
                "Composite Creator source file does not exist: {$afterPath}"
            );
        }


        /*
         * FETCH THE CUTTING BOARD.
         */
        $canvas =
            $this->tools
                ->createCanvas();


        $halfHeight =
            intdiv(
                PinterestCreatorTools::HEIGHT,
                2
            );


        /*
         * BEFORE — TOP HALF.
         */
        $this->tools
            ->copyImageCover(
                $canvas,
                $beforePath,
                0,
                0,
                PinterestCreatorTools::WIDTH,
                $halfHeight
            );


        /*
         * AFTER — BOTTOM HALF.
         */
        $this->tools
            ->copyImageCover(
                $canvas,
                $afterPath,
                0,
                $halfHeight,
                PinterestCreatorTools::WIDTH,
                PinterestCreatorTools::HEIGHT
                    - $halfHeight
            );


        /*
         * DIVIDER.
         */
        $divider =
            imagecolorallocate(
                $canvas,
                255,
                255,
                255
            );


        imagefilledrectangle(
            $canvas,
            0,
            $halfHeight - 3,
            PinterestCreatorTools::WIDTH,
            $halfHeight + 3,
            $divider
        );


        /*
         * LABELS.
         */
        $this->tools
            ->drawBadge(
                $canvas,
                'BEFORE',
                30,
                30
            );


        $this->tools
            ->drawBadge(
                $canvas,
                'ColorFixed',
                30,
                $halfHeight + 30
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
         * REDO intentionally writes to
         * this same file again.
         */
        $filePath =
            rtrim(
                $this->projectRoot,
                DIRECTORY_SEPARATOR
            )
            . '/public/pub_assets/pinterest/'
            . $pubAssetId
            . '.jpg';


        /*
         * PERMANENT BROWSER LOCATION.
         */
        $url =
            '/public/pub_assets/pinterest/'
            . $pubAssetId
            . '.jpg';


        /*
         * PLATE THE DISH.
         *
         * For REDO this overwrites the
         * existing asset file in place.
         */
        $written =
            $this->tools
                ->writeJpeg(
                    $canvas,
                    $filePath
                );


        /*
         * VERIFY PHYSICAL FILE.
         */
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
                "Composite Creator produced no valid file for PUB asset {$pubAssetId}."
            );
        }


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
}