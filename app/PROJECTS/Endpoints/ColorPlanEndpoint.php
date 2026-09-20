<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use InvalidArgumentException;
use PDO;

final class ColorPlanEndpoint
{
    private const ACTIONS = [
        'create',
        'delete',
        'get',
        'list',
        'members-save',
        'project-specs',
        'update',
        'viewer-get',
        'viewer-save',
    ];

    public static function handle(PDO $pdo, string $action): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unknown Project Color Plan action.');
        }

        require __DIR__ . '/ColorPlanActions/' . $action . '.php';
    }
}
