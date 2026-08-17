<?php
declare(strict_types=1);

/**
 * Authentication for the local PUB Remotion worker.
 *
 * Worker secret is accepted in the POST payload:
 *
 * JSON requests:
 *   {
 *     "worker_secret": "...",
 *     ...
 *   }
 *
 * Multipart uploads:
 *   worker_secret=...
 *
 * This avoids relying on shared-host handling of custom HTTP headers.
 */

function pub_require_render_worker(): void
{
    $secretsFile = '/home1/shortgal/colorfix-secrets.php';

    if (!is_file($secretsFile)) {
        pub_worker_auth_fail(
            'Render worker secret config missing',
            500
        );
    }

    $secrets = require $secretsFile;

    $expected = trim(
        (string)($secrets['render_worker_secret'] ?? '')
    );

    if ($expected === '') {
        pub_worker_auth_fail(
            'Render worker secret not configured',
            500
        );
    }

    /*
     * Multipart requests, such as upload.php.
     */
    $provided = trim(
        (string)($_POST['worker_secret'] ?? '')
    );

    /*
     * JSON requests, such as claim/rendering/complete.
     */
    if ($provided === '') {
        $raw = file_get_contents('php://input');

        if ($raw !== false && trim($raw) !== '') {
            $payload = json_decode($raw, true);

            if (is_array($payload)) {
                $provided = trim(
                    (string)($payload['worker_secret'] ?? '')
                );
            }
        }
    }

    if (
        $provided !== ''
        && hash_equals($expected, $provided)
    ) {
        return;
    }

    pub_worker_auth_fail(
        'Unauthorized render worker',
        401
    );
}

function pub_worker_auth_fail(
    string $message,
    int $status
): never {
    http_response_code($status);

    if (!headers_sent()) {
        header(
            'Content-Type: application/json; charset=UTF-8'
        );
    }

    echo json_encode([
        'ok' => false,
        'error' => $message,
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

pub_require_render_worker();