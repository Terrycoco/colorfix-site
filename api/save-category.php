<?php
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Error logging
$logFile = __DIR__ . '/category-errors.log';
function logError($msg) {
    global $logFile;
    error_log(date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, 3, $logFile);
}

// Parse JSON input
$raw = file_get_contents('php://input');
logError("Raw input: $raw");

$input = json_decode($raw, true);
logError("Decoded JSON: " . json_encode($input));

if (json_last_error() !== JSON_ERROR_NONE) {
    logError("JSON decode error: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit();
}

try {
    // Type-safe input handling
    $id = isset($input['id']) ? intval($input['id']) : null;
    $name = $input['name'] ?? '';
    $description = $input['description'] ?? null;

    $hue_min = isset($input['hue_min']) && $input['hue_min'] !== '' ? floatval($input['hue_min']) : null;
    $hue_max = isset($input['hue_max']) && $input['hue_max'] !== '' ? floatval($input['hue_max']) : null;

    $chroma_min = isset($input['chroma_min']) && $input['chroma_min'] !== '' ? floatval($input['chroma_min']) : null;
    $chroma_max = isset($input['chroma_max']) && $input['chroma_max'] !== '' ? floatval($input['chroma_max']) : null;

    $light_min = isset($input['light_min']) && $input['light_min'] !== '' ? floatval($input['light_min']) : null;
    $light_max = isset($input['light_max']) && $input['light_max'] !== '' ? floatval($input['light_max']) : null;

    $type = $input['type'] ?? null;
    $locked = isset($input['locked']) ? intval($input['locked']) : 0;
    $active = isset($input['active']) ? intval($input['active']) : 1;
    $notes = $input['notes'] ?? null;

    $lrv_min = isset($input['lrv_min']) && $input['lrv_min'] !== '' ? floatval($input['lrv_min']) : null;
    $lrv_max = isset($input['lrv_max']) && $input['lrv_max'] !== '' ? floatval($input['lrv_max']) : null;

    $wheel_text_color = $input['wheel_text_color'] ?? null;
    $calc_only = isset($input['calc_only']) ? intval($input['calc_only']) : 0;

    global $pdo; 

    if ($id) {
        $sql = "UPDATE category_definitions SET
                    name = :name,
                    description = :description,
                    hue_min = :hue_min,
                    hue_max = :hue_max,
                    chroma_min = :chroma_min,
                    chroma_max = :chroma_max,
                    light_min = :light_min,
                    light_max = :light_max,
                    type = :type,
                    locked = :locked,
                    active = :active,
                    notes = :notes,
                    lrv_min = :lrv_min,
                    lrv_max = :lrv_max,
                    wheel_text_color = :wheel_text_color,
                    calc_only = :calc_only
                WHERE id = :id";
    } else {
        $sql = "INSERT INTO category_definitions (
                    name, description, hue_min, hue_max, chroma_min, chroma_max,
                    light_min, light_max, type, locked, active, notes,
                    lrv_min, lrv_max, wheel_text_color, calc_only
                ) VALUES (
                    :name, :description, :hue_min, :hue_max, :chroma_min, :chroma_max,
                    :light_min, :light_max, :type, :locked, :active, :notes,
                    :lrv_min, :lrv_max, :wheel_text_color, :calc_only
                )";
    }

    $stmt = $pdo->prepare($sql);

    $params = [
        ':name' => $name,
        ':description' => $description,
        ':hue_min' => $hue_min,
        ':hue_max' => $hue_max,
        ':chroma_min' => $chroma_min,
        ':chroma_max' => $chroma_max,
        ':light_min' => $light_min,
        ':light_max' => $light_max,
        ':type' => $type,
        ':locked' => $locked,
        ':active' => $active,
        ':notes' => $notes,
        ':lrv_min' => $lrv_min,
        ':lrv_max' => $lrv_max,
        ':wheel_text_color' => $wheel_text_color,
        ':calc_only' => $calc_only
    ];

    if ($id) {
        $params[':id'] = $id;
    }
logError("Prepared to execute with params: " . json_encode($params));
   $success = $stmt->execute($params);
   logError("Statement executed: " . json_encode($success));

   $newId = $id ?: $pdo->lastInsertId();
   logError("Last insert ID: " . $newId);

    $stmt = $pdo->query("SELECT * FROM category_definitions ORDER BY id ASC");
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'categories' => $allRows]);
    } catch (Exception $e) {
    logError("Error saving category: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal Server Error']);
}
