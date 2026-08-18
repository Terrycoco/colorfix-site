<?php
declare(strict_types=1);

namespace App\Marketing;

use PDO;
use RuntimeException;

/**
 * MARKETING REPOSITORY
 *
 * Owns all database access for the company-wide Marketing department.
 *
 * Tables:
 *   - marketing_term_groups
 *   - marketing_terms
 *   - marketing_templates
 *   - marketing_template_tags
 *
 * Responsibilities:
 *   - load active term groups and terms
 *   - add new term groups
 *   - add new terms
 *   - load active templates
 *   - add templates
 *   - add/remove template tags
 *
 * Must NOT:
 *   - generate suggestions
 *   - decide which terms apply
 *   - decide which templates a requester should use
 *   - know anything about PUB, Pinterest, YouTube, SEO, etc.
 */
final class PdoMarketingRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function getActiveTermGroupsWithTerms(): array
    {
        $stmt = $this->pdo->query(
            "
            SELECT
                g.marketing_term_group_id,
                g.group_token,
                g.label,
                g.sort_order AS group_sort_order,

                t.marketing_term_id,
                t.term,
                t.singular_term,
                t.plural_term,
                t.sort_order AS term_sort_order

            FROM marketing_term_groups g

            LEFT JOIN marketing_terms t
                ON t.marketing_term_group_id = g.marketing_term_group_id
               AND t.is_active = 1

            WHERE g.is_active = 1

            ORDER BY
                g.sort_order ASC,
                g.marketing_term_group_id ASC,
                t.sort_order ASC,
                t.marketing_term_id ASC
            "
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];

        foreach ($rows as $row) {
            $groupId = (int)$row['marketing_term_group_id'];

            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'marketing_term_group_id' => $groupId,
                    'group_token' => (string)$row['group_token'],
                    'label' =>
                        $row['label'] !== null
                            ? (string)$row['label']
                            : null,
                    'sort_order' =>
                        (int)$row['group_sort_order'],
                    'terms' => [],
                ];
            }

            if ($row['marketing_term_id'] === null) {
                continue;
            }

            $groups[$groupId]['terms'][] = [
                'marketing_term_id' =>
                    (int)$row['marketing_term_id'],

                'term' =>
                    (string)$row['term'],

                'singular_term' =>
                    $row['singular_term'] !== null
                        ? (string)$row['singular_term']
                        : null,

                'plural_term' =>
                    $row['plural_term'] !== null
                        ? (string)$row['plural_term']
                        : null,

                'sort_order' =>
                    (int)$row['term_sort_order'],
            ];
        }

        return array_values($groups);
    }

    public function createTermGroup(
        string $groupToken,
        ?string $label = null,
        int $sortOrder = 0
    ): array {
        $groupToken = trim($groupToken);
        $label = $this->nullableString($label);

        if ($groupToken === '') {
            throw new RuntimeException(
                'group_token required.'
            );
        }

        $stmt = $this->pdo->prepare(
            "
            INSERT INTO marketing_term_groups
            (
                group_token,
                label,
                sort_order,
                is_active
            )
            VALUES
            (
                :group_token,
                :label,
                :sort_order,
                1
            )
            "
        );

        $stmt->execute([
            ':group_token' => $groupToken,
            ':label' => $label,
            ':sort_order' => $sortOrder,
        ]);

        $groupId = (int)$this->pdo->lastInsertId();

        if ($groupId <= 0) {
            throw new RuntimeException(
                'Marketing term group was not created.'
            );
        }

        return [
            'marketing_term_group_id' => $groupId,
            'group_token' => $groupToken,
            'label' => $label,
            'sort_order' => $sortOrder,
            'terms' => [],
        ];
    }

    public function createTerm(
        int $groupId,
        string $term,
        ?string $singularTerm = null,
        ?string $pluralTerm = null,
        int $sortOrder = 0
    ): array {
        if ($groupId <= 0) {
            throw new RuntimeException(
                'Valid marketing term group ID required.'
            );
        }

        $term = trim($term);

        if ($term === '') {
            throw new RuntimeException(
                'term required.'
            );
        }

        $singularTerm = $this->nullableString($singularTerm);
        $pluralTerm = $this->nullableString($pluralTerm);

        $stmt = $this->pdo->prepare(
            "
            INSERT INTO marketing_terms
            (
                marketing_term_group_id,
                term,
                singular_term,
                plural_term,
                sort_order,
                is_active
            )
            VALUES
            (
                :group_id,
                :term,
                :singular_term,
                :plural_term,
                :sort_order,
                1
            )
            "
        );

        $stmt->execute([
            ':group_id' => $groupId,
            ':term' => $term,
            ':singular_term' => $singularTerm,
            ':plural_term' => $pluralTerm,
            ':sort_order' => $sortOrder,
        ]);

        $termId = (int)$this->pdo->lastInsertId();

        if ($termId <= 0) {
            throw new RuntimeException(
                'Marketing term was not created.'
            );
        }

        return [
            'marketing_term_id' => $termId,
            'marketing_term_group_id' => $groupId,
            'term' => $term,
            'singular_term' => $singularTerm,
            'plural_term' => $pluralTerm,
            'sort_order' => $sortOrder,
        ];
    }

    public function getActiveTemplates(
        ?string $deliverableKey = null,
        array $requiredTags = []
    ): array {
        $params = [];

        $sql = "
            SELECT
                mt.marketing_template_id,
                mt.deliverable_key,
                mt.template,
                mt.sort_order

            FROM marketing_templates mt

            WHERE mt.is_active = 1
        ";

        if ($deliverableKey !== null && trim($deliverableKey) !== '') {
            $sql .= "
                AND mt.deliverable_key = :deliverable_key
            ";

            $params[':deliverable_key'] =
                trim($deliverableKey);
        }

        foreach (array_values($requiredTags) as $index => $tag) {
            $tag = trim((string)$tag);

            if ($tag === '') {
                continue;
            }

            $placeholder = ":tag_{$index}";

            $sql .= "
                AND EXISTS (
                    SELECT 1
                    FROM marketing_template_tags mtt
                    WHERE
                        mtt.marketing_template_id =
                            mt.marketing_template_id
                        AND mtt.tag = {$placeholder}
                )
            ";

            $params[$placeholder] = $tag;
        }

        $sql .= "
            ORDER BY
                mt.sort_order ASC,
                mt.marketing_template_id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$templates) {
            return [];
        }

        $templateIds = array_map(
            static fn(array $row): int =>
                (int)$row['marketing_template_id'],
            $templates
        );

        $tagsByTemplateId =
            $this->getTagsByTemplateIds($templateIds);

        return array_map(
            static function (array $row) use ($tagsByTemplateId): array {
                $templateId =
                    (int)$row['marketing_template_id'];

                return [
                    'marketing_template_id' =>
                        $templateId,

                    'deliverable_key' =>
                        (string)$row['deliverable_key'],

                    'template' =>
                        (string)$row['template'],

                    'sort_order' =>
                        (int)$row['sort_order'],

                    'tags' =>
                        $tagsByTemplateId[$templateId] ?? [],
                ];
            },
            $templates
        );
    }

    public function createTemplate(
        string $deliverableKey,
        string $template,
        array $tags = [],
        int $sortOrder = 0
    ): array {
        $deliverableKey = trim($deliverableKey);
        $template = trim($template);

        if ($deliverableKey === '') {
            throw new RuntimeException(
                'deliverable_key required.'
            );
        }

        if ($template === '') {
            throw new RuntimeException(
                'template required.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "
                INSERT INTO marketing_templates
                (
                    deliverable_key,
                    template,
                    sort_order,
                    is_active
                )
                VALUES
                (
                    :deliverable_key,
                    :template,
                    :sort_order,
                    1
                )
                "
            );

            $stmt->execute([
                ':deliverable_key' =>
                    $deliverableKey,

                ':template' =>
                    $template,

                ':sort_order' =>
                    $sortOrder,
            ]);

            $templateId =
                (int)$this->pdo->lastInsertId();

            if ($templateId <= 0) {
                throw new RuntimeException(
                    'Marketing template was not created.'
                );
            }

            $cleanTags = [];

            foreach ($tags as $tag) {
                $tag = trim((string)$tag);

                if ($tag === '') {
                    continue;
                }

                $this->addTemplateTag(
                    $templateId,
                    $tag
                );

                $cleanTags[] = $tag;
            }

            $this->pdo->commit();

            return [
                'marketing_template_id' =>
                    $templateId,

                'deliverable_key' =>
                    $deliverableKey,

                'template' =>
                    $template,

                'sort_order' =>
                    $sortOrder,

                'tags' =>
                    array_values(
                        array_unique($cleanTags)
                    ),
            ];

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function addTemplateTag(
        int $templateId,
        string $tag
    ): void {
        if ($templateId <= 0) {
            throw new RuntimeException(
                'Valid marketing template ID required.'
            );
        }

        $tag = trim($tag);

        if ($tag === '') {
            throw new RuntimeException(
                'tag required.'
            );
        }

        $stmt = $this->pdo->prepare(
            "
            INSERT IGNORE INTO marketing_template_tags
            (
                marketing_template_id,
                tag
            )
            VALUES
            (
                :template_id,
                :tag
            )
            "
        );

        $stmt->execute([
            ':template_id' => $templateId,
            ':tag' => $tag,
        ]);
    }

    public function removeTemplateTag(
        int $templateId,
        string $tag
    ): void {
        $stmt = $this->pdo->prepare(
            "
            DELETE FROM marketing_template_tags
            WHERE
                marketing_template_id = :template_id
                AND tag = :tag
            "
        );

        $stmt->execute([
            ':template_id' => $templateId,
            ':tag' => trim($tag),
        ]);
    }

    private function getTagsByTemplateIds(
        array $templateIds
    ): array {
        $templateIds = array_values(array_filter(
            array_map('intval', $templateIds),
            static fn(int $id): bool => $id > 0
        ));

        if (!$templateIds) {
            return [];
        }

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($templateIds),
                '?'
            )
        );

        $stmt = $this->pdo->prepare(
            "
            SELECT
                marketing_template_id,
                tag
            FROM marketing_template_tags
            WHERE marketing_template_id
                IN ({$placeholders})
            ORDER BY tag ASC
            "
        );

        $stmt->execute($templateIds);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tagsByTemplateId = [];

        foreach ($rows as $row) {
            $templateId =
                (int)$row['marketing_template_id'];

            $tagsByTemplateId[$templateId][] =
                (string)$row['tag'];
        }

        return $tagsByTemplateId;
    }

public function updateTermGroup(
    int $groupId,
    string $label
): array {
    if ($groupId <= 0) {
        throw new RuntimeException(
            'Valid marketing term group ID required.'
        );
    }

    $label = trim($label);

    if ($label === '') {
        throw new RuntimeException(
            'Group name required.'
        );
    }

    $stmt = $this->pdo->prepare(
        "
        UPDATE marketing_term_groups
        SET label = :label
        WHERE marketing_term_group_id = :group_id
        "
    );

    $stmt->execute([
        ':label' => $label,
        ':group_id' => $groupId,
    ]);

    return [
        'marketing_term_group_id' => $groupId,
        'label' => $label,
    ];
}

public function deleteTermGroup(
    int $groupId
): void {
    if ($groupId <= 0) {
        throw new RuntimeException(
            'Valid marketing term group ID required.'
        );
    }

    /*
     * marketing_terms are deleted automatically
     * by the FK ON DELETE CASCADE.
     *
     * Marketing is advisory only, so no historical
     * references need to be preserved.
     */
    $stmt = $this->pdo->prepare(
        "
        DELETE FROM marketing_term_groups
        WHERE marketing_term_group_id = :group_id
        "
    );

    $stmt->execute([
        ':group_id' => $groupId,
    ]);
}

public function updateTerm(
    int $termId,
    string $term,
    ?string $singularTerm,
    ?string $pluralTerm
): array {
    if ($termId <= 0) {
        throw new RuntimeException(
            'Valid marketing term ID required.'
        );
    }

    $term = trim($term);

    if ($term === '') {
        throw new RuntimeException(
            'Term required.'
        );
    }

    $singularTerm =
        $this->nullableString($singularTerm);

    $pluralTerm =
        $this->nullableString($pluralTerm);

    $stmt = $this->pdo->prepare(
        "
        UPDATE marketing_terms
        SET
            term = :term,
            singular_term = :singular_term,
            plural_term = :plural_term
        WHERE marketing_term_id = :term_id
        "
    );

    $stmt->execute([
        ':term' => $term,
        ':singular_term' => $singularTerm,
        ':plural_term' => $pluralTerm,
        ':term_id' => $termId,
    ]);

    return [
        'marketing_term_id' => $termId,
        'term' => $term,
        'singular_term' => $singularTerm,
        'plural_term' => $pluralTerm,
    ];
}

public function deleteTerm(
    int $termId
): void {
    if ($termId <= 0) {
        throw new RuntimeException(
            'Valid marketing term ID required.'
        );
    }

    $stmt = $this->pdo->prepare(
        "
        DELETE FROM marketing_terms
        WHERE marketing_term_id = :term_id
        "
    );

    $stmt->execute([
        ':term_id' => $termId,
    ]);
}

public function updateTemplate(
    int $templateId,
    string $deliverableKey,
    string $template,
    array $tags
): array {
    if ($templateId <= 0) {
        throw new RuntimeException(
            'Valid marketing template ID required.'
        );
    }

    $deliverableKey =
        trim($deliverableKey);

    $template =
        trim($template);

    if ($deliverableKey === '') {
        throw new RuntimeException(
            'deliverable_key required.'
        );
    }

    if ($template === '') {
        throw new RuntimeException(
            'Template required.'
        );
    }

    $this->pdo->beginTransaction();

    try {
        $stmt = $this->pdo->prepare(
            "
            UPDATE marketing_templates
            SET
                deliverable_key = :deliverable_key,
                template = :template
            WHERE marketing_template_id = :template_id
            "
        );

        $stmt->execute([
            ':deliverable_key' => $deliverableKey,
            ':template' => $template,
            ':template_id' => $templateId,
        ]);

        $this->replaceTemplateTags(
            $templateId,
            $tags
        );

        $this->pdo->commit();

        return [
            'marketing_template_id' =>
                $templateId,

            'deliverable_key' =>
                $deliverableKey,

            'template' =>
                $template,

            'tags' =>
                array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                static fn($tag): string =>
                                    trim((string)$tag),
                                $tags
                            )
                        )
                    )
                ),
        ];

    } catch (\Throwable $e) {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        throw $e;
    }
}

public function deleteTemplate(
    int $templateId
): void {
    if ($templateId <= 0) {
        throw new RuntimeException(
            'Valid marketing template ID required.'
        );
    }

    /*
     * marketing_template_tags are deleted automatically
     * by the FK ON DELETE CASCADE.
     */
    $stmt = $this->pdo->prepare(
        "
        DELETE FROM marketing_templates
        WHERE marketing_template_id = :template_id
        "
    );

    $stmt->execute([
        ':template_id' => $templateId,
    ]);
}

private function replaceTemplateTags(
    int $templateId,
    array $tags
): void {
    $delete = $this->pdo->prepare(
        "
        DELETE FROM marketing_template_tags
        WHERE marketing_template_id = :template_id
        "
    );

    $delete->execute([
        ':template_id' => $templateId,
    ]);

    foreach ($tags as $tag) {
        $tag = trim((string)$tag);

        if ($tag === '') {
            continue;
        }

        $this->addTemplateTag(
            $templateId,
            $tag
        );
    }
}



    private function nullableString(
        ?string $value
    ): ?string {
        $value = trim((string)$value);

        return $value !== ''
            ? $value
            : null;
    }




    
}