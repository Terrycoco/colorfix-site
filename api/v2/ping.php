<?php
// Minimal JSON-only script to confirm PHP executes in /api/v2
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => true, 'where' => '/api/v2/ping.php']);