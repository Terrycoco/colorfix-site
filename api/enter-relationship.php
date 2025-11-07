<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');

require_once 'db.php';

function normalize_hex($hex) {
    if ($hex === null) return null;
    $hex = strtoupper(trim($hex));
    if ($hex !== '' && $hex[0] === '#') $hex = substr($hex, 1);
    $hex = preg_replace('/[^0-9A-F]/', '', $hex);
    return (strlen($hex) === 6) ? $hex : null;
}

$color1_id = isset($_POST['color1_id']) ? intval($_POST['color1_id']) : 0;
$color2_id = isset($_POST['color2_id']) ? intval($_POST['color2_id']) : 0;
$source    = isset($_POST['source']) ? trim($_POST['source']) : 'manual';
$notes     = isset($_POST['notes'])  ? trim($_POST['notes'])  : '';

if (!$color1_id || !$color2_id) {
    http_response_code(400);
    echo "Invalid color IDs.";
    exit;
}

try {
    // Fetch hex6 for both IDs
    $stmt = $pdo->prepare("SELECT id, hex6 FROM colors WHERE id IN (?, ?)");
    $stmt->execute([$color1_id, $color2_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) < 2) {
        $found = array_column($rows, 'id');
        $missing = [];
        if (!in_array($color1_id, $found, true)) $missing[] = $color1_id;
        if (!in_array($color2_id, $found, true)) $missing[] = $color2_id;
        http_response_code(400);
        echo "Color ID(s) not found: " . implode(', ', $missing);
        exit;
    }

    // Map to normalized hex
    $hexA = null; $hexB = null;
    foreach ($rows as $r) {
        $hex = normalize_hex($r['hex6']);
        if ((int)$r['id'] === $color1_id) $hexA = $hex;
        if ( (int)$r['id'] === $color2_id) $hexB = $hex;
    }

    if (!$hexA || !$hexB) {
        http_response_code(400);
        $missing = [];
        if (!$hexA) $missing[] = $color1_id;
        if (!$hexB) $missing[] = $color2_id;
        echo "Missing or invalid hex6 for: " . implode(', ', $missing);
        exit;
    }

    // Prevent self-pair (same hex)
    if (strcmp($hexA, $hexB) === 0) {
        http_response_code(400);
        echo "Both colors resolve to the same hex ($hexA). Not inserting a self-pair.";
        exit;
    }

    // Ensure hex1 < hex2
    if (strcmp($hexA, $hexB) < 0) {
        $hex1 = $hexA; $hex2 = $hexB;
    } else {
        $hex1 = $hexB; $hex2 = $hexA;
    }

    // Upsert into color_friends
    // Requires a UNIQUE KEY on (hex1, hex2)
    $sql = "
        INSERT INTO color_friends (hex1, hex2, source, notes)
        VALUES (:hex1, :hex2, :source, :notes)
        ON DUPLICATE KEY UPDATE
            source = VALUES(source),
            notes  = VALUES(notes)
    ";
    $ins = $pdo->prepare($sql);
    $ins->execute([
        ':hex1'   => $hex1,
        ':hex2'   => $hex2,
        ':source' => $source,
        ':notes'  => $notes
    ]);

    $affected = $ins->rowCount(); // 1=insert, 2=update, 0=no change
    if ($affected === 1) {
        echo "Inserted friend pair: $hex1,$hex2";
    } elseif ($affected === 2) {
        echo "Updated friend pair: $hex1,$hex2";
    } else {
        echo "No change (already up to date): $hex1,$hex2";
    }

} catch (Throwable $e) {
    if (function_exists('logError')) {
        logError("enter-friend-pair error: " . $e->getMessage());
    }
    http_response_code(500);
    echo "Server error: " . $e->getMessage();
}
