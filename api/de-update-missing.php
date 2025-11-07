<?php
// scripts/update-de-missing.php
// Fills missing r,g,b,hex6 for Dunn-Edwards colors from /data/de-details.json

// --- show errors so 500s become readable ---
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain');

// ---- require your DB bootstrap ----
// If db.php is NOT in the same directory, uncomment the correct one:
// require_once __DIR__ . '/../db.php';
// require_once __DIR__ . '/../../db.php';
require_once 'db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  throw new RuntimeException("db.php must define \$pdo (PDO).");
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---- helpers ----
function hexToRgbArr(?string $hex): ?array {
  if (!$hex) return null;
  $h = strtoupper(ltrim(trim($hex), '#'));
  if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
  if (!preg_match('/^[0-9A-F]{6}$/', $h)) return null;
  return ['r'=>hexdec(substr($h,0,2)), 'g'=>hexdec(substr($h,2,2)), 'b'=>hexdec(substr($h,4,2)), 'hex6'=>"#$h"];
}
function rgbStrToArr(?string $s): ?array {
  if (!$s) return null;
  if (!preg_match('/^\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*$/', $s, $m)) return null;
  $r = max(0, min(255, (int)$m[1]));
  $g = max(0, min(255, (int)$m[2]));
  $b = max(0, min(255, (int)$m[3]));
  return ['r'=>$r,'g'=>$g,'b'=>$b,'hex6'=>sprintf('#%02X%02X%02X',$r,$g,$b)];
}
function rgbToHex6(int $r, int $g, int $b): string {
  return sprintf('#%02X%02X%02X', max(0,min(255,$r)), max(0,min(255,$g)), max(0,min(255,$b)));
}

// ---- find JSON ----
$paths = [
  '/data/de-details.json',
  __DIR__ . '/../data/de-details.json',
  __DIR__ . '/data/de-details.json',
];
$jsonPath = null;
foreach ($paths as $p) { if (is_file($p)) { $jsonPath = $p; break; } }
if (!$jsonPath) { throw new RuntimeException("Cannot find /data/de-details.json (tried: ".implode(', ', $paths).")"); }
echo "JSON: $jsonPath\n";

$raw = file_get_contents($jsonPath);
if ($raw === false) { throw new RuntimeException("Cannot read $jsonPath (permissions?)"); }
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($data)) { throw new RuntimeException("JSON payload not an array"); }

// ---- brand set (adjust to your canonical brand strings) ----
$brands = ['de','dunn-edwards','dunn edwards','dunnedwards'];
$brandPh = implode(',', array_fill(0, count($brands), '?'));

$sqlSel = "
  SELECT id, r, g, b, hex6
  FROM colors
  WHERE UPPER(code) = ?
    AND LOWER(brand) IN ($brandPh)
  LIMIT 1
";
$sqlUpd = "
  UPDATE colors
  SET
    r    = CASE WHEN r    IS NULL             THEN :r    ELSE r    END,
    g    = CASE WHEN g    IS NULL             THEN :g    ELSE g    END,
    b    = CASE WHEN b    IS NULL             THEN :b    ELSE b    END,
    hex6 = CASE WHEN hex6 IS NULL OR hex6=''  THEN :hex6 ELSE hex6 END
  WHERE id = :id
";

$sel = $pdo->prepare($sqlSel);
$upd = $pdo->prepare($sqlUpd);

$total=0; $matched=0; $updated=0; $nochange=0; $notfound=0; $skipped=0;

$pdo->beginTransaction();
try {
  foreach ($data as $row) {
    $total++;
    $code = strtoupper(trim($row['code'] ?? ''));
    if ($code === '') { $skipped++; continue; }

    $hexA = hexToRgbArr($row['hex'] ?? '');
    $rgbA = rgbStrToArr($row['rgb'] ?? '');
    if (!$hexA && $rgbA) $hexA = ['hex6'=>rgbToHex6($rgbA['r'],$rgbA['g'],$rgbA['b']), 'r'=>$rgbA['r'],'g'=>$rgbA['g'],'b'=>$rgbA['b']];
    if (!$rgbA && $hexA) $rgbA = ['r'=>$hexA['r'],'g'=>$hexA['g'],'b'=>$hexA['b'],'hex6'=>$hexA['hex6']];
    if (!$hexA && !$rgbA) { $skipped++; continue; }

    // lookup
    $params = array_merge([$code], $brands);
    $sel->execute($params);
    $db = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$db) { $notfound++; continue; }
    $matched++;

    $missing = (is_null($db['r']) || is_null($db['g']) || is_null($db['b']) || empty($db['hex6']));
    if (!$missing) { $nochange++; continue; }

    $hex6 = $hexA['hex6'] ?? ($rgbA ? rgbToHex6($rgbA['r'],$rgbA['g'],$rgbA['b']) : null);
    if ($hex6) $hex6 = '#'.strtoupper(ltrim($hex6, '#'));

    $upd->execute([
      ':r'    => $rgbA['r'] ?? null,
      ':g'    => $rgbA['g'] ?? null,
      ':b'    => $rgbA['b'] ?? null,
      ':hex6' => $hex6,
      ':id'   => $db['id'],
    ]);
    if ($upd->rowCount() > 0) $updated++; else $nochange++;
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  throw $e;
}

echo "\n✅ Done\n";
echo "JSON records:   $total\n";
echo "Matched rows:   $matched\n";
echo "Updated rows:   $updated\n";
echo "No change:      $nochange\n";
echo "Not found:      $notfound\n";
echo "Skipped (bad):  $skipped\n";
