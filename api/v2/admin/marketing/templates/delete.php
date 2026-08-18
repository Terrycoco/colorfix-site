<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ . '/../../../../db.php';
require_once __DIR__ . '/../../project-workflow/_helpers.php';
require_once __DIR__ . '/../../auth.php';

use App\Marketing\PdoMarketingRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        workflow_respond(['ok' => false, 'error' => 'POST only'], 405);
    }

    $payload = json_decode(
        (string)file_get_contents('php://input'),
        true
    );

    $templateId = (int)($payload['marketing_template_id'] ?? 0);

    if ($templateId <= 0) {
        throw new InvalidArgumentException('Valid marketing template ID required.');
    }

    $repo = new PdoMarketingRepository($pdo);
    $repo->deleteTemplate($templateId);

    workflow_respond([
        'ok' => true,
        'deleted' => true,
        'marketing_template_id' => $templateId,
    ]);

} catch (InvalidArgumentException | RuntimeException $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    workflow_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}