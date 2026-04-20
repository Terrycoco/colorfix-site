<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Lib\SmtpMailer;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\EmailTemplateService;
use App\Services\ShareService;

function replace_share_placeholders(string $value, string $link, string $title): string
{
    return str_replace(
        ['{{link}}', '{{title}}', '{link}', '{title}'],
        [$link, $title, $link, $title],
        $value
    );
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Use POST with a JSON body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $toEmail = trim((string)($payload['to_email'] ?? ''));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid to_email required'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $assetType = trim((string)($payload['asset_type'] ?? ''));
    $assetId = $payload['asset_id'] ?? 0;
    $assetOptions = is_array($payload['asset_options'] ?? null) ? $payload['asset_options'] : [];
    $shareService = new ShareService(new PdoSavedPaletteRepository($pdo));
    $shareUrl = $shareService->resolveShareUrl(
        $assetType !== '' ? $assetType : 'playlist_instance',
        is_scalar($assetId) ? (string)$assetId : '',
        (string)($payload['share_url'] ?? ''),
        $assetOptions
    );

    $subject = trim((string)($payload['subject'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));
    $htmlBody = trim((string)($payload['html_body'] ?? ''));
    $title = trim((string)($payload['title'] ?? ''));

    $mailConfigPath = dirname(__DIR__, 4) . '/config/mail.php';
    if (!is_file($mailConfigPath)) {
        throw new RuntimeException('Missing mail.php config at ' . $mailConfigPath);
    }
    $mailConfig = require $mailConfigPath;
    $mailer = new SmtpMailer($mailConfig);

    $emailSvc = new EmailTemplateService();
    $finalSubject = replace_share_placeholders($subject, $shareUrl, $title);
    $message = replace_share_placeholders($message, $shareUrl, $title);
    $htmlBody = replace_share_placeholders($htmlBody, $shareUrl, $title);

    if ($htmlBody !== '') {
        [$finalSubject, $htmlBody, $textBody] = $emailSvc->renderShareEmailHtml(
            $finalSubject,
            $htmlBody,
            $message !== '' ? $message : null,
            $shareUrl
        );
    } else {
        [$finalSubject, $htmlBody, $textBody] = $emailSvc->renderShareEmail(
            $finalSubject,
            $message,
            $shareUrl
        );
    }

    $mailer->send($toEmail, $finalSubject, $htmlBody, $textBody);

    echo json_encode(['ok' => true, 'share_url' => $shareUrl], JSON_UNESCAPED_SLASHES);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
