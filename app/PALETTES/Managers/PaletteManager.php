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

    public function checkInternalName(string $nickname): array
    {
        $nickname = trim($nickname);

        if ($nickname === '') {
            throw new InvalidArgumentException(
                'Internal Palette Name is required.'
            );
        }

        return [
            'nickname' => $nickname,
            'available' => !$this->editor->nicknameInUse($nickname),
        ];
    }

    public function saveAsNew(array $input): array
    {
        $sourcePaletteId = (int)(
            $input['source_palette_id'] ?? 0
        );

        $newNickname = trim(
            (string)($input['new_nickname'] ?? '')
        );

        if ($sourcePaletteId <= 0) {
            throw new InvalidArgumentException(
                'Valid source Palette ID is required.'
            );
        }

        if (!$this->editor->paletteExists($sourcePaletteId)) {
            throw new RuntimeException(
                "Palette {$sourcePaletteId} was not found."
            );
        }

        if ($newNickname === '') {
            throw new InvalidArgumentException(
                'New Internal Palette Name is required.'
            );
        }

        if ($this->editor->nicknameInUse($newNickname)) {
            throw new DomainException(
                'That Internal Palette Name is already in use.'
            );
        }

        $members = $this->normalizeMembers(
            is_array($input['members'] ?? null)
                ? $input['members']
                : []
        );

        $paletteFields = [
            'nickname' => $newNickname,
            'palette_type' => trim(
                (string)($input['palette_type'] ?? 'exterior')
            ) ?: 'exterior',

            /*
             * A branch starts private intentionally.
             * It can be made public later after review.
             */
            'is_public' => false,

            'private_notes' => $this->nullableText(
                $input['private_notes'] ?? null
            ),
        ];

        $pvKickerText = $this->nullableText(
            $input['pv_kicker_text'] ?? null
        );

        $pvTitle = $this->nullableText(
            $input['pv_title'] ?? null
        );

        $pvDescription = $this->nullableText(
            $input['pv_description'] ?? null
        );

        $copyPhotos = (bool)(
            $input['copy_photos'] ?? true
        );

        $sourcePv = $this->editor
            ->findPublicPVByPaletteId($sourcePaletteId);

        $this->pdo->beginTransaction();

        try {
            $newPaletteId = $this->editor->createPalette(
                $paletteFields
            );

            $this->editor->replaceMembers(
                $newPaletteId,
                $members
            );

            $newPvId = null;

            if ($sourcePv) {
                $newPvId = $this->editor->clonePublicPV(
                    (int)$sourcePv['palette_viewer_id'],
                    $newPaletteId,
                    $pvKickerText,
                    $pvTitle,
                    $pvDescription
                );

                if ($copyPhotos) {
                    $this->editor->copyPVPhotos(
                        (int)$sourcePv['palette_viewer_id'],
                        $newPvId
                    );
                }
            } elseif (
                $pvKickerText !== null
                || $pvTitle !== null
                || $pvDescription !== null
            ) {
                $newPvId = $this->editor->createPublicPV(
                    $newPaletteId,
                    $pvKickerText,
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

        /*
         * Deliberately no REX work here.
         * The new branch is independent and gets its own
         * reservation only when Open PV / publishing needs it.
         */
        return $this->getEditorItem($newPaletteId);
    }

    public function deletePaletteCombo(int $paletteId): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException(
                'Valid Palette ID is required.'
            );
        }

        $palette = $this->editor->getEditorItem($paletteId);

        if (!$palette) {
            throw new RuntimeException(
                "Palette {$paletteId} was not found."
            );
        }

        $pvIds = $this->editor
            ->findPVIdsByPaletteId($paletteId);

        $this->pdo->beginTransaction();

        try {
            /*
             * PV owns:
             * - its REX identity
             * - its Palette <-> Photo presentation relationships
             * - its own lifetime
             *
             * Do not duplicate that deletion logic here.
             *
             * PVManager detects this existing transaction, so a locked REX
             * aborts the entire Palette + PV delete and rolls everything back.
             */
            $pvManager =
                new PVManager(
                    $this->pdo
                );

            foreach ($pvIds as $pvId) {
                $deletedPv =
                    $pvManager->deletePV(
                        (int)$pvId
                    );

                if (!$deletedPv) {
                    throw new RuntimeException(
                        "PV {$pvId} was not found for Palette {$paletteId}."
                    );
                }
            }

            /*
             * Only after every PV has passed its REX deletion gate do we
             * remove the Palette-owned rows.
             */
            $this->editor
                ->deleteMembersForPalette($paletteId);

            $deletedPalette =
                $this->editor->deletePalette($paletteId);

            if ($deletedPalette !== 1) {
                throw new RuntimeException(
                    "Palette {$paletteId} was not deleted."
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return [
            'palette_id' => $paletteId,
            'nickname' => $palette['nickname'] ?? null,
            'deleted_pv_ids' => $pvIds,
            'deleted_pv_count' => count($pvIds),
        ];
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

        $pvKickerText = $this->nullableText(
            $input['pv_kicker_text'] ?? null
        );

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
                    $pvKickerText,
                    $pvTitle,
                    $pvDescription
                );
            } elseif (
                $pvKickerText !== null
                || $pvTitle !== null
                || $pvDescription !== null
            ) {
                $this->editor->createPublicPV(
                    $paletteId,
                    $pvKickerText,
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
