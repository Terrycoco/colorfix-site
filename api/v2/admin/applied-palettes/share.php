<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPaletteRepository;
use App\Repos\PdoClientRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON');
    }

    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    if ($paletteId <= 0) {
        throw new InvalidArgumentException('palette_id required');
    }

    $clientData = $payload['client'] ?? [];
    $clientId = isset($clientData['id']) ? (int)$clientData['id'] : null;
    $clientName = trim((string)($clientData['name'] ?? ''));
    $clientEmail = trim((string)($clientData['email'] ?? ''));
    $clientPhone = trim((string)($clientData['phone'] ?? ''));
    if ($clientId === null && $clientName === '') {
        throw new InvalidArgumentException('client name required');
    }
    if ($clientPhone === '') {
        throw new InvalidArgumentException('client phone required');
    }

    $repo = new PdoAppliedPaletteRepository($pdo);
    $palette = $repo->findById($paletteId);
    if (!$palette) {
        throw new RuntimeException('Palette not found');
    }

    $clientRepo = new PdoClientRepository($pdo);
    if ($clientId) {
        $client = $clientRepo->findById($clientId);
        if (!$client) throw new RuntimeException('Client not found');
    } else {
        $existingByEmail = $clientEmail ? $clientRepo->findByEmail($clientEmail) : null;
        if ($existingByEmail) {
            $clientId = (int)$existingByEmail['id'];
            $client = $existingByEmail;
        } else {
            $clientId = $clientRepo->create([
                'name' => $clientName,
                'email' => $clientEmail,
                'phone' => $clientPhone,
            ]);
            $client = $clientRepo->findById($clientId);
        }
        $needsUpdate = [];
        if ($clientName !== '' && $clientName !== ($client['name'] ?? '')) {
            $needsUpdate['name'] = $clientName;
        }
        if ($clientEmail !== '' && $clientEmail !== ($client['email'] ?? '')) {
            $needsUpdate['email'] = $clientEmail;
        }
        if ($clientPhone !== '' && $clientPhone !== ($client['phone'] ?? '')) {
            $needsUpdate['phone'] = $clientPhone;
        }
        if ($needsUpdate) {
            $clientRepo->update($clientId, $needsUpdate);
        }
    }

    $shareUrl = '/view/' . $palette->id;
    $note = trim((string)($payload['note'] ?? ''));
    $repo->recordShare($palette->id, $clientId, [
        'channel' => 'sms',
        'target_phone' => $clientPhone,
        'target_email' => $clientEmail ?: null,
        'note' => $note,
        'share_url' => $shareUrl,
    ]);
    $repo->linkPaletteToClient($clientId, $palette->id, 'shared');

    echo json_encode([
        'ok' => true,
        'share_url' => $shareUrl,
        'client_id' => $clientId,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
