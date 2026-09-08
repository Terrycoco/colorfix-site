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
 * PINTEREST TEASER CREATOR
 *
 * Product specialist for an authored Pinterest teaser pin.
 *
 * INGREDIENTS:
 *   source.file_path
 *   search_title
 *
 * The title is physically baked into the JPEG and therefore belongs
 * inside the filed Creator ingredients for REDO.
 *
 * Initial visual recipe intentionally matches IdeaCreator geometry,
 * but teaser remains a separate Creator so it can diverge later.
 *
 * This Creator does NOT fetch source material, invent copy, persist rows,
 * inspect/change pingback, package, schedule, or dispatch.
 */
final class TeaserCreator implements PubComWorkerContract
{
    private const REQUIRED_INGREDIENTS = [
        'source',
        'search_title',
    ];

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

    public function connectPubCom(PubComChannel $channel): void
    {
        $this->pubComChannel = $channel;
    }

    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Teaser Creator is ready.',
            [
                'worker' => self::class,
            ]
        );
    }

    public function preflight(array $ingredients): PubComSignal
    {
        try {
            CreateBoxValidator::assertRequired(
                self::REQUIRED_INGREDIENTS,
                $ingredients,
                'Teaser'
            );

            CreateBoxValidator::assertArray(
                $ingredients,
                'source',
                'Teaser'
            );
        } catch (RuntimeException $e) {
            return PubComSignal::ineligible(
                'teaser_invalid_ingredients',
                'Teaser cannot be created because its ingredients are incomplete or invalid.',
                [
                    'worker' => self::class,
                    'reason' => $e->getMessage(),
                ]
            );
        }

        $sourcePath = $this->sourcePath($ingredients);
        $searchTitle = $this->searchTitle($ingredients);

        if ($sourcePath === '') {
            return PubComSignal::ineligible(
                'teaser_source_file_path_missing',
                'Teaser cannot be created because source.file_path is missing.',
                [
                    'worker' => self::class,
                ]
            );
        }

        if ($searchTitle === '') {
            return PubComSignal::ineligible(
                'teaser_search_title_missing',
                'Teaser cannot be created because search_title is missing.',
                [
                    'worker' => self::class,
                ]
            );
        }

        if (!is_file($sourcePath)) {
            return PubComSignal::ineligible(
                'teaser_source_file_missing',
                'Teaser cannot be created because the source file does not exist.',
                [
                    'worker' => self::class,
                    'file_path' => $sourcePath,
                ]
            );
        }

        return PubComSignal::ready(
            'Teaser assignment is eligible.',
            [
                'worker' => self::class,
            ]
        );
    }

    public function create(
        int $pubAssetId,
        array $ingredients
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Teaser Creator requires a valid pub_asset_id.'
            );
        }

        CreateBoxValidator::assertRequired(
            self::REQUIRED_INGREDIENTS,
            $ingredients,
            'Teaser'
        );

        CreateBoxValidator::assertArray(
            $ingredients,
            'source',
            'Teaser'
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
        $sourcePath = $this->sourcePath($ingredients);
        $searchTitle = $this->searchTitle($ingredients);

        if ($sourcePath === '') {
            throw new RuntimeException(
                'Teaser Creator cannot start: source.file_path is missing.'
            );
        }

        if ($searchTitle === '') {
            throw new RuntimeException(
                'Teaser Creator cannot start: search_title is missing.'
            );
        }

        if (!is_file($sourcePath)) {
            throw new RuntimeException(
                "Teaser Creator source file does not exist: {$sourcePath}"
            );
        }

        $canvas = $this->tools->createCanvas();

        $this->tools->drawTitleBlock(
            $canvas,
            $searchTitle,
            self::TITLE_PADDING_X,
            self::TITLE_Y,
            PinterestCreatorTools::WIDTH - (self::TITLE_PADDING_X * 2),
            self::TITLE_INNER_HEIGHT,
            self::TITLE_FONT_SIZE,
            self::TITLE_MIN_FONT_SIZE,
            self::TITLE_MAX_LINES,
            true
        );

        $this->tools->copyImageCover(
            $canvas,
            $sourcePath,
            0,
            self::TITLE_HEIGHT,
            PinterestCreatorTools::WIDTH,
            PinterestCreatorTools::HEIGHT - self::TITLE_HEIGHT
        );

        $this->tools->drawLogo(
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

        $written = $this->tools->writeJpeg(
            $canvas,
            $filePath
        );

        if (
            !is_file($filePath)
            || filesize($filePath) <= 0
        ) {
            throw new RuntimeException(
                "Teaser Creator produced no valid file for PUB asset {$pubAssetId}."
            );
        }

        return [
            'file_path' => $filePath,
            'url' => $url,
            'mime_type' => 'image/jpeg',
            'width' => PinterestCreatorTools::WIDTH,
            'height' => PinterestCreatorTools::HEIGHT,
            'duration_ms' => null,
            'file_size_bytes' =>
                $written['file_size_bytes']
                ?? filesize($filePath),
            'checksum' =>
                $written['checksum']
                ?? hash_file('sha256', $filePath),
        ];
    }

    private function sourcePath(array $ingredients): string
    {
        return trim(
            (string)(
                $ingredients['source']['file_path']
                ?? ''
            )
        );
    }

    private function searchTitle(array $ingredients): string
    {
        return trim(
            (string)(
                $ingredients['search_title']
                ?? ''
            )
        );
    }
}
