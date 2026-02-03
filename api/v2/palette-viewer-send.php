<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Lib\SmtpMailer;
use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\EmailTemplateService;
use App\Services\SavedPaletteService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST required'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $source = strtolower(trim((string)($payload['source'] ?? '')));
    if (!in_array($source, ['applied', 'saved'], true)) {
        respond(['ok' => false, 'error' => 'source must be applied or saved'], 400);
    }

    $toEmail = trim((string)($payload['to_email'] ?? ''));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        respond(['ok' => false, 'error' => 'Valid to_email required'], 400);
    }

    $message = trim((string)($payload['message'] ?? ''));
    $subject = trim((string)($payload['subject'] ?? ''));
    $shareUrl = trim((string)($payload['share_url'] ?? ''));

    $projectRoot = dirname(__DIR__, 2);
    $mailConfigPath = $projectRoot . '/config/mail.php';
    if (!is_file($mailConfigPath)) {
        throw new RuntimeException('Missing mail.php config at ' . $mailConfigPath);
    }
    $mailConfig = require $mailConfigPath;
    $mailer = new SmtpMailer($mailConfig);

    if ($source === 'saved') {
        $paletteId = isset($payload['id']) ? (int)$payload['id'] : 0;
        $hash = trim((string)($payload['hash'] ?? ''));
        $savedRepo = new PdoSavedPaletteRepository($pdo);
        if ($paletteId <= 0 && $hash !== '') {
            $row = $savedRepo->getSavedPaletteByHash($hash);
            $paletteId = (int)($row['id'] ?? 0);
        }
        if ($paletteId <= 0) {
            respond(['ok' => false, 'error' => 'palette id required'], 400);
        }
        $service = new SavedPaletteService($savedRepo, $mailer);
        $service->sendPaletteEmail($paletteId, $toEmail, $message !== '' ? $message : null, $shareUrl ?: null, $subject ?: null);
        respond(['ok' => true]);
    }

    $paletteId = isset($payload['id']) ? (int)$payload['id'] : 0;
    if ($paletteId <= 0) {
        respond(['ok' => false, 'error' => 'palette id required'], 400);
    }

    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    $palette = $paletteRepo->findById($paletteId);
    if (!$palette) {
        respond(['ok' => false, 'error' => 'Palette not found'], 404);
    }

    if ($shareUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'colorfix.terrymarr.com';
        $shareUrl = $scheme . '://' . $host . '/view/' . $palette->id;
    }

    $uniqueEntries = [];
    foreach ($palette->entries as $entry) {
        $key = $entry['color_id'] ?? null;
        if (!$key) {
            $key = ($entry['color_hex6'] ?? '') . '_' . ($entry['color_name'] ?? '');
        }
        if (isset($uniqueEntries[$key])) continue;
        $uniqueEntries[$key] = [
            'color_name' => $entry['color_name'] ?? null,
            'color_code' => $entry['color_code'] ?? null,
            'color_brand' => $entry['color_brand'] ?? null,
            'color_hex6' => $entry['color_hex6'] ?? null,
        ];
    }

    $emailSvc = new EmailTemplateService();
    $paletteMeta = [
        'nickname' => $palette->displayTitle ?? $palette->title ?? ('Palette #' . $palette->id),
    ];
    [$finalSubject, $htmlBody, $textBody] = $emailSvc->renderPaletteEmail(
        $paletteMeta,
        array_values($uniqueEntries),
        $shareUrl,
        $message !== '' ? $message : null,
        $subject !== '' ? $subject : null
    );

    $mailer->send($toEmail, $finalSubject, $htmlBody, $textBody);

    respond(['ok' => true, 'share_url' => $shareUrl]);
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
