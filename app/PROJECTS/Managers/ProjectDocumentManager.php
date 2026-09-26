<?php
declare(strict_types=1);

namespace App\PROJECTS\Managers;

use App\DOCUMENTS\Repos\PdoDocumentTemplateRepository;
use App\DOCUMENTS\Services\DocumentMergeService;
use App\PROJECTS\Repos\PdoProjectDocumentRepository;
use App\PROJECTS\Repos\PdoProjectRepository;
use App\PROJECTS\Repos\PdoProjectScopeRepository;
use PDO;
use RuntimeException;

final class ProjectDocumentManager
{
    private PdoProjectRepository $projects;
    private PdoProjectScopeRepository $scopes;
    private PdoProjectDocumentRepository $documents;
    private PdoDocumentTemplateRepository $templates;
    private DocumentMergeService $merge;

    public function __construct(
        private PDO $pdo
    ) {
        $this->projects =
            new PdoProjectRepository(
                $this->pdo
            );

        $this->scopes =
            new PdoProjectScopeRepository(
                $this->pdo
            );

        $this->documents =
            new PdoProjectDocumentRepository(
                $this->pdo
            );

        $this->templates =
            new PdoDocumentTemplateRepository(
                $this->pdo
            );

        $this->merge =
            new DocumentMergeService();
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProjectDocuments(
        int $projectId
    ): array {
        $this->assertProjectExists(
            $projectId
        );

        return
            $this->documents
                ->listByProjectId(
                    $projectId
                );
    }


    /**
     * @return array<string, mixed>
     */
    public function generate(
        array $payload
    ): array {
        $projectId =
            isset(
                $payload['project_id']
            )
                ? (int)$payload['project_id']
                : 0;

        $project =
            $this->assertProjectExists(
                $projectId
            );

        $scopeId =
            isset(
                $payload['scope_id']
            )
                ? (int)$payload['scope_id']
                : 0;

        if ($scopeId > 0) {
            $scope =
                $this->scopes
                    ->findById(
                        $scopeId
                    );

            if ($scope === null) {
                throw new RuntimeException(
                    "Scope {$scopeId} was not found."
                );
            }

            if (
                (int)$scope['project_id']
                !==
                $projectId
            ) {
                throw new RuntimeException(
                    'Scope does not belong to this Project.'
                );
            }
        } else {
            $scope =
                $this->scopes
                    ->findMainByProjectId(
                        $projectId
                    );
        }

        if ($scope === null) {
            throw new RuntimeException(
                'Save the Project Scope before generating a document.'
            );
        }

        $templateKey =
            trim(
                (string)(
                    $payload['template_key']
                    ?? 'scope_agreement'
                )
            );

        if ($templateKey === '') {
            throw new RuntimeException(
                'template_key required.'
            );
        }

        $template =
            $this->templates
                ->findActiveByKey(
                    $templateKey
                );

        if ($template === null) {
            throw new RuntimeException(
                "Active document template '{$templateKey}' was not found."
            );
        }

        $documentType =
            trim(
                (string)(
                    $template['template_type']
                    ?? 'document'
                )
            )
            ?: 'document';

        $plainValues =
            $this->plainMergeValues(
                $project,
                $scope
            );

        $htmlValues =
            $this->htmlMergeValues(
                $project,
                $scope
            );

        $title =
            trim(
                $this->merge
                    ->render(
                        (string)(
                            $template['title_template']
                            ?? ''
                        ),
                        $plainValues
                    )
            );

        if ($title === '') {
            $title =
                (string)(
                    $template['label']
                    ?? 'Document'
                );
        }

        $contentHtml =
            trim(
                $this->merge
                    ->render(
                        (string)(
                            $template['html_template']
                            ?? ''
                        ),
                        $htmlValues
                    )
            );

        if ($contentHtml === '') {
            $text =
                $this->merge
                    ->render(
                        (string)(
                            $template['text_template']
                            ?? ''
                        ),
                        $plainValues
                    );

            $contentHtml =
                '<pre>'
                . htmlspecialchars(
                    $text,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                )
                . '</pre>';
        }

        $scopeId =
            (int)$scope['id'];

        $draft =
            $this->documents
                ->findReusableDraft(
                    $projectId,
                    $scopeId,
                    $templateKey
                );

        if ($draft !== null) {
            $documentId =
                (int)$draft['id'];

            $this->documents
                ->updateDraft(
                    $documentId,
                    $documentType,
                    $title,
                    $contentHtml
                );
        } else {
            $documentId =
                $this->documents
                    ->createDraft(
                        $projectId,
                        $scopeId,
                        $documentType,
                        $templateKey,
                        $title,
                        $contentHtml
                    );
        }

        $document =
            $this->documents
                ->findById(
                    $documentId
                );

        if ($document === null) {
            throw new RuntimeException(
                'Generated document could not be reloaded.'
            );
        }

        return $document;
    }


    /**
     * @return array<string, mixed>
     */
    private function assertProjectExists(
        int $projectId
    ): array {
        if ($projectId <= 0) {
            throw new RuntimeException(
                'Valid project_id required.'
            );
        }

        $project =
            $this->projects
                ->findById(
                    $projectId
                );

        if ($project === null) {
            throw new RuntimeException(
                "Project {$projectId} was not found."
            );
        }

        return $project;
    }


    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $scope
     * @return array<string, string>
     */
    private function plainMergeValues(
        array $project,
        array $scope
    ): array {
        return [
            'client_name' =>
                $this->plain(
                    $project['client_name']
                    ?? ''
                ),

            'project_name' =>
                $this->plain(
                    $project['project_name']
                    ?? ('Project #' . $project['id'])
                ),

            'property_address' =>
                $this->plain(
                    $project['property_address']
                    ?? ''
                ),

            // Compatibility alias for templates that used project_address.
            'project_address' =>
                $this->plain(
                    $project['property_address']
                    ?? ''
                ),

            'project_goal' =>
                $this->plain(
                    $scope['project_goal']
                    ?? ''
                ),

            'areas_covered' =>
                $this->plain(
                    $scope['areas_covered']
                    ?? ''
                ),

            'scope_fee' =>
                $this->formatMoney(
                    $scope['scope_fee']
                    ?? null
                ),
        ];
    }


    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $scope
     * @return array<string, string>
     */
    private function htmlMergeValues(
        array $project,
        array $scope
    ): array {
        return [
            'client_name' =>
                $this->html(
                    $project['client_name']
                    ?? ''
                ),

            'project_name' =>
                $this->html(
                    $project['project_name']
                    ?? ('Project #' . $project['id'])
                ),

            'property_address' =>
                $this->html(
                    $project['property_address']
                    ?? ''
                ),

            // Compatibility alias for templates that used project_address.
            'project_address' =>
                $this->html(
                    $project['property_address']
                    ?? ''
                ),

            'project_goal' =>
                nl2br(
                    $this->html(
                        $scope['project_goal']
                        ?? ''
                    ),
                    false
                ),

            'areas_covered' =>
                $this->areasHtml(
                    $scope['areas_covered']
                    ?? ''
                ),

            'scope_fee' =>
                $this->html(
                    $this->formatMoney(
                        $scope['scope_fee']
                        ?? null
                    )
                ),
        ];
    }


    private function areasHtml(
        mixed $value
    ): string {
        $lines =
            preg_split(
                '/\R+/',
                trim(
                    (string)$value
                )
            )
            ?: [];

        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $items[] =
                '<li>'
                . $this->html($line)
                . '</li>';
        }

        if ($items === []) {
            return '';
        }

        return
            '<ul>'
            . implode('', $items)
            . '</ul>';
    }


    private function formatMoney(
        mixed $value
    ): string {
        if (
            $value === null
            ||
            $value === ''
            ||
            !is_numeric($value)
        ) {
            return '';
        }

        return '$'
            . number_format(
                (float)$value,
                2,
                '.',
                ','
            );
    }


    private function plain(
        mixed $value
    ): string {
        return trim(
            (string)$value
        );
    }


    private function html(
        mixed $value
    ): string {
        return htmlspecialchars(
            trim(
                (string)$value
            ),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
