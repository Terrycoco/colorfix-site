<?php
declare(strict_types=1);

namespace App\Repos;

use App\Entities\PaletteViewer;
use InvalidArgumentException;
use PDO;

final class PdoPaletteViewerRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $paletteViewerId): ?PaletteViewer
    {
        if ($paletteViewerId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM palette_viewers
              WHERE palette_viewer_id = :palette_viewer_id
              LIMIT 1'
        );
        $stmt->execute([':palette_viewer_id' => $paletteViewerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->rowToPaletteViewer($row) : null;
    }

    /**
     * @return PaletteViewer[]
     */
    public function listAll(int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM palette_viewers
              ORDER BY updated_at DESC, palette_viewer_id DESC
              LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn(array $row): PaletteViewer => $this->rowToPaletteViewer($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return PaletteViewer[]
     */
    public function findBySavedPaletteId(int $savedPaletteId): array
    {
        if ($savedPaletteId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM palette_viewers
              WHERE saved_palette_id = :saved_palette_id
              ORDER BY is_active DESC, format ASC, palette_viewer_id ASC'
        );
        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
        ]);

        return array_map(
            fn(array $row): PaletteViewer => $this->rowToPaletteViewer($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param int[] $savedPaletteIds
     * @return array<int, PaletteViewer[]>
     */
    public function findActivePublicBySavedPaletteIds(array $savedPaletteIds): array
    {
        $savedPaletteIds = array_values(array_unique(array_filter(
            array_map('intval', $savedPaletteIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($savedPaletteIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':format' => 'public',
            ':is_active' => 1,
        ];

        foreach ($savedPaletteIds as $index => $savedPaletteId) {
            $placeholder = ':saved_palette_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $savedPaletteId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM palette_viewers
              WHERE is_active = :is_active
                AND format = :format
                AND saved_palette_id IN (' . implode(', ', $placeholders) . ')
              ORDER BY saved_palette_id ASC, palette_viewer_id ASC'
        );
        $stmt->execute($params);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $viewer = $this->rowToPaletteViewer($row);
            $grouped[$viewer->savedPaletteId] ??= [];
            $grouped[$viewer->savedPaletteId][] = $viewer;
        }

        return $grouped;
    }

    public function create(array $data): PaletteViewer
    {
        $savedPaletteId = (int)($data['saved_palette_id'] ?? 0);
        $format = $this->normalizeKey((string)($data['format'] ?? 'public'));

        if ($savedPaletteId <= 0) {
            throw new InvalidArgumentException('saved_palette_id required');
        }
        if ($format === '') {
            throw new InvalidArgumentException('format required');
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO palette_viewers (
                saved_palette_id,
                format,
                template_key,
                kicker_text,
                title,
                intro,
                notes,
                cta_label,
                is_active,
                created_at,
                updated_at
             ) VALUES (
                :saved_palette_id,
                :format,
                :template_key,
                :kicker_text,
                :title,
                :intro,
                :notes,
                :cta_label,
                :is_active,
                NOW(),
                NOW()
             )"
        );
        $stmt->execute([
            ':saved_palette_id' => $savedPaletteId,
            ':format' => $format,
            ':template_key' => $this->nullableKey($data['template_key'] ?? null),
            ':kicker_text' => $this->nullableText($data['kicker_text'] ?? null),
            ':title' => $this->nullableText($data['title'] ?? null),
            ':intro' => $this->nullableText($data['intro'] ?? null),
            ':notes' => $this->nullableText($data['notes'] ?? null),
            ':cta_label' => $this->nullableText($data['cta_label'] ?? null),
            ':is_active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);

        return $this->requireById((int)$this->pdo->lastInsertId());
    }

    public function update(int $paletteViewerId, array $data): PaletteViewer
    {
        if ($paletteViewerId <= 0) {
            throw new InvalidArgumentException('palette_viewer_id required');
        }

        $savedPaletteId = (int)($data['saved_palette_id'] ?? 0);
        $format = $this->normalizeKey((string)($data['format'] ?? ''));

        if ($savedPaletteId <= 0) {
            throw new InvalidArgumentException('saved_palette_id required');
        }
        if ($format === '') {
            throw new InvalidArgumentException('format required');
        }

        $stmt = $this->pdo->prepare(
            "UPDATE palette_viewers
                SET saved_palette_id = :saved_palette_id,
                    format = :format,
                    template_key = :template_key,
                    kicker_text = :kicker_text,
                    title = :title,
                    intro = :intro,
                    notes = :notes,
                    cta_label = :cta_label,
                    is_active = :is_active,
                    updated_at = NOW()
              WHERE palette_viewer_id = :palette_viewer_id"
        );
        $stmt->execute([
            ':palette_viewer_id' => $paletteViewerId,
            ':saved_palette_id' => $savedPaletteId,
            ':format' => $format,
            ':template_key' => $this->nullableKey($data['template_key'] ?? null),
            ':kicker_text' => $this->nullableText($data['kicker_text'] ?? null),
            ':title' => $this->nullableText($data['title'] ?? null),
            ':intro' => $this->nullableText($data['intro'] ?? null),
            ':notes' => $this->nullableText($data['notes'] ?? null),
            ':cta_label' => $this->nullableText($data['cta_label'] ?? null),
            ':is_active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);

        return $this->requireById($paletteViewerId);
    }

    public function deactivate(int $paletteViewerId): bool
    {
        if ($paletteViewerId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE palette_viewers
                SET is_active = 0,
                    updated_at = NOW()
              WHERE palette_viewer_id = :palette_viewer_id'
        );
        $stmt->execute([':palette_viewer_id' => $paletteViewerId]);

        return $stmt->rowCount() > 0;
    }

    private function requireById(int $paletteViewerId): PaletteViewer
    {
        $viewer = $this->findById($paletteViewerId);
        if (!$viewer) {
            throw new InvalidArgumentException('Palette Viewer was not found.');
        }

        return $viewer;
    }

    private function rowToPaletteViewer(array $row): PaletteViewer
    {
        return new PaletteViewer(
            paletteViewerId: (int)$row['palette_viewer_id'],
            savedPaletteId: (int)$row['saved_palette_id'],
            format: (string)$row['format'],
            templateKey: $this->nullableText($row['template_key'] ?? null),
            kickerText: $this->nullableText($row['kicker_text'] ?? null),
            title: $this->nullableText($row['title'] ?? null),
            intro: $this->nullableText($row['intro'] ?? null),
            notes: $this->nullableText($row['notes'] ?? null),
            ctaLabel: $this->nullableText($row['cta_label'] ?? null),
            isActive: (bool)$row['is_active'],
            createdAt: (string)$row['created_at'],
            updatedAt: $this->nullableText($row['updated_at'] ?? null)
        );
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        return trim($value, '_-');
    }

    private function nullableKey(mixed $value): ?string
    {
        $text = $this->normalizeKey((string)($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }
}
