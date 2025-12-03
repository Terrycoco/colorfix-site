<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Lib\SmtpMailer;
use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoClientRepository;
use App\Repos\PdoPhotoRepository;
use App\Services\EmailTemplateService;
use App\Services\PhotoRenderingService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON payload');
    }

    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    if ($paletteId <= 0) {
        throw new InvalidArgumentException('palette_id required');
    }

    $toName = trim((string)($payload['to_name'] ?? ''));
    $toEmail = trim((string)($payload['to_email'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));
    $subject = trim((string)($payload['subject'] ?? ''));

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Valid to_email required');
    }

    $paletteRepo = new PdoAppliedPaletteRepository($pdo);
    $palette = $paletteRepo->findById($paletteId);
    if (!$palette) {
        throw new RuntimeException('Palette not found');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'colorfix.terrymarr.com';
    $link = $scheme . '://' . $host . '/view/' . $palette->id;

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
    $renderRel = "/photos/rendered/ap_{$palette->id}.jpg";
    $renderAbs = $docRoot . $renderRel;
    $photoRepo = new PdoPhotoRepository($pdo);
    $renderService = new PhotoRenderingService($photoRepo, $pdo);
    if (!is_file($renderAbs)) {
        $cacheInfo = $renderService->cacheAppliedPalette($palette);
        $renderRel = $cacheInfo['render_rel_path'] ?? $renderRel;
    }
    $renderUrl = $scheme . '://' . $host . $renderRel;

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
        'nickname' => $palette->title ?? ('Palette #' . $palette->id),
        'client_name' => $toName,
    ];
    [$finalSubject, $htmlBody, $textBody] = $emailSvc->renderPaletteEmail(
        $paletteMeta,
        array_values($uniqueEntries),
        $link,
        $message !== '' ? $message : null,
        $subject !== '' ? $subject : null,
        $renderUrl
    );

    $projectRoot = dirname(__DIR__, 4);
    $mailConfig = require $projectRoot . '/config/mail.php';
    $mailer = new SmtpMailer($mailConfig);
    $mailer->send($toEmail, $finalSubject, $htmlBody, $textBody);

    $clientRepo = new PdoClientRepository($pdo);
    $clientPayload = $payload['client'] ?? [];
    $clientId = isset($clientPayload['id']) ? (int)$clientPayload['id'] : null;
    $clientName = $toName !== '' ? $toName : trim((string)($clientPayload['name'] ?? ''));
    $clientPhone = trim((string)($clientPayload['phone'] ?? ''));
    if ($clientId) {
        $client = $clientRepo->findById($clientId);
        if (!$client) {
            $clientId = null;
        }
    }
    if (!$clientId) {
        $existing = $clientRepo->findByEmail($toEmail);
        if ($existing) {
            $clientId = (int)$existing['id'];
            $needsUpdate = [];
            if ($clientName !== '' && $clientName !== ($existing['name'] ?? '')) {
                $needsUpdate['name'] = $clientName;
            }
            if ($clientPhone !== '' && $clientPhone !== ($existing['phone'] ?? '')) {
                $needsUpdate['phone'] = $clientPhone;
            }
            if ($needsUpdate) {
                $clientRepo->update($clientId, $needsUpdate);
            }
        } else {
            $clientId = $clientRepo->create([
                'name' => $clientName !== '' ? $clientName : ($paletteMeta['nickname'] ?? 'Client'),
                'email' => $toEmail,
                'phone' => $clientPhone ?: null,
            ]);
        }
    }

    $paletteRepo->recordShare($palette->id, $clientId, [
        'channel' => 'email',
        'target_email' => $toEmail,
        'note' => $message ?: null,
        'share_url' => $link,
    ]);
    $paletteRepo->linkPaletteToClient($clientId, $palette->id, 'shared');

    echo json_encode([
        'ok' => true,
        'share_url' => $link,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
