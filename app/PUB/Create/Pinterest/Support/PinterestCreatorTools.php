<?php
declare(strict_types=1);

namespace App\PUB\Create\Pinterest\Support;

use RuntimeException;

/**
 * PINTEREST CREATOR TOOLS
 *
 * Shared equipment for Pinterest creators.
 *
 * This class contains reusable Pinterest rendering
 * techniques only.
 *
 * It does NOT:
 *   - define creator recipes
 *   - decide which labels belong on an asset
 *   - resolve database references
 *   - resolve photo paths
 *   - define PUB contracts
 */
final class PinterestCreatorTools
{
    public const WIDTH = 1000;
    public const HEIGHT = 1500;

    private const LOGO_WIDTH = 188;
    private const LOGO_MARGIN = 20;
    private const TITLE_LINE_GAP = 24;


    public function createCanvas(): \GdImage
    {
        $canvas =
            imagecreatetruecolor(
                self::WIDTH,
                self::HEIGHT
            );

        $white =
            imagecolorallocate(
                $canvas,
                255,
                255,
                255
            );

        imagefill(
            $canvas,
            0,
            0,
            $white
        );

        return $canvas;
    }


    public function copyImageCover(
        \GdImage $canvas,
        string $sourcePath,
        int $dstX,
        int $dstY,
        int $dstW,
        int $dstH
    ): void {
        $info =
            getimagesize(
                $sourcePath
            );

        if (!$info) {
            throw new RuntimeException(
                "Invalid image: {$sourcePath}"
            );
        }

        [$srcW, $srcH] =
            $info;

        $source =
            $this->openImage(
                $sourcePath,
                (string)(
                    $info['mime']
                    ?? ''
                )
            );

        $scale =
            max(
                $dstW /
                    max(
                        1,
                        $srcW
                    ),

                $dstH /
                    max(
                        1,
                        $srcH
                    )
            );

        $cropW =
            (int)round(
                $dstW /
                $scale
            );

        $cropH =
            (int)round(
                $dstH /
                $scale
            );

        $srcX =
            max(
                0,

                (int)floor(
                    (
                        $srcW -
                        $cropW
                    ) / 2
                )
            );

        $srcY =
            max(
                0,

                (int)floor(
                    (
                        $srcH -
                        $cropH
                    ) / 2
                )
            );

        imagecopyresampled(
            $canvas,
            $source,
            $dstX,
            $dstY,
            $srcX,
            $srcY,
            $dstW,
            $dstH,
            $cropW,
            $cropH
        );

        imagedestroy(
            $source
        );
    }


    /**
     * Draw a centered, wrapped title block.
     *
     * The Creator owns the recipe values:
     *   - position
     *   - dimensions
     *   - font sizes
     *   - max lines
     *   - weight
     *
     * This tool owns only the typography mechanics:
     *   - font lookup
     *   - word wrapping
     *   - shrink-to-fit
     *   - truncation
     *   - centering
     *   - drawing
     */
    public function drawTitleBlock(
        \GdImage $canvas,
        string $text,
        int $x,
        int $y,
        int $width,
        int $height,
        int $fontSize,
        int $minFontSize,
        int $maxLines = 0,
        bool $extraWeight = false
    ): void {
        $text =
            trim(
                $text
            );

        if ($text === '') {
            throw new RuntimeException(
                'Text block cannot be empty.'
            );
        }


        if (
            $width <= 0
            ||
            $height <= 0
        ) {
            throw new RuntimeException(
                'Text block requires positive width and height.'
            );
        }


        if (
            $fontSize <= 0
            ||
            $minFontSize <= 0
            ||
            $minFontSize >
                $fontSize
        ) {
            throw new RuntimeException(
                'Text block font sizes are invalid.'
            );
        }



        $font =
            $this->findFont();

        $black =
            imagecolorallocate(
                $canvas,
                17,
                17,
                17
            );


        /*
         * Last-resort server fallback.
         */
        if ($font === null) {
            imagestring(
                $canvas,
                5,
                $x,
                $y + 20,
                $text,
                $black
            );

            return;
        }


        $currentFontSize =
            $fontSize;

        $lineHeight =
            $currentFontSize
            +
            self::TITLE_LINE_GAP;

        $lines =
            $this->wrapText(
                $text,
                $font,
                $currentFontSize,
                $width
            );


        while (
            (
                (
                    $maxLines > 0
                    &&
                    count(
                        $lines
                    )
                    >
                    $maxLines
                )
                ||
                count(
                    $lines
                )
                *
                $lineHeight
                >
                $height
            )
            &&
            $currentFontSize >
                $minFontSize
        ) {
            $currentFontSize -=
                2;

            $lineHeight =
                $currentFontSize
                +
                self::TITLE_LINE_GAP;

            $lines =
                $this->wrapText(
                    $text,
                    $font,
                    $currentFontSize,
                    $width
                );
        }


        if (
            $maxLines > 0
            &&
            count(
                $lines
            )
            >
            $maxLines
        ) {
            $lines =
                array_slice(
                    $lines,
                    0,
                    $maxLines
                );

            $lastIndex =
                $maxLines
                -
                1;

            $lines[
                $lastIndex
            ] =
                $this->truncateLine(
                    $lines[
                        $lastIndex
                    ],
                    $font,
                    $currentFontSize,
                    $width
                );
        }


        $textY =
            $y
            +
            (int)round(
                (
                    $height
                    -
                    (
                        count(
                            $lines
                        )
                        *
                        $lineHeight
                    )
                ) / 2
            )
            +
            $currentFontSize;


        foreach (
            $lines
            as $line
        ) {
            $lineX =
                $x
                +
                (int)round(
                    (
                        $width
                        -
                        $this->textWidth(
                            $line,
                            $font,
                            $currentFontSize
                        )
                    ) / 2
                );


            if ($extraWeight) {
                foreach (
                    [
                        [-1, 0],
                        [1, 0],
                        [0, -1],
                        [0, 1],
                        [-1, -1],
                        [1, -1],
                        [-1, 1],
                        [1, 1],
                        [2, 0],
                        [0, 2],
                    ]
                    as [
                        $dx,
                        $dy,
                    ]
                ) {
                    imagettftext(
                        $canvas,
                        $currentFontSize,
                        0,
                        $lineX + $dx,
                        $textY + $dy,
                        $black,
                        $font,
                        $line
                    );
                }
            }


            imagettftext(
                $canvas,
                $currentFontSize,
                0,
                $lineX,
                $textY,
                $black,
                $font,
                $line
            );


            $textY +=
                $lineHeight;
        }
    }


    /**
     * Draw a polished Pinterest badge.
     *
     * The caller decides:
     *   - label
     *   - position
     *
     * This tool owns only the rendering technique.
     */
    public function drawBadge(
        \GdImage $canvas,
        string $label,
        int $x,
        int $y
    ): void {
        $isColorFixed =
            $label ===
            'ColorFixed';

        $font =
            $this->findFont();

        $fontSize =
            $isColorFixed
                ? 32
                : 36;

        $width =
            225;

        if (
            $isColorFixed
            && $font
        ) {
            $beforeBox =
                imagettfbbox(
                    36,
                    0,
                    $font,
                    'BEFORE'
                );

            $colorFixedBox =
                imagettfbbox(
                    $fontSize,
                    0,
                    $font,
                    $label
                );

            $beforeTextW =
                $beforeBox
                    ? abs(
                        (int)$beforeBox[4]
                        -
                        (int)$beforeBox[0]
                    )
                    : 0;

            $colorFixedTextW =
                $colorFixedBox
                    ? abs(
                        (int)$colorFixedBox[4]
                        -
                        (int)$colorFixedBox[0]
                    )
                    : 0;

            $sidePadding =
                max(
                    18,

                    (int)round(
                        (
                            225 -
                            $beforeTextW
                        ) / 2
                    )
                );

            $width =
                $colorFixedTextW
                +
                (
                    $sidePadding
                    * 2
                );

        } elseif (
            $isColorFixed
        ) {
            $width =
                250;
        }

        $height =
            $isColorFixed
                ? 68
                : 72;

        /*
         * Old recipe intentionally used
         * square corners.
         */
        $radius =
            0;

        /*
         * Semi-transparent black badge.
         */
        $black =
            imagecolorallocatealpha(
                $canvas,
                0,
                0,
                0,
                48
            );

        $white =
            imagecolorallocate(
                $canvas,
                255,
                255,
                255
            );

        $shadow =
            imagecolorallocatealpha(
                $canvas,
                0,
                0,
                0,
                8
            );

        $this->drawRoundedRect(
            $canvas,
            $x,
            $y,
            $width,
            $height,
            $radius,
            $black
        );


        /*
         * Prefer real TTF typography.
         */
        if ($font) {
            $box =
                imagettfbbox(
                    $fontSize,
                    0,
                    $font,
                    $label
                );

            $textW =
                $box
                    ? abs(
                        (int)$box[4]
                        -
                        (int)$box[0]
                    )
                    : 0;

            $textH =
                $box
                    ? abs(
                        (int)$box[5]
                        -
                        (int)$box[1]
                    )
                    : 0;

            $textX =
                $x
                +
                (int)round(
                    (
                        $width -
                        $textW
                    ) / 2
                );

            $textY =
                $y
                +
                (int)round(
                    (
                        $height +
                        $textH
                    ) / 2
                )
                - 2;


            /*
             * Tiny shadow.
             */
            imagettftext(
                $canvas,
                $fontSize,
                0,
                $textX + 2,
                $textY + 2,
                $shadow,
                $font,
                $label
            );


            /*
             * Slightly strengthen the white
             * lettering without changing font.
             */
            foreach (
                [
                    [-2, 0],
                    [-1, -1],
                    [-1, 0],
                    [-1, 1],
                    [0, -1],
                    [0, 0],
                    [0, 1],
                    [1, -1],
                    [1, 0],
                    [1, 1],
                    [2, 0],
                ]
                as [
                    $dx,
                    $dy,
                ]
            ) {
                imagettftext(
                    $canvas,
                    $fontSize,
                    0,
                    $textX + $dx,
                    $textY + $dy,
                    $white,
                    $font,
                    $label
                );
            }

            return;
        }


        /*
         * Fallback only if no usable TTF
         * font exists on the server.
         */
        $this->drawScaledFallbackText(
            $canvas,
            $label,
            $x,
            $y,
            $width,
            $height,
            $white
        );
    }


    public function drawLogo(
        \GdImage $canvas,
        string $logoPath
    ): void {
        if (!is_file($logoPath)) {
            throw new RuntimeException(
                "Pinterest logo file not found: {$logoPath}"
            );
        }

        $info =
            getimagesize(
                $logoPath
            );

        if (!$info) {
            throw new RuntimeException(
                "Invalid Pinterest logo file: {$logoPath}"
            );
        }

        [$srcW, $srcH] =
            $info;

        $source =
            $this->openImage(
                $logoPath,
                (string)(
                    $info['mime']
                    ?? ''
                )
            );

        imagealphablending(
            $source,
            true
        );

        imagesavealpha(
            $source,
            true
        );

        $dstW =
            self::LOGO_WIDTH;

        $dstH =
            max(
                1,

                (int)round(
                    $srcH
                    *
                    (
                        $dstW /
                        max(
                            1,
                            $srcW
                        )
                    )
                )
            );

        $dstX =
            self::WIDTH
            -
            self::LOGO_MARGIN
            -
            $dstW;

        $dstY =
            self::HEIGHT
            -
            self::LOGO_MARGIN
            -
            $dstH;

        imagecopyresampled(
            $canvas,
            $source,
            $dstX,
            $dstY,
            0,
            0,
            $dstW,
            $dstH,
            $srcW,
            $srcH
        );

        imagedestroy(
            $source
        );
    }



    /**
     * Draw one reusable paint-can lid.
     *
     * The Creator decides which colors belong in the product,
     * where each lid goes, and how large it is.
     *
     * Expected color shape:
     *   ['color_hex6' => 'a1b2c3']
     */
    public function drawPaintCanLid(
        \GdImage $canvas,
        array $color,
        int $cx,
        int $cy,
        int $diameter
    ): void {
        if ($diameter <= 0) {
            throw new RuntimeException(
                'Paint-can lid diameter must be positive.'
            );
        }

        $hex =
            $this->normalizeHex(
                (string)(
                    $color[
                        'color_hex6'
                    ]
                    ?? ''
                )
            );

        if ($hex === null) {
            throw new RuntimeException(
                'Paint-can lid requires a valid color_hex6.'
            );
        }

        [
            $r,
            $g,
            $b,
        ] =
            sscanf(
                $hex,
                '%02x%02x%02x'
            );

        $paint =
            imagecolorallocate(
                $canvas,
                $r,
                $g,
                $b
            );

        $rim =
            imagecolorallocate(
                $canvas,
                176,
                176,
                176
            );

        $rimDark =
            imagecolorallocate(
                $canvas,
                104,
                104,
                104
            );

        $highlight =
            imagecolorallocatealpha(
                $canvas,
                255,
                255,
                255,
                65
            );

        $shadow =
            imagecolorallocatealpha(
                $canvas,
                0,
                0,
                0,
                84
            );

        $innerDiameter =
            (int)round(
                $diameter * 0.78
            );

        $highlightDiameter =
            (int)round(
                $diameter * 0.34
            );

        imagefilledellipse(
            $canvas,
            $cx + 8,
            $cy + 12,
            $diameter,
            $diameter,
            $shadow
        );

        imagefilledellipse(
            $canvas,
            $cx,
            $cy + 4,
            $diameter,
            $diameter,
            $rimDark
        );

        imagefilledellipse(
            $canvas,
            $cx,
            $cy,
            $diameter,
            $diameter,
            $rim
        );

        imagefilledellipse(
            $canvas,
            $cx,
            $cy,
            $innerDiameter,
            $innerDiameter,
            $paint
        );

        imagearc(
            $canvas,
            $cx - (int)round($diameter * 0.1),
            $cy - (int)round($diameter * 0.16),
            $highlightDiameter,
            $highlightDiameter,
            190,
            340,
            $highlight
        );
    }

    public function writeJpeg(
        \GdImage $canvas,
        string $outputPath,
        int $quality = 90
    ): array {
        $dir =
            dirname(
                $outputPath
            );

        if (
            !is_dir(
                $dir
            )
            &&
            !mkdir(
                $dir,
                0775,
                true
            )
            &&
            !is_dir(
                $dir
            )
        ) {
            imagedestroy(
                $canvas
            );

            throw new RuntimeException(
                "Failed to create output folder: {$dir}"
            );
        }

        if (
            !imagejpeg(
                $canvas,
                $outputPath,
                $quality
            )
        ) {
            imagedestroy(
                $canvas
            );

            throw new RuntimeException(
                "Failed to write Pinterest JPEG: {$outputPath}"
            );
        }

        imagedestroy(
            $canvas
        );

        return [
            'path' =>
                $outputPath,

            'file_size_bytes' =>
                is_file(
                    $outputPath
                )
                    ? filesize(
                        $outputPath
                    )
                    : null,

            'checksum' =>
                is_file(
                    $outputPath
                )
                    ? hash_file(
                        'sha256',
                        $outputPath
                    )
                    : null,
        ];
    }


    private function openImage(
        string $sourcePath,
        string $mime
    ): \GdImage {
        $source =
            match (
                strtolower(
                    $mime
                )
            ) {
                'image/jpeg' =>
                    @imagecreatefromjpeg(
                        $sourcePath
                    ),

                'image/png' =>
                    @imagecreatefrompng(
                        $sourcePath
                    ),

                'image/webp' =>
                    function_exists(
                        'imagecreatefromwebp'
                    )
                        ? @imagecreatefromwebp(
                            $sourcePath
                        )
                        : false,

                default =>
                    @imagecreatefromstring(
                        (string)@file_get_contents(
                            $sourcePath
                        )
                    ),
            };

        if (
            !$source instanceof
            \GdImage
        ) {
            throw new RuntimeException(
                "Unsupported image: {$sourcePath}"
            );
        }

        return $source;
    }


    private function drawRoundedRect(
        \GdImage $canvas,
        int $x,
        int $y,
        int $width,
        int $height,
        int $radius,
        int $color
    ): void {
        if ($radius <= 0) {
            imagefilledrectangle(
                $canvas,
                $x,
                $y,
                $x + $width,
                $y + $height,
                $color
            );

            return;
        }

        imagefilledrectangle(
            $canvas,
            $x + $radius,
            $y,
            $x + $width - $radius,
            $y + $height,
            $color
        );

        imagefilledrectangle(
            $canvas,
            $x,
            $y + $radius,
            $x + $width,
            $y + $height - $radius,
            $color
        );

        imagefilledellipse(
            $canvas,
            $x + $radius,
            $y + $radius,
            $radius * 2,
            $radius * 2,
            $color
        );

        imagefilledellipse(
            $canvas,
            $x + $width - $radius,
            $y + $radius,
            $radius * 2,
            $radius * 2,
            $color
        );

        imagefilledellipse(
            $canvas,
            $x + $radius,
            $y + $height - $radius,
            $radius * 2,
            $radius * 2,
            $color
        );

        imagefilledellipse(
            $canvas,
            $x + $width - $radius,
            $y + $height - $radius,
            $radius * 2,
            $radius * 2,
            $color
        );
    }


    private function findFont(): ?string
    {
        $candidates = [
            /*
             * Current ColorFix project locations.
             */
            dirname(
                __DIR__,
                5
            )
            . '/public/fonts/Inter-SemiBold.ttf',

            dirname(
                __DIR__,
                5
            )
            . '/public/fonts/Inter-Bold.ttf',

            dirname(
                __DIR__,
                5
            )
            . '/public/fonts/Montserrat-SemiBold.ttf',

            dirname(
                __DIR__,
                5
            )
            . '/public/fonts/Montserrat-Bold.ttf',

            dirname(
                __DIR__,
                5
            )
            . '/fonts/Montserrat.ttf',

            dirname(
                __DIR__,
                5
            )
            . '/public/fonts/Montserrat.ttf',


            /*
             * Common server fallbacks.
             */
            '/usr/share/fonts/truetype/inter/Inter-SemiBold.ttf',
            '/usr/share/fonts/truetype/inter/Inter-Bold.ttf',
            '/usr/share/fonts/truetype/montserrat/Montserrat-SemiBold.ttf',
            '/usr/share/fonts/truetype/montserrat/Montserrat-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ];

        foreach (
            $candidates
            as $candidate
        ) {
            if (
                is_file(
                    $candidate
                )
            ) {
                return $candidate;
            }
        }

        return null;
    }


    private function wrapText(
        string $text,
        string $font,
        int $fontSize,
        int $maxWidth
    ): array {
        $words =
            preg_split(
                '/\s+/',
                trim(
                    $text
                )
            )
            ?: [];

        $lines = [];

        $line =
            '';


        foreach (
            $words
            as $word
        ) {
            $test =
                trim(
                    $line
                    . ' '
                    . $word
                );


            if (
                $line !== ''
                &&
                $this->textWidth(
                    $test,
                    $font,
                    $fontSize
                )
                >
                $maxWidth
            ) {
                $lines[] =
                    $line;

                $line =
                    $word;

            } else {
                $line =
                    $test;
            }
        }


        if ($line !== '') {
            $lines[] =
                $line;
        }


        return $lines;
    }


    private function truncateLine(
        string $line,
        string $font,
        int $fontSize,
        int $maxWidth
    ): string {
        $line =
            trim(
                $line
            );


        if (
            $this->textWidth(
                $line,
                $font,
                $fontSize
            )
            <=
            $maxWidth
        ) {
            return $line;
        }


        $suffix =
            '...';


        while (
            $line !== ''
            &&
            $this->textWidth(
                rtrim(
                    $line
                )
                . $suffix,
                $font,
                $fontSize
            )
            >
            $maxWidth
        ) {
            $line =
                rtrim(
                    substr(
                        $line,
                        0,
                        -1
                    )
                );
        }


        return $line !== ''
            ? $line
                . $suffix
            : $suffix;
    }


    private function textWidth(
        string $text,
        string $font,
        int $fontSize
    ): int {
        $box =
            imagettfbbox(
                $fontSize,
                0,
                $font,
                $text
            );


        return $box
            ? abs(
                (int)$box[4]
                -
                (int)$box[0]
            )
            : 0;
    }



    private function normalizeHex(
        string $hex
    ): ?string {
        $hex =
            ltrim(
                trim(
                    $hex
                ),
                '#'
            );

        if (
            !preg_match(
                '/^[0-9a-fA-F]{6}$/',
                $hex
            )
        ) {
            return null;
        }

        return strtolower(
            $hex
        );
    }

    private function drawScaledFallbackText(
        \GdImage $canvas,
        string $label,
        int $x,
        int $y,
        int $badgeW,
        int $badgeH,
        int $color
    ): void {
        $font =
            5;

        $baseW =
            imagefontwidth(
                $font
            )
            *
            strlen(
                $label
            );

        $baseH =
            imagefontheight(
                $font
            );

        $scale =
            min(
                4,

                max(
                    1,

                    (int)floor(
                        (
                            $badgeW -
                            32
                        )
                        /
                        max(
                            1,
                            $baseW
                        )
                    )
                )
            );

        $tmpW =
            max(
                1,
                $baseW
            );

        $tmpH =
            max(
                1,
                $baseH
            );

        $tmp =
            imagecreatetruecolor(
                $tmpW,
                $tmpH
            );

        imagealphablending(
            $tmp,
            false
        );

        imagesavealpha(
            $tmp,
            true
        );

        $transparent =
            imagecolorallocatealpha(
                $tmp,
                0,
                0,
                0,
                127
            );

        imagefill(
            $tmp,
            0,
            0,
            $transparent
        );

        $white =
            imagecolorallocate(
                $tmp,
                255,
                255,
                255
            );

        imagestring(
            $tmp,
            $font,
            0,
            0,
            $label,
            $white
        );

        $targetW =
            $baseW
            *
            $scale;

        $targetH =
            $baseH
            *
            $scale;

        $dstX =
            $x
            +
            (int)round(
                (
                    $badgeW -
                    $targetW
                ) / 2
            );

        $dstY =
            $y
            +
            (int)round(
                (
                    $badgeH -
                    $targetH
                ) / 2
            );

        imagecopyresampled(
            $canvas,
            $tmp,
            $dstX,
            $dstY,
            0,
            0,
            $targetW,
            $targetH,
            $tmpW,
            $tmpH
        );

        imagedestroy(
            $tmp
        );
    }
}