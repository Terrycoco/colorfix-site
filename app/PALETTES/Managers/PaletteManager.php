<?php
declare(strict_types=1);

namespace App\PALETTES\Managers;

use App\PALETTES\Repos\PdoPaletteEditorRepository;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PaletteManager
{
    private PdoPaletteEditorRepository $editor;

    public function __construct(
        private PDO $pdo
    ) {
        $this->editor = new PdoPaletteEditorRepository($pdo);
    }

    public function getEditorItem(int $paletteId): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('Valid palette ID is required.');
        }

        $item = $this->editor->getEditorItem($paletteId);

        if (!$item) {
            throw new RuntimeException(
                "Palette {$paletteId} was not found."
            );
        }

        return $item;
    }

    public function saveEditorItem(array $input): array
    {
        $paletteId = (int)($input['id'] ?? 0);
        $nickname = trim((string)($input['nickname'] ?? ''));

        if ($nickname === '') {
            throw new InvalidArgumentException(
                'Internal Palette Name is required.'
            );
        }

        if (
            $this->editor->nicknameInUse(
                $nickname,
                $paletteId > 0 ? $paletteId : null
            )
        ) {
            throw new DomainException(
                'That Internal Palette Name is already in use.'
            );
        }

        if (
            $paletteId > 0
            && !$this->editor->paletteExists($paletteId)
        ) {
            throw new RuntimeException(
                "Palette {$paletteId} was not found."
            );
        }

        $members = $this->normalizeMembers(
            is_array($input['members'] ?? null)
                ? $input['members']
                : []
        );

        $paletteFields = [
            'nickname' => $nickname,
            'palette_type' => trim(
                (string)($input['palette_type'] ?? 'exterior')
            ) ?: 'exterior',
            'is_public' => (bool)($input['is_public'] ?? true),
            'private_notes' => $this->nullableText(
                $input['private_notes'] ?? null
            ),
        ];

        $pvTitle = $this->nullableText(
            $input['pv_title'] ?? null
        );

        $pvDescription = $this->nullableText(
            $input['pv_description'] ?? null
        );

        $this->pdo->beginTransaction();

        try {
            if ($paletteId > 0) {
                $this->editor->updatePalette(
                    $paletteId,
                    $paletteFields
                );
            } else {
                $paletteId = $this->editor->createPalette(
                    $paletteFields
                );
            }

            $this->editor->replaceMembers(
                $paletteId,
                $members
            );

            $publicPv = $this->editor
                ->findPublicPVByPaletteId($paletteId);

            if ($publicPv) {
                $this->editor->updatePublicPV(
                    (int)$publicPv['palette_viewer_id'],
                    $pvTitle,
                    $pvDescription
                );
            } elseif (
                $pvTitle !== null
                || $pvDescription !== null
            ) {
                $this->editor->createPublicPV(
                    $paletteId,
                    $pvTitle,
                    $pvDescription
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $this->getEditorItem($paletteId);
    }

    private function normalizeMembers(array $members): array
    {
        $normalized = [];

        foreach ($members as $index => $member) {
            if (!is_array($member)) {
                continue;
            }

            $colorId = (int)($member['color_id'] ?? 0);

            if ($colorId <= 0) {
                throw new InvalidArgumentException(
                    'Every palette color must have a valid color ID.'
                );
            }

            $normalized[] = [
                'color_id' => $colorId,
                'role' => $this->nullableText(
                    $member['role'] ?? null
                ),
                'sheen' => $this->nullableText(
                    $member['sheen'] ?? null
                ),
                'note' => $this->nullableText(
                    $member['note'] ?? null
                ),
                'order_index' => $index,
            ];
        }

        return $normalized;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));

        return $text === '' ? null : $text;
    }
}
