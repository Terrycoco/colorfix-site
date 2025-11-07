<?php
// ✅ MUST be first — no whitespace, no echo, no logs yet!
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// ✅ Handle preflight before DB or output
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';
header('Content-Type: application/json');

// ✅ Logging (safe to file only)
$logFile = __DIR__ . '/delete-filter-error.log';
function logError($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

// ✅ Accept JSON input
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM filter_definitions WHERE id = ?");
    $stmt->execute([$data['id']]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    logError("PDOException: " . $e->getMessage());
    logError("With data: " . json_encode($data));
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Exception $e) {
    logError("Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected error']);
}
