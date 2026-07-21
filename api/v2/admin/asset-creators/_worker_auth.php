<?php
declare(strict_types=1);

function cf_worker_expected_token(): string
{
    $env = trim((string)(getenv('COLORFIX_WORKER_TOKEN') ?: ''));
    if ($env !== '') {
        return $env;
    }

    $file = dirname(__DIR__, 3) . '/worker-token.php';
    if (is_file($file)) {
        $value = require $file;
        if (is_array($value)) {
            return trim((string)($value['token'] ?? ''));
        }
        return trim((string)$value);
    }

    return '';
}

function cf_require_worker_token(): void
{
    $expected = cf_worker_expected_token();
    if ($expected === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Worker token is not configured.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $provided = trim((string)($_SERVER['HTTP_X_COLORFIX_WORKER_TOKEN'] ?? ''));
    if ($provided === '') {
        $provided = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    }

    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized worker.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
