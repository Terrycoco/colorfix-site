<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Website;

use App\ARTWORK\Repos\PdoArtworkRepository;
use App\ARTWORK\Services\ArtworkService;
use App\PALETTES\Services\PaletteService;
use App\PALETTES\Managers\SavedPaletteManager;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use PDO;
use RuntimeException;

/**
 * ARTICLE ANALYZER — the Article Chef's sous chef.
 *
 * Prepares one complete, ordered ingredient package per classified playlist.
 * It selects and normalizes source material but does not write article copy,
 * compose article sections, or save an article.
 */
final class ArticleAnalyzer implements PubComWorkerContract
{
    private ?PubComChannel $pubComChannel = null;

    public function __construct(private PDO $pdo) {}

    private const EDITORIAL_TYPES = [
        'makeover',
        'spotlight',
        'teaching',
        'themed_makeover',
        'palette_ideas',
        'artwork',
        'mistakes_fixes',
        'step_by_step_makeover',
    ];

    public function connectPubCom(PubComChannel $channel): void
    {
        $this->pubComChannel = $channel;
    }

    public function readiness(): PubComSignal
    {
        return PubComSignal::ready('Article Analyzer is ready.', [
            'worker' => self::class,
        ]);
    }

    public function preflight(array $source): PubComSignal
    {
        if (($source['source_type'] ?? null) !== 'playlist') {
            return PubComSignal::ineligible(
                'article_requires_playlist',
                'Article Analyzer currently supports playlist sources only.',
                ['worker' => self::class]
            );
        }

        $editorialType = strtolower(trim((string)($source['editorial_type'] ?? '')));
        if (!in_array($editorialType, self::EDITORIAL_TYPES, true)) {
            return PubComSignal::ineligible(
                'article_requires_editorial_type',
                'Article Analyzer requires one of the eight classified editorial types.',
                ['worker' => self::class, 'editorial_type' => $editorialType]
            );
        }

        if ((int)($source['source_id'] ?? 0) <= 0
            || trim((string)($source['title'] ?? '')) === '') {
            return PubComSignal::ineligible(
                'article_missing_playlist_context',
                'Article Analyzer requires a playlist ID and title.',
                ['worker' => self::class]
            );
        }

        $photoCount = 0;
        foreach ($this->editorialItems($source) as $item) {
            if ($this->usablePhoto($item) !== null) {
                $photoCount++;
            }
        }
        if ($photoCount === 0) {
            return PubComSignal::ineligible(
                'article_no_photographs',
                'Article Analyzer requires at least one prepared Photo Library image.',
                ['worker' => self::class]
            );
        }

        if ($editorialType === 'artwork') {
            try {
                $this->artworkIngredients($source);
            } catch (RuntimeException $exception) {
                return PubComSignal::ineligible(
                    'article_artwork_incomplete',
                    $exception->getMessage(),
                    ['worker' => self::class, 'editorial_type' => 'artwork']
                );
            }
        }

        return PubComSignal::ready('Article ingredients are available.', [
            'worker' => self::class,
            'editorial_type' => $editorialType,
            'photo_count' => $photoCount,
        ]);
    }

    public function analyze(array $source): array
    {
        $editorialType = strtolower(trim((string)($source['editorial_type'] ?? '')));
        $artworkIngredients = $editorialType === 'artwork'
            ? $this->artworkIngredients($source) : null;
        $artworkPhotoId = (int)($artworkIngredients['artwork']['photo_library_id'] ?? 0);

        $slides = [];
        foreach ($this->editorialItems($source) as $item) {
            // The original painting is an introduction ingredient, not a room study.
            if ($artworkPhotoId > 0 && (int)($item['photo_library_id'] ??
                $item['photo']['photo_library_id'] ?? 0) === $artworkPhotoId) {
                continue;
            }
            $slide = [
                'playlist_item_id' => (int)($item['playlist_item_id'] ?? 0),
                'order_index' => (int)($item['order_index'] ?? 0),
                'item_type' => strtolower(trim((string)($item['item_type'] ?? ''))),
                'analyzer_role' => strtolower(trim((string)($item['analyzer_role'] ?? ''))),
                'title' => trim((string)($item['title'] ?? '')),
                'subtitle' => trim((string)($item['subtitle'] ?? '')),
                'subtitle_2' => trim((string)($item['subtitle_2'] ?? '')),
                'body' => (string)($item['body'] ?? ''),
            ];

            // Preserve the prepared photo object, including all PHOTOS-owned
            // metadata and the three nullable Photo Editorial fields.
            // No extra PHOTOS lookup and no image bytes embedded in JSON.
            $photo = $this->usablePhoto($item);
            if ($photo !== null) {
                $slide['photo'] = $photo;
            }

            // Retain already-prepared palette pointers for the Chef; do not
            // resolve or fetch palettes in CREATE.
            foreach (['ap_id', 'palette_hash', 'saved_palette_set_id', 'color_plan_id'] as $key) {
                if (isset($item[$key]) && $item[$key] !== '') {
                    $slide[$key] = $item[$key];
                }
            }
            $slides[] = $slide;
        }

        $ingredients = [
                'playlist_id' => (int)$source['source_id'],
                'playlist_title' => trim((string)$source['title']),
                'editorial_type' => strtolower(trim((string)$source['editorial_type'])),
                'slides' => $slides,
                'linked_pvs' => is_array($source['linked_pvs'] ?? null)
                    ? array_values($source['linked_pvs']) : [],
        ];
        if ($artworkIngredients !== null) {
            $ingredients = array_merge($ingredients, $artworkIngredients);
        }
        return ['proposals' => [[
            'asset_type' => 'article',
            'ingredients' => $ingredients,
        ]]];
    }

    /**
     * Resolve artwork from the real PHOTOS inspiration relationships, even if
     * its slide is excluded from editorial use. Read ARTWORK/PALETTES only here;
     * the Chef receives a complete, reproducible snapshot.
     *
     * @return array{artwork:array<string,mixed>,palette:array<string,mixed>}
     */
    private function artworkIngredients(array $source): array
    {
        $items = is_array($source['items'] ?? null) ? $source['items'] : [];
        $photoIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $photoId = (int)($item['photo_library_id'] ??
                $item['photo']['photo_library_id'] ?? 0);
            if ($photoId > 0) $photoIds[] = $photoId;
        }
        $candidates = (new PdoArtworkRepository($this->pdo))
            ->findArtworkCandidatesForPhotos($photoIds);
        if (count($candidates) !== 1) {
            throw new RuntimeException(count($candidates) === 0
                ? 'Artwork article requires a painting or an explicitly art-inspired photograph in the playlist.'
                : 'Artwork article references multiple inspiration paintings; select one artwork.');
        }
        $artworkId = (int)($candidates[0]['artwork_id'] ?? 0);
        if ($artworkId <= 0) {
            throw new RuntimeException('The inspiration painting is missing its ARTWORK metadata record.');
        }
        $artwork = (new ArtworkService($this->pdo))->findById($artworkId);
        $artPhotoId = (int)($artwork['photo_library_id'] ?? 0);
        $photo = (new PdoPhotoLibraryRepository($this->pdo))->findById($artPhotoId);
        if (!is_array($photo)) {
            throw new RuntimeException('The inspiration painting is missing from Photo Library.');
        }
        $paletteId = (int)($photo['palette_id'] ?? 0);
        if ($paletteId <= 0) {
            throw new RuntimeException('The inspiration painting has no saved palette attached.');
        }
        $saved = (new SavedPaletteManager($this->pdo))->get($paletteId);
        if (!is_array($saved) || !is_array($saved['palette'] ?? null)) {
            throw new RuntimeException('The inspiration painting points to a missing saved palette.');
        }
        $paletteRecord = $saved['palette'];
        $swatches = (new PaletteService($this->pdo))->getSwatches($paletteId);
        if (!$swatches) {
            throw new RuntimeException('The inspiration painting palette has no displayable colors.');
        }
        $artSlideId = null;
        $artSlideOrder = null;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $slidePhotoId = (int)($item['photo_library_id'] ??
                $item['photo']['photo_library_id'] ?? 0);
            if ($slidePhotoId === $artPhotoId) {
                $artSlideId = (int)($item['playlist_item_id'] ?? 0) ?: null;
                $artSlideOrder = (int)($item['order_index'] ?? 0);
                // The source preparer has already attached the enriched PHOTO snapshot.
                if (is_array($item['photo'] ?? null)) {
                    $photo = $item['photo'];
                }
                break;
            }
        }
        // The original may be linked from every design without having its own slide.
        // In that case retain its PHOTOS image path for the article opening.
        if (trim((string)($photo['image_url'] ?? '')) === '') {
            $photo['image_url'] = (string)($photo['rel_path'] ?? '');
        }
        return [
            'artwork' => [
                ...$artwork,
                'photo_library_id' => $artPhotoId,
                'photo' => $photo,
                'playlist_item_id' => $artSlideId,
                'order_index' => $artSlideOrder,
                'study_url' => ArtworkService::studyUrl($artworkId),
            ],
            'palette' => [
                'palette_id' => $paletteId,
                'name' => (string)($paletteRecord['display_title']
                    ?: $paletteRecord['nickname'] ?: ''),
                'notes' => $paletteRecord['notes'] ?? null,
                'members' => $swatches,
                'member_details' => $saved['members'] ?? [],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function editorialItems(array $source): array
    {
        $items = is_array($source['items'] ?? null) ? $source['items'] : [];
        $items = array_values(array_filter($items, static function (mixed $item): bool {
            return is_array($item)
                && strtolower(trim((string)($item['item_type'] ?? ''))) !== 'brand-bumper'
                && strtolower(trim((string)($item['analyzer_role'] ?? ''))) !== 'ignore';
        }));
        usort($items, static fn (array $a, array $b): int =>
            ((int)($a['order_index'] ?? 0)) <=> ((int)($b['order_index'] ?? 0)));
        return $items;
    }

    /** @return array<string,mixed>|null */
    private function usablePhoto(array $item): ?array
    {
        $photo = $item['photo'] ?? null;
        if (!is_array($photo) || (int)($photo['photo_library_id'] ?? 0) <= 0) {
            return null;
        }
        if (trim((string)($photo['image_url'] ?? '')) === ''
            || trim((string)($photo['file_path'] ?? '')) === '') {
            return null;
        }
        return $photo;
    }
}
