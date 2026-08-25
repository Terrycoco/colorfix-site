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
 * PINTEREST IDEA + PALETTE CREATOR
 *
 * CHEF'S INGREDIENT ORDER:
 *
 *   ingredients {
 *     source {
 *       file_path
 *     }
 *
 *     search_title
 *
 *     palette_colors [
 *       {
 *         color_hex6
 *       }
 *     ]
 *   }
 *
 * palette_colors must contain 1-4 colors.
 *
 * RECIPE:
 *   - 1000 x 1500 canvas
 *   - 280px baked title band
 *   - source photo
 *   - 260px palette band
 *   - 1-4 paint-can lids
 *   - ColorFix logo
 *   - JPEG output
 *
 * This Creator does not procure palette data. The future
 * Palette Analyzer must deliver the completed ingredients.
 */
final class PaletteCreator implements PubComWorkerContract
{
    private const REQUIRED_INGREDIENTS = [
        'source',
        'search_title',
        'palette_colors',
    ];

    private const TITLE_HEIGHT = 280;
    private const TITLE_PADDING_X = 70;
    private const TITLE_Y = 34;
    private const TITLE_INNER_HEIGHT = 218;
    private const TITLE_FONT_SIZE = 58;
    private const TITLE_MIN_FONT_SIZE = 42;
    private const TITLE_MAX_LINES = 2;

    private const PALETTE_BAND_HEIGHT = 260;
    private const LID_DIAMETER = 150;
    private const LID_GAP = 34;
    private const MAX_COLORS = 4;

    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private PinterestCreatorTools $tools,
        private string $projectRoot,
        private string $logoPath,
    ) {}


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Palette Creator is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    public function preflight(
        array $ingredients
    ): PubComSignal {
        try {
            CreateBoxValidator::assertRequired(
                self::REQUIRED_INGREDIENTS,
                $ingredients,
                'Palette'
            );

            CreateBoxValidator::assertArray(
                $ingredients,
                'source',
                'Palette'
            );

            CreateBoxValidator::assertArray(
                $ingredients,
                'palette_colors',
                'Palette'
            );
        } catch (RuntimeException $e) {
            return PubComSignal::ineligible(
                'palette_invalid_ingredients',
                'Palette cannot be created because its ingredients are incomplete or invalid.',
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

        $colors =
            $this->paletteColors(
                $ingredients
            );

        if (
            $sourcePath === ''
            ||
            !is_file(
                $sourcePath
            )
        ) {
            return PubComSignal::ineligible(
                'palette_source_file_missing',
                'Palette cannot be created because its source file is missing.',
                [
                    'worker' =>
                        self::class,

                    'file_path' =>
                        $sourcePath,
                ]
            );
        }

        if ($searchTitle === '') {
            return PubComSignal::ineligible(
                'palette_search_title_missing',
                'Palette cannot be created because search_title is missing.',
                [
                    'worker' =>
                        self::class,
                ]
            );
        }

        if (
            count(
                $colors
            )
            < 1
            ||
            count(
                $colors
            )
            >
            self::MAX_COLORS
        ) {
            return PubComSignal::ineligible(
                'palette_color_count_invalid',
                'Palette requires between one and four palette colors.',
                [
                    'worker' =>
                        self::class,

                    'color_count' =>
                        count(
                            $colors
                        ),
                ]
            );
        }

        foreach (
            $colors
            as $index =>
                $color
        ) {
            if (
                !is_array(
                    $color
                )
                ||
                !$this->validHex(
                    (string)(
                        $color[
                            'color_hex6'
                        ]
                        ?? ''
                    )
                )
            ) {
                return PubComSignal::ineligible(
                    'palette_color_invalid',
                    'Palette cannot be created because one palette color is invalid.',
                    [
                        'worker' =>
                            self::class,

                        'color_index' =>
                            $index,
                    ]
                );
            }
        }

        return PubComSignal::ready(
            'Palette assignment is eligible.',
            [
                'worker' =>
                    self::class,

                'color_count' =>
                    count(
                        $colors
                    ),
            ]
        );
    }


    public function create(
        int $pubAssetId,
        array $ingredients
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Palette Creator requires a valid pub_asset_id.'
            );
        }

        CreateBoxValidator::assertRequired(
            self::REQUIRED_INGREDIENTS,
            $ingredients,
            'Palette'
        );

        CreateBoxValidator::assertArray(
            $ingredients,
            'source',
            'Palette'
        );

        CreateBoxValidator::assertArray(
            $ingredients,
            'palette_colors',
            'Palette'
        );

        $sourcePath =
            $this->sourcePath(
                $ingredients
            );

        $searchTitle =
            $this->searchTitle(
                $ingredients
            );

        $colors =
            $this->paletteColors(
                $ingredients
            );

        if (
            $sourcePath === ''
            ||
            !is_file(
                $sourcePath
            )
        ) {
            throw new RuntimeException(
                "Palette Creator source file does not exist: {$sourcePath}"
            );
        }

        if ($searchTitle === '') {
            throw new RuntimeException(
                'Palette Creator requires search_title.'
            );
        }

        if (
            count(
                $colors
            )
            < 1
            ||
            count(
                $colors
            )
            >
            self::MAX_COLORS
        ) {
            throw new RuntimeException(
                'Palette Creator requires between one and four palette colors.'
            );
        }

        foreach (
            $colors
            as $color
        ) {
            if (
                !is_array(
                    $color
                )
                ||
                !$this->validHex(
                    (string)(
                        $color[
                            'color_hex6'
                        ]
                        ?? ''
                    )
                )
            ) {
                throw new RuntimeException(
                    'Palette Creator received an invalid palette color.'
                );
            }
        }

        $canvas =
            $this->tools
                ->createCanvas();

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

        $photoHeight =
            PinterestCreatorTools::HEIGHT
            -
            self::TITLE_HEIGHT
            -
            self::PALETTE_BAND_HEIGHT;

        $this->tools
            ->copyImageCover(
                $canvas,
                $sourcePath,
                0,
                self::TITLE_HEIGHT,
                PinterestCreatorTools::WIDTH,
                $photoHeight
            );

        $this->drawPaletteBand(
            $canvas,
            $colors,
            self::TITLE_HEIGHT
                +
                $photoHeight,
            self::PALETTE_BAND_HEIGHT
        );

        $this->tools
            ->drawLogo(
                $canvas,
                $this->logoPath
            );

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
            )
            <= 0
        ) {
            throw new RuntimeException(
                "Palette Creator produced no valid file for PUB asset {$pubAssetId}."
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


    private function drawPaletteBand(
        \GdImage $canvas,
        array $colors,
        int $y,
        int $height
    ): void {
        $background =
            imagecolorallocate(
                $canvas,
                248,
                246,
                242
            );

        imagefilledrectangle(
            $canvas,
            0,
            $y,
            PinterestCreatorTools::WIDTH,
            $y + $height,
            $background
        );

        $count =
            count(
                $colors
            );

        $total =
            (
                $count
                *
                self::LID_DIAMETER
            )
            +
            (
                (
                    $count - 1
                )
                *
                self::LID_GAP
            );

        $x =
            (int)round(
                (
                    PinterestCreatorTools::WIDTH
                    -
                    $total
                ) / 2
            )
            +
            intdiv(
                self::LID_DIAMETER,
                2
            );

            $cy =
                $y
                +
                intdiv(
                    $height,
                    2
                )
                -
                40;


        foreach (
            $colors
            as $color
        ) {
            $this->tools
                ->drawPaintCanLid(
                    $canvas,
                    $color,
                    $x,
                    $cy,
                    self::LID_DIAMETER
                );

            $x +=
                self::LID_DIAMETER
                +
                self::LID_GAP;
        }





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


    private function paletteColors(
        array $ingredients
    ): array {
        return is_array(
            $ingredients[
                'palette_colors'
            ]
            ?? null
        )
            ? array_values(
                $ingredients[
                    'palette_colors'
                ]
            )
            : [];
    }


    private function validHex(
        string $hex
    ): bool {
        return preg_match(
            '/^#?[0-9a-fA-F]{6}$/',
            trim(
                $hex
            )
        ) === 1;
    }
}