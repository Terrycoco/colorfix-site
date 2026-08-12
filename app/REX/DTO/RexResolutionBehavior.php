<?php
declare(strict_types=1);

namespace App\REX\DTO;

enum RexResolutionBehavior: string
{
    case REDIRECT = 'redirect';
    case RENDER = 'render';
}
