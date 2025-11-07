<?php


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


header('Content-Type: application/json');
require_once 'db.php'; // Your DB connection script

// Simple logging helper
function logMessage($msg) {
    $logFile = __DIR__ . '/upsert_search_preset.log';
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$date] $msg\n", FILE_APPEND);
}

// Log raw input
$input = file_get_contents('php://input');
logMessage("Raw input: $input");

$data = json_decode($input, true);

// Log decoded data
logMessage('Decoded data: ' . print_r($data, true));

if (!$data || !isset($data['id'])) {
    http_response_code(400);
    $msg = 'Invalid input: missing data or id';
    logMessage($msg);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

try {
    $id = $data['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing id']);
        exit;
    }


    if (preg_match('/^new-/', $id)) {
        // Insert new row (strip 'new-' prefix if you want)
        $sql = "INSERT INTO color_search_presets
            (name, category_id, description, hue_min, hue_max, chroma_min, chroma_max, light_min, light_max, type, locked, active, notes, display_order)
            VALUES (:name, :category_id, :description, :hue_min, :hue_max, :chroma_min, :chroma_max, :light_min, :light_max, :type, :locked, :active, :notes, :display_order)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'] ?? null,
            ':category_id' => $data['category_id'] ?? null,
            ':description' => $data['description'] ?? null,
            ':hue_min' => $data['hue_min'] ?? null,
            ':hue_max' => $data['hue_max'] ?? null,
            ':chroma_min' => $data['chroma_min'] ?? null,
            ':chroma_max' => $data['chroma_max'] ?? null,
            ':light_min' => $data['light_min'] ?? null,
            ':light_max' => $data['light_max'] ?? null,
            ':type' => $data['type'] ?? null,
            ':locked' => isset($data['locked']) ? (int)$data['locked'] : 0,
            ':active' => isset($data['active']) ? (int)$data['active'] : 0,
            ':notes' => $data['notes'] ?? null,
            ':display_order' => $data['display_order'] ?? null,
        ]);
        $newId = $pdo->lastInsertId();

        logMessage("Inserted new preset with id $newId");

        echo json_encode(['success' => true, 'id' => $newId]);
        exit;
    } else {
        // Update existing row
        $sql = "UPDATE color_search_presets SET
                name = :name,
                category_id = :category_id,
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
                display_order = :display_order
            WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'] ?? null,
            ':category_id' => $data['category_id'] ?? null,
            ':description' => $data['description'] ?? null,
            ':hue_min' => $data['hue_min'] ?? null,
            ':hue_max' => $data['hue_max'] ?? null,
            ':chroma_min' => $data['chroma_min'] ?? null,
            ':chroma_max' => $data['chroma_max'] ?? null,
            ':light_min' => $data['light_min'] ?? null,
            ':light_max' => $data['light_max'] ?? null,
            ':type' => $data['type'] ?? null,
            ':locked' => isset($data['locked']) ? (int)$data['locked'] : 0,
            ':active' => isset($data['active']) ? (int)$data['active'] : 0,
            ':notes' => $data['notes'] ?? null,
            ':display_order' => $data['display_order'] ?? null,
            ':id' => $id,
        ]);

        logMessage("Updated preset with id $id");
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }


} catch (PDOException $e) {
    $errMsg = 'Database error: ' . $e->getMessage();
    logMessage($errMsg);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $errMsg]);
    exit;
}
