<?php
declare(strict_types=1);

namespace App\REX\Services;

final class RexTokenGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
