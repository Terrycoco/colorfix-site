<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\DOCUMENTS\Repos\PdoDocumentTemplateRepository;


function normalize_email_template_row(array $row): array
{
    return [
        'id' => isset($row['id']) ? (int)$row['id'] : null,
        'key' => (string)($row['template_key'] ?? $row['key'] ?? ''),
        'label' => (string)($row['label'] ?? ''),
        'description' => isset($row['description']) ? (string)$row['description'] : '',
        'subject' => (string)($row['title_template'] ?? $row['subject'] ?? ''),
        'message' => (string)($row['text_template'] ?? $row['message'] ?? ''),
        'html' => (string)($row['html_template'] ?? $row['html'] ?? ''),
        'is_active' => isset($row['is_active']) ? (int)$row['is_active'] === 1 : true,
        'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : '',
    ];
}


/**
 * @return array<string, mixed>|null
 */
function find_email_template_by_key(
    PdoDocumentTemplateRepository $repo,
    string $key
): ?array {
    $key = trim($key);

    if ($key === '') {
        return null;
    }

    foreach ($repo->listAll() as $row) {
        if (
            (string)($row['template_key'] ?? '') === $key
            && (string)($row['template_type'] ?? '') === 'email'
        ) {
            return $row;
        }
    }

    return null;
}


try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if (!isset($pdo)) {
        throw new RuntimeException('Database unavailable for templates');
    }

    $repo = new PdoDocumentTemplateRepository($pdo);


    /*
     * SAVE
     *
     * Compatibility adapter for the existing email-template UI.
     * The UI still speaks:
     *
     *   key / subject / message / html
     *
     * document_templates stores:
     *
     *   template_key / title_template / text_template / html_template
     */
    if ($method === 'POST') {
        $payload = json_decode(
            file_get_contents('php://input') ?: '',
            true
        );

        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(
                [
                    'ok' => false,
                    'error' => 'Invalid JSON body',
                ],
                JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        $key = trim(
            (string)($payload['key'] ?? '')
        );

        $label = trim(
            (string)($payload['label'] ?? '')
        );

        if ($key === '' || $label === '') {
            http_response_code(400);
            echo json_encode(
                [
                    'ok' => false,
                    'error' => 'key and label required',
                ],
                JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        $existingEmail =
            find_email_template_by_key(
                $repo,
                $key
            );

        /*
         * template_key is globally unique in document_templates.
         * Refuse to overwrite a non-email template with the same key.
         */
        if ($existingEmail === null) {
            foreach ($repo->listAll() as $row) {
                if (
                    (string)($row['template_key'] ?? '') === $key
                    && (string)($row['template_type'] ?? '') !== 'email'
                ) {
                    http_response_code(409);
                    echo json_encode(
                        [
                            'ok' => false,
                            'error' =>
                                "Template key '{$key}' is already used by "
                                . (string)($row['template_type'] ?? 'another')
                                . ' template.',
                        ],
                        JSON_UNESCAPED_SLASHES
                    );
                    exit;
                }
            }
        }

        $saved = $repo->save([
            'id' =>
                $existingEmail['id']
                ?? null,

            'template_key' =>
                $key,

            'template_type' =>
                'email',

            'label' =>
                $label,

            'description' =>
                (string)($payload['description'] ?? ''),

            'title_template' =>
                (string)($payload['subject'] ?? ''),

            'text_template' =>
                (string)($payload['message'] ?? ''),

            'html_template' =>
                (string)($payload['html'] ?? ''),

            'is_active' =>
                array_key_exists('is_active', $payload)
                    ? (int)(bool)$payload['is_active']
                    : 1,
        ]);

        echo json_encode(
            [
                'ok' => true,
                'template' =>
                    normalize_email_template_row(
                        $saved
                    ),
            ],
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(
            [
                'ok' => false,
                'error' => 'Use GET or POST',
            ],
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    /*
     * Preserve the existing file-template fallback for now.
     *
     * Once every legacy file template has been confirmed/migrated into
     * document_templates, this fallback can be removed.
     */
    $baseDir =
        dirname(__DIR__, 3)
        . '/content/email-templates';

    $htmlDir =
        $baseDir . '/html';

    $subjectDir =
        $baseDir . '/subjects';

    $messageDir =
        $baseDir . '/messages';

    $templates = [];


    if (is_dir($htmlDir)) {
        foreach (
            glob(
                $htmlDir . '/*.html'
            ) ?: []
            as $path
        ) {
            $fileKey =
                basename(
                    $path,
                    '.html'
                );

            $label =
                ucwords(
                    str_replace(
                        '-',
                        ' ',
                        $fileKey
                    )
                );

            $subject = '';
            $message = '';

            $subjectPath =
                $subjectDir
                . '/'
                . $fileKey
                . '.txt';

            if (is_file($subjectPath)) {
                $subject =
                    trim(
                        (string)file_get_contents(
                            $subjectPath
                        )
                    );
            }

            $messagePath =
                $messageDir
                . '/'
                . $fileKey
                . '.txt';

            if (is_file($messagePath)) {
                $message =
                    trim(
                        (string)file_get_contents(
                            $messagePath
                        )
                    );
            }

            $html =
                (string)file_get_contents(
                    $path
                );

            $templates[$fileKey] = [
                'key' =>
                    $fileKey,

                'label' =>
                    $label,

                'subject' =>
                    $subject,

                'message' =>
                    $message,

                'html' =>
                    $html,

                'is_active' =>
                    true,

                'updated_at' =>
                    '',
            ];
        }
    }


    /*
     * document_templates is now the database source of truth.
     * Only email rows are exposed through this compatibility endpoint.
     */
    foreach ($repo->listAll() as $row) {
        if (
            (string)($row['template_type'] ?? '')
            !== 'email'
        ) {
            continue;
        }

        $normalized =
            normalize_email_template_row(
                $row
            );

        if ($normalized['key'] === '') {
            continue;
        }

        $templates[
            $normalized['key']
        ] = $normalized;
    }


    $key =
        isset($_GET['key'])
            ? trim(
                (string)$_GET['key']
            )
            : '';

    if ($key !== '') {
        if (isset($templates[$key])) {
            echo json_encode(
                [
                    'ok' => true,
                    'template' =>
                        $templates[$key],
                ],
                JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        http_response_code(404);

        echo json_encode(
            [
                'ok' => false,
                'error' => 'Template not found',
            ],
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    $list =
        array_values(
            $templates
        );

    usort(
        $list,
        static fn(
            array $a,
            array $b
        ): int =>
            strcmp(
                (string)($a['label'] ?? ''),
                (string)($b['label'] ?? '')
            )
    );

    echo json_encode(
        [
            'ok' => true,
            'templates' => $list,
        ],
        JSON_UNESCAPED_SLASHES
    );

} catch (\Throwable $e) {
    http_response_code(500);

    echo json_encode(
        [
            'ok' => false,
            'error' => $e->getMessage(),
        ],
        JSON_UNESCAPED_SLASHES
    );
}
