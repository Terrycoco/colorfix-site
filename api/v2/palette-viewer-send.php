<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Lib\SmtpMailer;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\SavedPaletteService;
use App\Services\ShareService;

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
    if ($source !== 'saved') {
        respond(['ok' => false, 'error' => 'source must be saved'], 400);
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

    $shareService = new ShareService(new PdoSavedPaletteRepository($pdo));

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
        $service = new SavedPaletteService($savedRepo, $mailer, null, null, $shareService);
        $service->sendPaletteEmail($paletteId, $toEmail, $message !== '' ? $message : null, $shareUrl ?: null, $subject ?: null);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'source must be saved'], 400);
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
