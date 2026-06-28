<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../auth.php';

use App\Repos\PdoPublicationScheduleRepository;
use App\Services\PublicationScheduler;

function scheduler_respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function scheduler_payload(): array {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    return is_array($payload) ? $payload : [];
}

function scheduler_service(PDO $pdo): PublicationScheduler {
    return new PublicationScheduler(new PdoPublicationScheduleRepository($pdo));
}
