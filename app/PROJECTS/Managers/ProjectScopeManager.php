<?php
declare(strict_types=1);

namespace App\PROJECTS\Managers;

use App\PROJECTS\Repos\PdoProjectRepository;
use App\PROJECTS\Repos\PdoProjectScopeRepository;
use PDO;
use RuntimeException;

final class ProjectScopeManager
{
    private PdoProjectRepository $projects;
    private PdoProjectScopeRepository $scopes;

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
    }


    /**
     * @return array<string, mixed>|null
     */
    public function getMainScope(
        int $projectId
    ): ?array {
        $this->assertProjectExists(
            $projectId
        );

        return
            $this->scopes
                ->findMainByProjectId(
                    $projectId
                );
    }


    /**
     * @return array<string, mixed>
     */
    public function saveScope(
        array $payload
    ): array {
        $projectId =
            isset(
                $payload['project_id']
            )
                ? (int)$payload['project_id']
                : 0;

        $this->assertProjectExists(
            $projectId
        );

        $scopeId =
            isset(
                $payload['scope_id']
            )
                ? (int)$payload['scope_id']
                : 0;

        $scopeKind =
            self::normalizeScopeKind(
                $payload['scope_kind']
                ?? 'main'
            );

        $title =
            self::nullableString(
                $payload['title']
                ?? null
            )
            ?? (
                $scopeKind === 'main'
                    ? 'Original Scope'
                    : 'Additional Scope'
            );

        $projectGoal =
            self::nullableString(
                $payload['project_goal']
                ?? null
            );

        $areasCovered =
            self::nullableString(
                $payload['areas_covered']
                ?? null
            );

        $scopeFee =
            self::nullableMoney(
                $payload['scope_fee']
                ?? null
            );

        $status =
            self::normalizeStatus(
                $payload['status']
                ?? 'draft'
            );

        if ($scopeId > 0) {
            $existing =
                $this->scopes
                    ->findById(
                        $scopeId
                    );

            if ($existing === null) {
                throw new RuntimeException(
                    "Scope {$scopeId} was not found."
                );
            }

            if (
                (int)$existing['project_id']
                !==
                $projectId
            ) {
                throw new RuntimeException(
                    'Scope does not belong to this Project.'
                );
            }

            $this->scopes
                ->update(
                    $scopeId,
                    $scopeKind,
                    $title,
                    $projectGoal,
                    $areasCovered,
                    $scopeFee,
                    $status
                );

        } else {
            if ($scopeKind === 'main') {
                $existingMain =
                    $this->scopes
                        ->findMainByProjectId(
                            $projectId
                        );

                if ($existingMain !== null) {
                    $scopeId =
                        (int)$existingMain['id'];

                    $this->scopes
                        ->update(
                            $scopeId,
                            $scopeKind,
                            $title,
                            $projectGoal,
                            $areasCovered,
                            $scopeFee,
                            $status
                        );
                }
            }

            if ($scopeId <= 0) {
                $scopeId =
                    $this->scopes
                        ->create(
                            $projectId,
                            $scopeKind,
                            $title,
                            $projectGoal,
                            $areasCovered,
                            $scopeFee,
                            $status
                        );
            }
        }

        $saved =
            $this->scopes
                ->findById(
                    $scopeId
                );

        if ($saved === null) {
            throw new RuntimeException(
                'Saved Scope could not be reloaded.'
            );
        }

        return $saved;
    }


    private function assertProjectExists(
        int $projectId
    ): void {
        if ($projectId <= 0) {
            throw new RuntimeException(
                'Valid project_id required.'
            );
        }

        if (
            $this->projects
                ->findById(
                    $projectId
                ) === null
        ) {
            throw new RuntimeException(
                "Project {$projectId} was not found."
            );
        }
    }


    private static function normalizeScopeKind(
        mixed $value
    ): string {
        $scopeKind =
            strtolower(
                trim(
                    (string)$value
                )
            );

        return match ($scopeKind) {
            'main',
            'addendum' =>
                $scopeKind,

            default =>
                throw new RuntimeException(
                    'Invalid scope_kind.'
                ),
        };
    }


    private static function normalizeStatus(
        mixed $value
    ): string {
        $status =
            strtolower(
                trim(
                    (string)$value
                )
            );

        return match ($status) {
            'draft',
            'active',
            'superseded' =>
                $status,

            default =>
                throw new RuntimeException(
                    'Invalid Scope status.'
                ),
        };
    }


    private static function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $text =
            trim(
                (string)$value
            );

        return
            $text !== ''
                ? $text
                : null;
    }


    private static function nullableMoney(
        mixed $value
    ): ?string {
        if (
            $value === null
            ||
            $value === ''
        ) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new RuntimeException(
                'Scope fee must be numeric.'
            );
        }

        $amount =
            round(
                (float)$value,
                2
            );

        if ($amount < 0) {
            throw new RuntimeException(
                'Scope fee cannot be negative.'
            );
        }

        return
            number_format(
                $amount,
                2,
                '.',
                ''
            );
    }
}
