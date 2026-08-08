<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';

use App\Repos\PdoUrlReservationRepository;
use App\Repos\PdoUrlReservationResourceRepository;
use App\Services\UrlReservationService;
use App\Services\UrlReservations\UrlReservationRegistryFactory;

function url_reservation_service(PDO $pdo): UrlReservationService
{
    $baseUrl = 'https://colorfix.terrymarr.com';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!in_array(strtolower(preg_replace('/:\d+$/', '', $host) ?? $host), ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            $scheme = 'https';
        }
        $baseUrl = $scheme . '://' . $host;
    }

    return new UrlReservationService(
        new PdoUrlReservationRepository($pdo),
        UrlReservationRegistryFactory::create(new PdoUrlReservationResourceRepository($pdo)),
        $baseUrl
    );
}

function url_reservation_json_body(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        workflow_respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }
    return $data;
}
