<?php
declare(strict_types=1);

namespace App\PUB\Create\Website;

use App\ARTICLES\Repos\PdoArticleRepository;
use App\ARTICLES\Services\ArticleService;
use App\PUB\Create\Website\Recipes\ArtworkRecipe;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use PDO;
use RuntimeException;

/**
 * Article Chef: compose an initial editable draft from prepared ingredients.
 * Does not fetch playlists/photos or publish. ARTICLES owns the draft.
 */
final class ArticleCreator implements PubComWorkerContract
{
    private ?PubComChannel $pubCom = null;

    private const RECIPES = [
        'makeover' => ['A makeover shaped by color', 'A look at the decisions behind this transformation.', 'The completed design brings the decisions into focus.'],
        'spotlight' => ['Color worth noticing', 'A closer look at the design choices in these examples.', 'The lesson is in what each example makes visible.'],
        'teaching' => ['Learning to see color', 'Exploring the principles through concrete examples.', 'These examples offer a way to look more deliberately at color.'],
        'themed_makeover' => ['One challenge, several solutions', 'Different responses to a shared color challenge.', 'There is more than one way to solve a color problem.'],
        'palette_ideas' => ['One starting point, different possibilities', 'Exploring several possible color directions for the same starting point.', 'Each alternative changes the character of the design.'],
        'artwork' => ['Art-inspired color study', 'An exploration of a painting’s colors across different spaces.', 'The results depend on where each color is used.'],
        'mistakes_fixes' => ['What works, what does not, and why', 'Examining color problems alongside possible fixes.', 'The useful question is not simply which colors look nice, but why they work together.'],
        'step_by_step_makeover' => ['A transformation in stages', 'Following the decisions that build toward the finished design.', 'Each step contributes to the final result.'],
    ];

    public function __construct(private PDO $pdo) {}

    public function connectPubCom(PubComChannel $channel): void
    {
        $this->pubCom = $channel;
    }

    public function readiness(): PubComSignal
    {
        return PubComSignal::ready('Article Creator is ready.', ['worker' => self::class]);
    }

    public function preflight(array $ingredients): PubComSignal
    {
        $type = trim((string)($ingredients['editorial_type'] ?? ''));
        if (!isset(self::RECIPES[$type]) || (int)($ingredients['playlist_id'] ?? 0) <= 0
            || trim((string)($ingredients['playlist_title'] ?? '')) === '') {
            return PubComSignal::ineligible('article_invalid_ingredients',
                'Article Creator requires a classified playlist ID and title.', ['worker' => self::class]);
        }
        if ($type === ArtworkRecipe::TYPE) {
            try {
                (new ArtworkRecipe())->validate($ingredients);
            } catch (RuntimeException $exception) {
                return PubComSignal::ineligible('article_artwork_incomplete',
                    $exception->getMessage(), ['worker' => self::class]);
            }
        }
        $slides = $ingredients['slides'] ?? null;
        if (!is_array($slides) || !array_filter($slides, static fn($s) => is_array($s)
            && (int)($s['photo']['photo_library_id'] ?? 0) > 0)) {
            return PubComSignal::ineligible('article_missing_photo',
                'Article Creator requires at least one prepared Photo Library image.', ['worker' => self::class]);
        }
        return PubComSignal::ready('Article Creator can prepare this draft.', ['worker' => self::class]);
    }

    public function create(int $pubAssetId, array $ingredients): array
    {
        if ($pubAssetId <= 0) {
            throw new RuntimeException('Article Creator requires a numbered PUB order.');
        }
        $type = (string)($ingredients['editorial_type'] ?? '');
        if (!isset(self::RECIPES[$type])) {
            throw new RuntimeException('Unsupported article editorial type.');
        }
        $playlistId = (int)($ingredients['playlist_id'] ?? 0);
        $playlistTitle = trim((string)($ingredients['playlist_title'] ?? ''));
        $recipe = self::RECIPES[$type];
        $title = $playlistTitle;
        $dek = $recipe[1];
        $draft = [];
        $slug = $this->slug($playlistTitle) . '-playlist-' . $playlistId;
        if ($type === ArtworkRecipe::TYPE) {
            $draft = (new ArtworkRecipe())->build($ingredients);
            $title = $draft['title'];
            $dek = $draft['dek'];
            $sections = $draft['sections'];
        } else {
        $sections = [];
        $n = 0;
        $sections[] = $this->section($n++, 'text', null,
            $recipe[0] . '. ' . $dek . ' The examples below come from the ColorFix by Terry collection, with attention to the choices behind each result.');

        $slides = array_values(array_filter($ingredients['slides'] ?? [], 'is_array'));
        usort($slides, static fn(array $a, array $b) => ((int)($a['order_index'] ?? 0)) <=> ((int)($b['order_index'] ?? 0)));
        $previousRole = '';
        $photoCount = 0;
        foreach ($slides as $slide) {
            $photo = is_array($slide['photo'] ?? null) ? $slide['photo'] : [];
            $photoId = (int)($photo['photo_library_id'] ?? 0);
            $itemType = strtolower(trim((string)($slide['item_type'] ?? '')));
            $heading = trim((string)($slide['title'] ?? ''));
            $subtitle = trim((string)($slide['subtitle'] ?? ''));
            $body = trim((string)($slide['body'] ?? ''));
            $role = strtolower(trim((string)($slide['analyzer_role'] ?? '')));
            if ($photoId <= 0) {
                // Preserve authored explanatory material without inventing an image.
                if ($itemType !== 'intro' && $body !== '') {
                    $sections[] = $this->section($n++, 'text', $heading !== '' ? $heading : null, $body);
                }
                continue;
            }
            $photoCount++;
            $editorial = is_array($photo['editorial'] ?? null) ? $photo['editorial'] : [];
            $description = trim((string)($editorial['photo_description'] ?? ''));
            $designNotes = trim((string)($editorial['design_notes'] ?? ''));
            $refined = trim((string)($editorial['refined_commentary'] ?? ''));
            $photoTitle = trim((string)($photo['title'] ?? ''));
            $heading = $heading !== '' ? $heading : ($photoTitle !== '' ? $photoTitle : 'Design example ' . $photoCount);
            $parts = [];
            if ($subtitle !== '') $parts[] = $subtitle;
            // Refined commentary takes precedence over notes; do not simply repeat both.
            if ($refined !== '') $parts[] = $refined;
            elseif ($designNotes !== '') $parts[] = $designNotes;
            elseif ($body !== '') $parts[] = $body;
            elseif ($description !== '') $parts[] = $description;
            if ($role === 'after' && $previousRole === 'before') {
                array_unshift($parts, 'Here is the proposed response to the preceding starting point.');
            } elseif ($type === 'palette_ideas' && $role === 'after') {
                array_unshift($parts, 'This is one possible treatment of the same starting point, not a completed renovation.');
            } elseif ($type === 'step_by_step_makeover') {
                array_unshift($parts, 'At this stage, the design takes another step.');
            }
            $text = implode("\n\n", array_values(array_unique(array_filter($parts))));
            if ($text === '') $text = 'This example is included in the collection for comparison.';
            $caption = $description !== '' ? $description : $heading;
            $section = $this->section($n++, 'image_text', $heading, $text);
            $section['asset_id'] = $photoId;
            $section['caption'] = $caption;
            $sections[] = $section;
            $previousRole = $role;
        }
        if ($photoCount === 0) throw new RuntimeException('No usable photographs in Article Creator ingredients.');
        $sections[] = $this->section($n++, 'text', 'The color story', $recipe[2]);
        } // End legacy non-artwork recipe branch.
        // CTA IDs/REX links belong to ARTICLES and the existing CTA system.
        // We do not guess CTA IDs or hardcode playlist URLs here.
        // CREATE Manager alone may authorize replacing an existing draft.
        $existingId = (int)($ingredients['existing_article_id'] ?? 0);
        if ($existingId > 0 && ($ingredients['overwrite_authorized'] ?? false) !== true) {
            throw new RuntimeException('Article overwrite not authorized.');
        }
        $service = new ArticleService(new PdoArticleRepository($this->pdo));
        if ($existingId > 0) {
            $current = $service->getArticle($existingId);
            if (!is_array($current) || strtolower((string)($current['status'] ?? '')) !== 'draft') {
                throw new RuntimeException('Only a draft may be overwritten by PUB.');
            }
            // Retain the editor-assigned slug when overwriting this draft.
            $slug = (string)($current['slug'] ?? $slug);
        }
        $id = $service->saveArticle([
            ...($existingId > 0 ? ['id' => $existingId] : []),
            'title' => $title,
            'slug' => $slug,
            'type' => 'colorfix',
            'status' => 'draft',
            'dek' => $dek,
            ...($type === ArtworkRecipe::TYPE ? [
                'kicker' => $draft['kicker'],
                'hero_asset_id' => $draft['hero_asset_id'],
                'hero_inset_asset_id' => $draft['hero_inset_asset_id'],
                'hero_inset_positions' => $draft['hero_inset_positions'],
            ] : []),
            'meta_description' => $draft['meta_description'] ?? mb_substr($title . '. ' . $dek, 0, 155),
            'sections' => $sections,
        ]);
        return ['article_id' => $id, 'title' => $title, 'status' => 'draft',
            'url' => '/articles/' . $id]; // Current article ID route.
    }

    private function section(int $order, string $kind, ?string $heading, string $body): array
    {
        return ['sort_order' => $order, 'kind' => $kind, 'heading' => $heading,
            'heading_level' => $heading !== null ? 2 : null, 'body' => $body];
    }

    private function slug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii ?: $value)), '-'));
        return $slug !== '' ? $slug : 'colorfix-article';
    }
}
