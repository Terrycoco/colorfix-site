<?php
require_once 'db.php';
header('Content-Type: application/json');

// Pull and normalize all inputs
$hueMin = isset($_GET['hue_min']) ? floatval($_GET['hue_min']) : 0;
$hueMax = isset($_GET['hue_max']) ? floatval($_GET['hue_max']) : 360;

//wrap around logic
if ($hueMin <= $hueMax) {
  $hueClause = "hcl_h BETWEEN :hueMin AND :hueMax";
} else {
  $hueClause = "(hcl_h >= :hueMin OR hcl_h <= :hueMax)";
}

$chromaMin = isset($_GET['chroma_min']) ? floatval($_GET['chroma_min']) : 0;
$chromaMax = isset($_GET['chroma_max']) ? floatval($_GET['chroma_max']) : 100;
$lightMin = isset($_GET['light_min']) ? floatval($_GET['light_min']) : 0;
$lightMax = isset($_GET['light_max']) ? floatval($_GET['light_max']) : 100;

try {
  $sql = "
    SELECT * FROM swatch_view
    WHERE $hueClause
      AND hcl_c BETWEEN :chromaMin AND :chromaMax
      AND hcl_l BETWEEN :lightMin AND :lightMax
    ORDER BY hcl_l ASC, hcl_c DESC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':hueMin' => $hueMin,
    ':hueMax' => $hueMax,
    ':chromaMin' => $chromaMin,
    ':chromaMax' => $chromaMax,
    ':lightMin' => $lightMin,
    ':lightMax' => $lightMax
  ]);

  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
  echo json_encode(['error' => $e->getMessage()]);
}
