<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_method('GET');

try {
    respond(milestone_list_payload($pdo));
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
