<?php
declare(strict_types=1);

namespace App\COLORS\Repos;

use PDO;

/**
 * Compatibility-safe detail retrieval: retain legacy detail enrichment while
 * replacing classification fields with the canonical COLORS materialized view.
 * The legacy repository can be retired separately once its full SELECT is migrated.
 */
final class PdoColorDetailRepository
{
    public function __construct(private PDO $pdo) {}

    public function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $detail = (new \App\Repos\PdoColorDetailRepository($this->pdo))->getById($id);
        if (!$detail) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT hue_cats, neutral_cats, lightness_cats, chroma_cats
               FROM mv_color_categories
              WHERE color_id = :color_id
              LIMIT 1'
        );
        $stmt->execute(['color_id' => $id]);
        $categories = $stmt->fetch(PDO::FETCH_ASSOC);

        // Never fall back to stale classification values from colors.
        $detail['hue_cats'] = $categories['hue_cats'] ?? null;
        $detail['neutral_cats'] = $categories['neutral_cats'] ?? null;
        $detail['lightness_cats'] = $categories['lightness_cats'] ?? null;
        $detail['chroma_cats'] = $categories['chroma_cats'] ?? null;

        // Preserve detail-page legacy singular field names when current values exist.
        // The category description fields still come from the old detail repository.
        if ($categories) {
            $detail['light_cat'] = $categories['lightness_cats'];
            $detail['chroma_cat'] = $categories['chroma_cats'];
        } else {
            $detail['light_cat'] = null;
            $detail['chroma_cat'] = null;
        }

        return $detail;
    }
}
