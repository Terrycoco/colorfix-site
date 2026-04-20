<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/db.php';

$logFile = __DIR__ . '/get-colors-error.log';
$log = static function (string $msg) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
};
register_shutdown_function(static function () use ($log): void {
    $error = error_get_last();
    if (!$error) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) return;
    $log(
        'Fatal shutdown: '
        . ($error['message'] ?? 'unknown')
        . ' in ' . ($error['file'] ?? 'unknown')
        . ':' . ($error['line'] ?? 0)
    );
});

try {
    $sql = "
        SELECT
            id, name, code, brand, r, g, b,
            hcl_h, hcl_c, hcl_l,
            hue_cats, neutral_cats
        FROM colors
        ORDER BY name ASC
    ";

    $stmt = $pdo->query($sql);
    $colors = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'status' => 'success',
        'data' => $colors,
    ], JSON_UNESCAPED_SLASHES);
} catch (\PDOException $e) {
    $log('PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error',
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    $log('General error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unexpected error',
    ], JSON_UNESCAPED_SLASHES);
}
