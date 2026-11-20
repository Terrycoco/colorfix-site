<?php
declare(strict_types=1);

namespace App\PUB\Create\Website\Recipes;

use RuntimeException;

/**
 * Deterministic first draft from ANALYZE's prepared artwork ingredients.
 * Does not fetch content, invoke AI, or persist anything.
 * Editorial refinement instructions belong to ARTICLES/AI/Instructions/Artwork.md.
 */
final class ArtworkRecipe
{
    public const TYPE = 'artwork';

    public function validate(array $ingredients): void
    {
        $artwork = $ingredients['artwork'] ?? null;
        $palette = $ingredients['palette'] ?? null;
        if (!is_array($artwork) || (int)($artwork['photo_library_id'] ?? 0) <= 0) {
            throw new RuntimeException('Artwork recipe requires the inspiration painting and its Photo Library ID.');
        }
        if (!is_array($palette) || (int)($palette['palette_id'] ?? 0) <= 0
            || !is_array($palette['members'] ?? null) || $palette['members'] === []) {
            throw new RuntimeException('Artwork recipe requires the complete saved palette.');
        }
        $slides = $ingredients['slides'] ?? null;
        if (!is_array($slides) || !array_filter($slides, static fn($slide) => is_array($slide)
            && (int)($slide['photo']['photo_library_id'] ?? 0) > 0
            && (int)$slide['photo']['photo_library_id'] !== (int)$artwork['photo_library_id'])) {
            throw new RuntimeException('Artwork recipe requires at least one room-study photograph.');
        }
    }

    /** Return an ARTICLES::saveArticle compatible draft; no invented design conclusions. */
    public function build(array $ingredients): array
    {
        $this->validate($ingredients);
        $artwork = $ingredients['artwork'];
        $palette = $ingredients['palette'];
        $painting = self::first($artwork, ['display_title', 'official_title']) ?: 'the painting';
        $artist = self::first($artwork, ['artist']) ?: 'the artist';
        // Hero: the artwork supplies the inset; the first eligible room in playlist
        // order supplies the main photograph. Neither choice depends on page layout.
        $slides = array_values(array_filter($ingredients['slides'] ?? [], 'is_array'));
        usort($slides, static fn(array $a, array $b): int =>
            ((int)($a['order_index'] ?? 0)) <=> ((int)($b['order_index'] ?? 0)));
        $roomIds = [];
        foreach ($slides as $slide) {
            $photo = is_array($slide['photo'] ?? null) ? $slide['photo'] : [];
            $photoId = (int)($photo['photo_library_id'] ?? 0);
            if ($photoId > 0 && $photoId !== (int)$artwork['photo_library_id']) {
                $roomIds[] = $photoId;
            }
        }
        $roomCount = count($roomIds);
        // Editorial headline is intentionally unfinished for the article editor.
        $title = 'The Color Of';
        $kicker = 'Color Secrets of Master Artists';
        $roomPhrase = $roomCount === 1 ? 'one room study' : $roomCount . ' room studies';
        $dek = 'One painting, one interpreted palette, and ' . $roomPhrase
            . ' exploring how changes in color placement and proportion transform a space.';
        $heroAssetId = $roomIds[0];
        $insetAssetId = (int)$artwork['photo_library_id'];
        $sections = [];
        $order = 0;
        $sections[] = self::section($order++, 'text', null,
            'In this series, I look to the great painters for inspiration, studying their use of color and developing palettes based on their work. Then I take those same colors into a variety of rooms to explore how changing their proportions, placement, and emphasis can produce very different results.');

        $historical = self::first($artwork, ['historical_notes']);
        // Collection/source attribution belongs in the painting caption, not the narrative.
        // Remove only the standalone collection-credit sentence; retain art history.
        $historical = trim((string)preg_replace(
            '~(?:^|(?<=\s))The painting is part of the collection of (?:the )?[^.\n]+\.(?:\s*|$)~iu',
            '',
            $historical
        ));
        $facts = trim(implode('. ', array_filter([
            $artist . ': ' . $painting,
            !empty($artwork['year']) ? (string)$artwork['year'] : '',
            self::first($artwork, ['medium']),
        ])));
        $inspiration = $historical !== '' ? $historical : $facts;
        $section = self::section($order++, 'image_text', 'The Inspiration', $inspiration);
        $section['asset_id'] = (int)$artwork['photo_library_id'];
        $museum = self::first($artwork, ['museum']);
        $year = self::first($artwork, ['year']);
        $section['caption'] = trim(implode(' · ', array_filter([
            trim($artist . ', ' . $painting . ($year !== '' ? ' (' . $year . ')' : '')),
            $museum,
        ])));
        $sections[] = $section;

        // PALETTES owns live swatches. Paragraph 1 introduces the method; remaining
        // paragraphs are the artist-specific analysis displayed BELOW the swatches.
        $method = "The palette isn't intended to match the painting exactly. I worked from a photograph, which can alter the colors, and artists often use many pigments to create what appears to be a single color. Instead, I've chosen paint swatches that capture the overall impression of each color and how they work together.";
        $analysis = self::first($artwork, ['color_analysis']);
        $paletteNotes = self::first($palette, ['notes']);
        $paletteBody = trim(implode("\n\n", array_filter([$method, $analysis, $paletteNotes])));
        $section = self::section($order++, 'palette_link', 'The Palette', $paletteBody);
        $section['palette_id'] = (int)$palette['palette_id'];
        $sections[] = $section;

        $count = 0;
        foreach ($slides as $slide) {
            $photo = is_array($slide['photo'] ?? null) ? $slide['photo'] : [];
            $photoId = (int)($photo['photo_library_id'] ?? 0);
            if ($photoId <= 0 || $photoId === (int)$artwork['photo_library_id']) continue;
            $count++;
            $editorial = is_array($photo['editorial'] ?? null) ? $photo['editorial'] : [];
            $heading = self::first($slide, ['title']) ?: self::first($photo, ['title']) ?: 'Room study ' . $count;
            $lead = self::first($slide, ['subtitle']);
            $commentary = self::first($editorial, ['refined_commentary', 'design_notes']);
            if ($commentary === '') $commentary = self::first($slide, ['body']);
            if ($commentary === '') $commentary = self::first($editorial, ['photo_description']);
            $body = trim(implode("\n\n", array_filter([$lead, $commentary])));
            $section = self::section($order++, 'image_text', $heading, $body);
            $section['asset_id'] = $photoId;
            $section['caption'] = self::first($editorial, ['photo_description']) ?: $heading;
            $sections[] = $section;
        }
        if ($count === 0) throw new RuntimeException('Artwork recipe has no usable room studies.');
        // Leave the conclusion blank when there is no authored cross-room conclusion.
        // The external AI refinement pass can compare the actual photographs.
        $sections[] = self::section($order++, 'text', 'Final Thoughts', '');
        // Links are inserted by ARTICLES when real canonical destinations are available.
        // Do not embed guessed URLs into article prose or write unsupported section fields.
        return [
            'title' => $title,
            'kicker' => $kicker,
            'dek' => $dek,
            'hero_asset_id' => $heroAssetId,
            'hero_inset_asset_id' => $insetAssetId,
            'hero_inset_positions' => [
                'desktop' => 'bottom-right',
                'tablet' => 'bottom-right',
                'phone' => 'bottom-right',
            ],
            'meta_description' => function_exists('mb_substr')
                ? mb_substr($title . '. ' . $dek, 0, 155)
                : substr($title . '. ' . $dek, 0, 155),
            'sections' => $sections,
        ];
    }

    private static function section(int $order, string $kind, ?string $heading, string $body): array
    {
        return ['sort_order' => $order, 'kind' => $kind, 'heading' => $heading,
            'heading_level' => $heading !== null ? 2 : null, 'body' => $body];
    }

    private static function first(array $array, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $array[$key] ?? null;
            if (is_string($value) || is_numeric($value)) {
                $value = trim((string)$value);
                if ($value !== '') return $value;
            }
        }
        return '';
    }
}
