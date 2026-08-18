<?php
declare(strict_types=1);

namespace App\Marketing;

use RuntimeException;

/**
 * MARKETING SERVICE
 *
 * Company-wide suggestion engine.
 *
 * Marketing does not make final decisions.
 * It receives a request and returns suggestions.
 *
 * Typical request:
 *
 *   "Give me 35 Pinterest search titles using
 *    these selected terms and these templates."
 *
 * Inputs:
 *   - requested count
 *   - selected terms grouped by group_token
 *   - selected templates
 *
 * Output:
 *   - suggestion strings
 *
 * Marketing does NOT:
 *   - mutate caller objects
 *   - persist generated suggestions
 *   - decide which suggestion gets used
 *   - know anything about Create / Package / Queue / Publish
 *
 * The requester remains responsible for accepting,
 * rejecting, editing, or requesting a new batch.
 */
final class MarketingService
{
    public function generateSuggestions(
        int $count,
        array $selectedTermsByGroup,
        array $templates,
        bool $allowDuplicates = false,
    ): array {
        if ($count <= 0) {
            throw new RuntimeException(
                'Suggestion count must be greater than zero.'
            );
        }

        if (!$templates) {
            throw new RuntimeException(
                'At least one marketing template is required.'
            );
        }

        $normalizedTerms =
            $this->normalizeSelectedTerms(
                $selectedTermsByGroup
            );

        if (!$normalizedTerms) {
            throw new RuntimeException(
                'At least one marketing term is required.'
            );
        }

        $candidateSuggestions = [];

        foreach ($templates as $templateRow) {
            $template = trim(
                (string)(
                    $templateRow['template']
                    ?? $templateRow
                    ?? ''
                )
            );

            if ($template === '') {
                continue;
            }

            $expanded = $this->expandTemplate(
                $template,
                $normalizedTerms
            );

            foreach ($expanded as $suggestion) {
                $suggestion =
                    $this->cleanSuggestion(
                        $suggestion
                    );

                if ($suggestion === '') {
                    continue;
                }

                $candidateSuggestions[$suggestion] =
                    $suggestion;
            }
        }

        $candidateSuggestions =
            array_values(
                $candidateSuggestions
            );

        if (!$candidateSuggestions) {
            throw new RuntimeException(
                'Selected terms and templates produced no suggestions.'
            );
        }

        /*
         * Keep output deterministic for now.
         *
         * We can later add controlled rotation/shuffling if
         * repeated batches feel too predictable.
         */
  $results = [];
$candidateCount =
    count($candidateSuggestions);

if ($allowDuplicates) {
    /*
     * Fill the requested count even when Marketing
     * has fewer unique candidates than requested.
     *
     * Duplicates are acceptable for callers such as
     * Pinterest, where separate Pins may legitimately
     * share the same search title.
     */
    for ($i = 0; $i < $count; $i++) {
        $results[] =
            $candidateSuggestions[
                $i % $candidateCount
            ];
    }
} else {
    /*
     * Return only unique suggestions.
     * Never invent duplicates merely to satisfy count.
     */
    $results = array_slice(
        $candidateSuggestions,
        0,
        $count
    );
}

return [
    'requested_count' => $count,
    'allow_duplicates' => $allowDuplicates,
    'unique_candidate_count' =>
        $candidateCount,
    'returned_count' =>
        count($results),
    'suggestions' => $results,
];
    }

    /**
     * Normalize request-selected terms into:
     *
     * [
     *   'group1' => [
     *      [
     *          'term' => 'Cottage',
     *          'singular' => 'Cottage',
     *          'plural' => 'Cottages',
     *      ],
     *   ],
     * ]
     */
    private function normalizeSelectedTerms(
        array $selectedTermsByGroup
    ): array {
        $normalized = [];

        foreach (
            $selectedTermsByGroup
            as $groupToken => $terms
        ) {
            $groupToken =
                trim((string)$groupToken);

            if (
                $groupToken === ''
                || !is_array($terms)
            ) {
                continue;
            }

            foreach ($terms as $termRow) {
                if (is_string($termRow)) {
                    $term = trim($termRow);

                    if ($term === '') {
                        continue;
                    }

                    $normalized[$groupToken][] = [
                        'term' => $term,
                        'singular' => $term,
                        'plural' => $term,
                    ];

                    continue;
                }

                if (!is_array($termRow)) {
                    continue;
                }

                $term = trim(
                    (string)(
                        $termRow['term']
                        ?? ''
                    )
                );

                if ($term === '') {
                    continue;
                }

                $singular = trim(
                    (string)(
                        $termRow['singular_term']
                        ?? $termRow['singular']
                        ?? $term
                    )
                );

                $plural = trim(
                    (string)(
                        $termRow['plural_term']
                        ?? $termRow['plural']
                        ?? $term
                    )
                );

                $normalized[$groupToken][] = [
                    'term' => $term,

                    'singular' =>
                        $singular !== ''
                            ? $singular
                            : $term,

                    'plural' =>
                        $plural !== ''
                            ? $plural
                            : $term,
                ];
            }
        }

        return $normalized;
    }

    /**
     * Supported placeholders:
     *
     *   {group1}
     *   {group1_term}
     *   {group1_singular}
     *   {group1_plural}
     *
     * Same for any group token in the request.
     *
     * Example:
     *
     *   {group3} {group2_plural} for {group1_plural}
     *
     * Marketing generates the Cartesian combinations
     * required by the placeholders used in that template.
     */
    private function expandTemplate(
        string $template,
        array $termsByGroup
    ): array {
            preg_match_all(
                '/\{([a-zA-Z0-9-]+)(?:_(plural_lower|singular_lower|lower|term|singular|plural))?\}/',
                $template,
                $matches,
                PREG_SET_ORDER
            );

        if (!$matches) {
            return [$template];
        }

        $placeholderSpecs = [];

        foreach ($matches as $match) {
            $wholePlaceholder =
                (string)$match[0];

            $groupToken =
                (string)$match[1];

            $form =
                isset($match[2])
                && $match[2] !== ''
                    ? (string)$match[2]
                    : 'term';

            if (!isset($termsByGroup[$groupToken])) {
                /*
                 * Template requires a group that was not
                 * supplied for this request.
                 *
                 * Skip this template entirely.
                 */
                return [];
            }

            $placeholderSpecs[
                $wholePlaceholder
            ] = [
                'group_token' => $groupToken,
                'form' => $form,
            ];
        }

        /*
         * Build one variable axis per unique group/form
         * combination actually used by the template.
         */
        $axes = [];

        foreach (
            $placeholderSpecs
            as $placeholder => $spec
        ) {
            $axisKey =
                $spec['group_token']
                . ':'
                . $spec['form'];

            if (isset($axes[$axisKey])) {
                continue;
            }

            $values = [];

            foreach (
                $termsByGroup[
                    $spec['group_token']
                ]
                as $term
            ) {
                $value = match (
                    $spec['form']
                ) {
                    'singular' =>
                        $term['singular'],

                    'plural' =>
                        $term['plural'],

                    'lower' =>
                        mb_strtolower(
                            (string)$term['term']
                        ),

                    'singular_lower' =>
                        mb_strtolower(
                            (string)$term['singular']
                        ),

                    'plural_lower' =>
                        mb_strtolower(
                            (string)$term['plural']
                        ),

                    default =>
                        $term['term'],
                };

                $value = trim(
                    (string)$value
                );

                if ($value !== '') {
                    $values[] = $value;
                }
            }

            $values = array_values(
                array_unique($values)
            );

            if (!$values) {
                return [];
            }

            $axes[$axisKey] = $values;
        }

        $combinations =
            $this->cartesianProduct(
                $axes
            );

        $results = [];

        foreach ($combinations as $combo) {
            $result = $template;

            foreach (
                $placeholderSpecs
                as $placeholder => $spec
            ) {
                $axisKey =
                    $spec['group_token']
                    . ':'
                    . $spec['form'];

                $replacement =
                    $combo[$axisKey]
                    ?? '';

                $result = str_replace(
                    $placeholder,
                    $replacement,
                    $result
                );
            }

            $results[] = $result;
        }

        return $results;
    }

    private function cartesianProduct(
        array $axes
    ): array {
        $results = [[]];

        foreach ($axes as $axisKey => $values) {
            $next = [];

            foreach ($results as $partial) {
                foreach ($values as $value) {
                    $candidate = $partial;
                    $candidate[$axisKey] = $value;
                    $next[] = $candidate;
                }
            }

            $results = $next;
        }

        return $results;
    }

    private function cleanSuggestion(
        string $value
    ): string {
        $value = preg_replace(
            '/\s+/',
            ' ',
            trim($value)
        ) ?? '';

        /*
         * Remove accidental spacing before punctuation.
         */
        $value = preg_replace(
            '/\s+([,.!?;:])/',
            '$1',
            $value
        ) ?? $value;

        return trim($value);
    }
}