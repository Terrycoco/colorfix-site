<?php
declare(strict_types=1);

function cf_require_admin(): void
{
    $isAdmin = (isset($_COOKIE['cf_admin']) && $_COOKIE['cf_admin'] === '1')
        || (isset($_COOKIE['cf_admin_global']) && $_COOKIE['cf_admin_global'] === '1');

    if ($isAdmin) {
        return;
    }

    http_response_code(401);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_SLASHES);
    exit;
}

cf_require_admin();
