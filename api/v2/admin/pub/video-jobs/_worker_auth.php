<?php
declare(strict_types=1);

/**
 * Authentication for the local PUB video worker.
 *
 * The current worker happens to use Remotion locally,
 * but this endpoint contract is renderer-neutral.
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
 * NOTE:
 * We deliberately continue using the existing
 * render_worker_secret config key so this rename does not
 * require a secrets-file migration.
 */

function pub_require_video_worker(): void
{
    $secretsFile = '/home1/shortgal/colorfix-secrets.php';

    if (!is_file($secretsFile)) {
        pub_video_worker_auth_fail(
            'Video worker secret config missing',
            500
        );
    }

    $secrets = require $secretsFile;

    $expected = trim(
        (string)($secrets['render_worker_secret'] ?? '')
    );

    if ($expected === '') {
        pub_video_worker_auth_fail(
            'Video worker secret not configured',
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

    pub_video_worker_auth_fail(
        'Unauthorized video worker',
        401
    );
}

function pub_video_worker_auth_fail(
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

pub_require_video_worker();
