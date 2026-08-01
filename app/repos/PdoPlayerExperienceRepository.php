<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\PlayerExperience;
use PDO;

final class PdoPlayerExperienceRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function getById(int $id): ?PlayerExperience
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                player_experience_id,
                experience_key,
                name,
                slide_flag,
                palette_viewer_key,
                cta_page_id,
                is_active
             FROM player_experiences
             WHERE player_experience_id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new PlayerExperience(
            (int)$row['player_experience_id'],
            (string)$row['experience_key'],
            (string)$row['name'],
            (string)$row['slide_flag'],
            (string)$row['palette_viewer_key'],
            (int)$row['cta_page_id'],
            (bool)$row['is_active']
        );
    }
}
