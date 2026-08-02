<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\PlayerExperience;
use PDO;

final class PdoPlayerExperienceRepository
{
    private const SLIDE_FLAGS = ['site', 'yt', 'pin', 'prospect', 'client'];
    private const PALETTE_VIEWER_KEYS = ['full_palette', 'concept', 'none'];

    public function __construct(
        private PDO $pdo
    ) {}

    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            "SELECT
                pe.player_experience_id,
                pe.experience_key,
                pe.name,
                pe.slide_flag,
                pe.palette_viewer_key,
                pe.cta_page_id,
                pe.is_active,
                pe.sort_order,
                pe.created_at,
                pe.updated_at,
                cg.`key` AS cta_page_key,
                cg.label AS cta_page_label
             FROM player_experiences pe
             LEFT JOIN cta_groups cg
               ON cg.id = pe.cta_page_id
             ORDER BY pe.sort_order ASC, pe.name ASC, pe.player_experience_id ASC"
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

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

    public function save(array $payload): int
    {
        $id = isset($payload['player_experience_id']) ? (int)$payload['player_experience_id'] : 0;
        $experienceKey = $this->normalizeKey((string)($payload['experience_key'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $slideFlag = strtolower(trim((string)($payload['slide_flag'] ?? '')));
        $paletteViewerKey = strtolower(trim((string)($payload['palette_viewer_key'] ?? '')));
        $ctaPageId = isset($payload['cta_page_id']) ? (int)$payload['cta_page_id'] : 0;
        $isActive = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;
        $sortOrder = isset($payload['sort_order']) ? max(0, (int)$payload['sort_order']) : 0;

        if ($experienceKey === '') {
            throw new \InvalidArgumentException('Experience key required');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('Name required');
        }
        if (!in_array($slideFlag, self::SLIDE_FLAGS, true)) {
            throw new \InvalidArgumentException('Invalid slide flag');
        }
        if (!in_array($paletteViewerKey, self::PALETTE_VIEWER_KEYS, true)) {
            throw new \InvalidArgumentException('Invalid palette viewer key');
        }
        if (!$this->ctaPageExists($ctaPageId)) {
            throw new \InvalidArgumentException('CTA Page required');
        }

        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                "UPDATE player_experiences
                    SET experience_key = :experience_key,
                        name = :name,
                        slide_flag = :slide_flag,
                        palette_viewer_key = :palette_viewer_key,
                        cta_page_id = :cta_page_id,
                        is_active = :is_active,
                        sort_order = :sort_order
                  WHERE player_experience_id = :id"
            );
            $stmt->execute([
                'id' => $id,
                'experience_key' => $experienceKey,
                'name' => $name,
                'slide_flag' => $slideFlag,
                'palette_viewer_key' => $paletteViewerKey,
                'cta_page_id' => $ctaPageId,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO player_experiences
                (experience_key, name, slide_flag, palette_viewer_key, cta_page_id, is_active, sort_order)
             VALUES
                (:experience_key, :name, :slide_flag, :palette_viewer_key, :cta_page_id, :is_active, :sort_order)"
        );
        $stmt->execute([
            'experience_key' => $experienceKey,
            'name' => $name,
            'slide_flag' => $slideFlag,
            'palette_viewer_key' => $paletteViewerKey,
            'cta_page_id' => $ctaPageId,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function ctaPageExists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM cta_groups WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
        return trim($value, '-_');
    }
}
