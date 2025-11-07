<?php
header('Content-Type: application/json');
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    if (isset($data['id']) && $data['id']) {
        // UPDATE existing record
        $sql = "UPDATE color_search_presets SET
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
                    display_order = :display_order
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'] ?? null,
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
            ':id' => $data['id'],
        ]);

        echo json_encode(['success' => true, 'id' => $data['id'], 'action' => 'updated']);
    } else {
        // INSERT new record
        $sql = "INSERT INTO color_search_presets
                (name, description, hue_min, hue_max, chroma_min, chroma_max, light_min, light_max, type, locked, active, notes, display_order)
                VALUES
                (:name, :description, :hue_min, :hue_max, :chroma_min, :chroma_max, :light_min, :light_max, :type, :locked, :active, :notes, :display_order)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'] ?? null,
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

        echo json_encode(['success' => true, 'id' => $newId, 'action' => 'inserted']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
