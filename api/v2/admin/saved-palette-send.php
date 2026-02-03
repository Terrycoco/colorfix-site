<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Controllers\SavedPaletteController;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\SavedPaletteService;
use App\Lib\SmtpMailer;

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

    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    if ($paletteId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'palette_id required'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $toEmail = trim((string)($payload['to_email'] ?? ''));
    if ($toEmail === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'to_email required'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $config = require __DIR__ . '/../../config.php';
    $mailConfigPath = dirname(__DIR__, 3) . '/config/mail.php';
    if (!is_file($mailConfigPath)) {
        throw new RuntimeException('Missing mail.php config at ' . $mailConfigPath);
    }
    $mailConfig = require $mailConfigPath;
    $mailer = new SmtpMailer($mailConfig);

    $paletteRepo = new PdoSavedPaletteRepository($pdo);
    $service     = new SavedPaletteService($paletteRepo, $mailer);
    $controller  = new SavedPaletteController($service);

    $message = isset($payload['message']) ? (string)$payload['message'] : null;
    $shareUrl = isset($payload['share_url']) ? (string)$payload['share_url'] : null;
    $subjectOverride = isset($payload['subject']) ? (string)$payload['subject'] : null;

    $controller->sendEmail($paletteId, $toEmail, $message, $shareUrl, $subjectOverride);

    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
