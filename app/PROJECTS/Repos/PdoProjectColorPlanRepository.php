<?php
declare(strict_types=1);

namespace App\PROJECTS\Repos;

use PDO;

final class PdoProjectColorPlanRepository
{
    public function __construct(private PDO $pdo) {}

    public function projectExists(int $projectId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $projectId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function listForProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
               FROM project_color_plans
              WHERE project_id = :project_id
              ORDER BY nickname ASC, area_name ASC, id ASC"
        );
        $stmt->execute([':project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findPlan(int $id, ?int $projectId = null): ?array
    {
        $sql = 'SELECT * FROM project_color_plans WHERE id = :id';
        $params = [':id' => $id];
        if ($projectId !== null) {
            $sql .= ' AND project_id = :project_id';
            $params[':project_id'] = $projectId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createPlan(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO project_color_plans (
                project_id,
                palette_type,
                nickname,
                area_name,
                scheme_title,
                revision_number,
                created_at,
                updated_at
             ) VALUES (
                :project_id,
                :palette_type,
                :nickname,
                :area_name,
                :scheme_title,
                :revision_number,
                NOW(),
                NOW()
             )"
        );
        $stmt->execute([
            ':project_id' => (int)$data['project_id'],
            ':palette_type' => $data['palette_type'] ?? 'exterior',
            ':nickname' => $data['nickname'] ?? null,
            ':area_name' => $data['area_name'] ?? null,
            ':scheme_title' => $data['scheme_title'] ?? null,
            ':revision_number' => (int)($data['revision_number'] ?? 1),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updatePlan(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE project_color_plans
                SET palette_type = :palette_type,
                    nickname = :nickname,
                    area_name = :area_name,
                    scheme_title = :scheme_title,
                    revision_number = :revision_number,
                    issued_at = :issued_at,
                    updated_at = NOW()
              WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $id,
            ':palette_type' => $data['palette_type'] ?? 'exterior',
            ':nickname' => $data['nickname'] ?? null,
            ':area_name' => $data['area_name'] ?? null,
            ':scheme_title' => $data['scheme_title'] ?? null,
            ':revision_number' => (int)($data['revision_number'] ?? 1),
            ':issued_at' => $data['issued_at'] ?? null,
        ]);
    }

    public function deletePlan(int $id, int $projectId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM project_color_plans WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute([
            ':id' => $id,
            ':project_id' => $projectId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function membersForPlan(int $planId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                m.*,
                c.name AS color_name,
                c.code AS color_code,
                c.brand AS color_brand,
                c.brand_name AS color_brand_name,
                c.hex6,
                c.r,
                c.g,
                c.b,
                c.hcl_l
             FROM project_color_plan_members m
             INNER JOIN swatch_view c
               ON c.id = m.color_id
             WHERE m.project_color_plan_id = :id
             ORDER BY m.order_index ASC, m.id ASC"
        );
        $stmt->execute([':id' => $planId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listForProjectWithMembers(int $projectId): array
    {
        $plans = $this->listForProject($projectId);
        if (!$plans) {
            return [];
        }

        $planIds = array_map(static fn(array $plan): int => (int)$plan['id'], $plans);
        $placeholders = implode(',', array_fill(0, count($planIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT
                m.*,
                c.name AS color_name,
                c.code AS color_code,
                c.brand AS color_brand,
                c.brand_name AS color_brand_name,
                c.hex6,
                c.r,
                c.g,
                c.b,
                c.hcl_l
             FROM project_color_plan_members m
             INNER JOIN swatch_view c
               ON c.id = m.color_id
             WHERE m.project_color_plan_id IN ({$placeholders})
             ORDER BY m.project_color_plan_id ASC, m.order_index ASC, m.id ASC"
        );
        $stmt->execute($planIds);

        $membersByPlan = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $member) {
            $membersByPlan[(int)$member['project_color_plan_id']][] = $member;
        }

        $viewerStmt = $this->pdo->prepare(
            "SELECT *
               FROM project_color_plan_viewers
              WHERE viewer_key = 'painter'
                AND project_color_plan_id IN ({$placeholders})
              ORDER BY project_color_plan_id ASC, id ASC"
        );
        $viewerStmt->execute($planIds);

        $viewersByPlan = [];
        $viewerIds = [];
        foreach ($viewerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $viewer) {
            $planId = (int)$viewer['project_color_plan_id'];
            $viewersByPlan[$planId] = [
                'row' => $viewer,
                'photos' => [],
            ];
            $viewerIds[] = (int)$viewer['id'];
        }

        if ($viewerIds) {
            $viewerPlaceholders = implode(',', array_fill(0, count($viewerIds), '?'));
            $photoStmt = $this->pdo->prepare(
                "SELECT p.*,
                        pl.title AS photo_title,
                        pl.updated_at AS photo_updated_at
                   FROM project_color_plan_viewer_photos p
                   LEFT JOIN photo_library pl
                     ON pl.photo_library_id = p.photo_library_id
                  WHERE p.project_color_plan_viewer_id IN ({$viewerPlaceholders})
                  ORDER BY p.project_color_plan_viewer_id ASC, p.order_index ASC, p.id ASC"
            );
            $photoStmt->execute($viewerIds);
            $viewerIdToPlanId = [];
            foreach ($viewersByPlan as $planId => $viewer) {
                $viewerIdToPlanId[(int)$viewer['row']['id']] = $planId;
            }
            foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $photo) {
                $planId = $viewerIdToPlanId[(int)$photo['project_color_plan_viewer_id']] ?? null;
                if ($planId !== null) {
                    $viewersByPlan[$planId]['photos'][] = $photo;
                }
            }
        }

        return array_map(static function (array $plan) use ($membersByPlan, $viewersByPlan): array {
            $plan['members'] = $membersByPlan[(int)$plan['id']] ?? [];
            $plan['painter_viewer'] = $viewersByPlan[(int)$plan['id']] ?? null;
            return $plan;
        }, $plans);
    }

    public function saveMembers(int $planId, array $rows, array $deleteIds): void
    {
        $this->pdo->beginTransaction();
        try {
            if ($deleteIds) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $deleteStmt = $this->pdo->prepare("DELETE FROM project_color_plan_members WHERE project_color_plan_id = ? AND id IN ({$placeholders})");
                $deleteStmt->execute(array_merge([$planId], $deleteIds));
            }

            $colorCheck = $this->pdo->prepare('SELECT id FROM colors WHERE id = :id LIMIT 1');
            $updateStmt = $this->pdo->prepare(
                "UPDATE project_color_plan_members
                    SET color_id = :color_id,
                        role_name = :role_name,
                        sheen = :sheen,
                        note = :note,
                        order_index = :order_index,
                        updated_at = NOW()
                  WHERE id = :id
                    AND project_color_plan_id = :project_color_plan_id"
            );
            $insertStmt = $this->pdo->prepare(
                "INSERT INTO project_color_plan_members (
                    project_color_plan_id,
                    color_id,
                    role_name,
                    sheen,
                    note,
                    order_index,
                    created_at,
                    updated_at
                 ) VALUES (
                    :project_color_plan_id,
                    :color_id,
                    :role_name,
                    :sheen,
                    :note,
                    :order_index,
                    NOW(),
                    NOW()
                 )"
            );

            foreach ($rows as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $colorId = isset($row['color_id']) ? (int)$row['color_id'] : 0;
                if ($colorId <= 0) {
                    throw new \InvalidArgumentException('Color required for every color row');
                }
                $colorCheck->execute([':id' => $colorId]);
                if (!$colorCheck->fetch(PDO::FETCH_ASSOC)) {
                    throw new \InvalidArgumentException('Color not found: ' . $colorId);
                }
                $params = [
                    ':project_color_plan_id' => $planId,
                    ':color_id' => $colorId,
                    ':role_name' => $row['role_name'] ?? null,
                    ':sheen' => $row['sheen'] ?? null,
                    ':note' => $row['note'] ?? null,
                    ':order_index' => isset($row['order_index']) ? (int)$row['order_index'] : $index,
                ];
                $id = isset($row['id']) ? (int)$row['id'] : 0;
                if ($id > 0) {
                    $updateStmt->execute($params + [':id' => $id]);
                } else {
                    $insertStmt->execute($params);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function viewersForPlan(int $planId): array
    {
        $viewers = [];
        $stmt = $this->pdo->prepare(
            "SELECT *
               FROM project_color_plan_viewers
              WHERE project_color_plan_id = :plan_id
              ORDER BY viewer_key ASC"
        );
        $stmt->execute([':plan_id' => $planId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $viewers[(string)$row['viewer_key']] = [
                'row' => $row,
                'photos' => [],
            ];
        }

        if ($viewers) {
            $ids = array_map(static fn(array $viewer): int => (int)$viewer['row']['id'], array_values($viewers));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $photoStmt = $this->pdo->prepare(
                "SELECT p.*,
                        pl.title AS photo_title,
                        pl.updated_at AS photo_updated_at
                   FROM project_color_plan_viewer_photos p
                   LEFT JOIN photo_library pl
                     ON pl.photo_library_id = p.photo_library_id
                  WHERE p.project_color_plan_viewer_id IN ({$placeholders})
                  ORDER BY p.project_color_plan_viewer_id ASC, p.order_index ASC, p.id ASC"
            );
            $photoStmt->execute($ids);
            foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $photo) {
                foreach ($viewers as &$viewer) {
                    if ((int)$viewer['row']['id'] === (int)$photo['project_color_plan_viewer_id']) {
                        $viewer['photos'][] = $photo;
                        break;
                    }
                }
                unset($viewer);
            }
        }

        return $viewers;
    }

    public function saveViewer(int $planId, string $viewerKey, array $form, array $photos): void
    {
        if (!in_array($viewerKey, ['concept', 'client', 'painter'], true)) {
            throw new \InvalidArgumentException('Invalid viewer_key');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO project_color_plan_viewers (
                    project_color_plan_id,
                    viewer_key,
                    concept_title,
                    challenge,
                    design_direction,
                    scheme_title,
                    final_design_description,
                    overall_painter_note,
                    created_at,
                    updated_at
                 ) VALUES (
                    :project_color_plan_id,
                    :viewer_key,
                    :concept_title,
                    :challenge,
                    :design_direction,
                    :scheme_title,
                    :final_design_description,
                    :overall_painter_note,
                    NOW(),
                    NOW()
                 )
                 ON DUPLICATE KEY UPDATE
                    concept_title = VALUES(concept_title),
                    challenge = VALUES(challenge),
                    design_direction = VALUES(design_direction),
                    scheme_title = VALUES(scheme_title),
                    final_design_description = VALUES(final_design_description),
                    overall_painter_note = VALUES(overall_painter_note),
                    updated_at = NOW()"
            );
            $stmt->execute([
                ':project_color_plan_id' => $planId,
                ':viewer_key' => $viewerKey,
                ':concept_title' => $form['title'] ?? $form['concept_title'] ?? null,
                ':challenge' => $form['challenge'] ?? null,
                ':design_direction' => $form['design_direction'] ?? null,
                ':scheme_title' => $form['scheme_title'] ?? null,
                ':final_design_description' => $form['final_design_description'] ?? null,
                ':overall_painter_note' => $form['overall_painter_note'] ?? null,
            ]);

            $viewerStmt = $this->pdo->prepare(
                'SELECT id FROM project_color_plan_viewers WHERE project_color_plan_id = :plan_id AND viewer_key = :viewer_key LIMIT 1'
            );
            $viewerStmt->execute([
                ':plan_id' => $planId,
                ':viewer_key' => $viewerKey,
            ]);
            $viewerId = (int)($viewerStmt->fetchColumn() ?: 0);
            if ($viewerId <= 0) {
                throw new \RuntimeException('Viewer save failed');
            }

            $this->pdo->prepare('DELETE FROM project_color_plan_viewer_photos WHERE project_color_plan_viewer_id = ?')->execute([$viewerId]);
            $photoCheck = $this->pdo->prepare('SELECT photo_library_id, rel_path FROM photo_library WHERE photo_library_id = :id LIMIT 1');
            $insertPhoto = $this->pdo->prepare(
                "INSERT INTO project_color_plan_viewer_photos (
                    project_color_plan_viewer_id,
                    photo_library_id,
                    rel_path,
                    photo_type,
                    order_index,
                    created_at,
                    updated_at
                 ) VALUES (
                    :project_color_plan_viewer_id,
                    :photo_library_id,
                    :rel_path,
                    :photo_type,
                    :order_index,
                    NOW(),
                    NOW()
                 )"
            );

            foreach ($photos as $index => $photo) {
                if (!is_array($photo)) {
                    continue;
                }
                $photoLibraryId = isset($photo['photo_library_id']) && (int)$photo['photo_library_id'] > 0 ? (int)$photo['photo_library_id'] : null;
                $library = null;
                if ($photoLibraryId !== null) {
                    $photoCheck->execute([':id' => $photoLibraryId]);
                    $library = $photoCheck->fetch(PDO::FETCH_ASSOC);
                    if (!$library) {
                        throw new \InvalidArgumentException('Photo not found: ' . $photoLibraryId);
                    }
                }
                $relPath = $photo['rel_path'] ?? $library['rel_path'] ?? null;
                if ($relPath === null || trim((string)$relPath) === '') {
                    throw new \InvalidArgumentException('Photo rel_path required');
                }
                $insertPhoto->execute([
                    ':project_color_plan_viewer_id' => $viewerId,
                    ':photo_library_id' => $photoLibraryId,
                    ':rel_path' => trim((string)$relPath),
                    ':photo_type' => $photo['photo_type'] ?? 'FULL',
                    ':order_index' => isset($photo['order_index']) ? (int)$photo['order_index'] : $index,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
