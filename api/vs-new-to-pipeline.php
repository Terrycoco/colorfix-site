<?php
/**
 * import-valspar-details.php
 *
 * Upsert Valspar swatches into `colors` keyed by (brand, name, hex6),
 * then trigger recalc-colors.php for only the hexes we touched so
 * HCL/LAB/HSL are computed and clusters/memberships are created.
 *
 * Usage:
 *   php import-valspar-details.php [path/to/valspar_details.csv]
 *
 * CSV headers (case-insensitive):
 *   id, name, hex6, url, lrv, r, g, b, hex6_detail
 */

declare(strict_types=1);

/* ---------- Config ---------- */
$BRAND        = 'vs';
$DEFAULT_CSV  = __DIR__ . '/data/valspar_details.csv';
$LOG_DIR      = __DIR__ . '/logs';
$LOG_FILE     = $LOG_DIR . '/import_valspar_details.log';
$RECALC_URL   = '/api/recalc-colors.php'; // your central pipeline

/* ---------- Bootstrap ---------- */
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);

function logmsg(string $msg): void {
  global $LOG_FILE;
  $line = '['.date('c')."] $msg\n";
  echo $line;
  error_log($line, 3, $LOG_FILE);
}

try {
  require_once __DIR__.'/db.php'; // provides $pdo
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('db.php did not provide $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  logmsg('FATAL: DB bootstrap failed: '.$e->getMessage());
  exit(1);
}

/* ---------- Input ---------- */
$csvPath = $argv[1] ?? $DEFAULT_CSV;
if (!is_file($csvPath)) {
  logmsg("FATAL: CSV not found: $csvPath");
  exit(1);
}
logmsg("Starting Valspar import from: $csvPath");

/* ---------- Helpers ---------- */
function readCsvAssoc(string $path): array {
  $fh = fopen($path, 'rb');
  if (!$fh) throw new RuntimeException("Cannot open CSV: $path");
  $header = fgetcsv($fh);
  if ($header === false) throw new RuntimeException("Empty CSV: $path");
  $map = [];
  foreach ($header as $i => $h) $map[strtolower(trim((string)$h))] = $i;
  $rows = [];
  while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === 1 && trim((string)$row[0]) === '') continue;
    $assoc = [];
    foreach ($map as $k => $i) $assoc[$k] = $row[$i] ?? '';
    $rows[] = $assoc;
  }
  fclose($fh);
  return [$map, $rows];
}
function normalizeName($s): string {
  $s = trim((string)$s);
  return preg_replace('/\s+/u', ' ', $s);
}
function normalizeInt($v): ?int {
  if ($v === '' || $v === null) return null;
  $n = (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT);
  return ($n < 0 || $n > 255) ? null : $n;
}
function normalizeLrv($v): ?float {
  if ($v === '' || $v === null) return null;
  $s = trim((string)$v);
  if (preg_match('/([0-9]{1,2}(?:\.[0-9]+)?)/', $s, $m)) return (float)$m[1];
  return null;
}
function rgbFromHex(string $hex6): array {
  $hex6 = strtoupper($hex6);
  return [hexdec(substr($hex6,0,2)), hexdec(substr($hex6,2,2)), hexdec(substr($hex6,4,2))];
}
function hexFromRgb(int $r, int $g, int $b): string {
  $r = max(0, min(255,$r)); $g = max(0, min(255,$g)); $b = max(0, min(255,$b));
  return strtoupper(sprintf('%02X%02X%02X', $r,$g,$b));
}
function hostBaseUrl(): ?string {
  if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme.'://'.$_SERVER['HTTP_HOST'];
  }
  return null;
}
function triggerRecalc(array $hexes): void {
  global $RECALC_URL;
  if (!$hexes) return;
  $chunks = array_chunk(array_values(array_unique($hexes)), 400);
  $base = hostBaseUrl();
  foreach ($chunks as $chunk) {
    $param = implode(',', $chunk);
    if ($base) {
      $url = rtrim($base,'/').$RECALC_URL.'?hexes='.urlencode($param);
      @file_get_contents($url);
      logmsg("Triggered HTTP recalc for ".count($chunk)." hexes");
    } else {
      // If running via CLI without web server vars, you can adapt recalc-colors.php
      // to accept CLI args; otherwise call it via curl here.
    }
  }
}

/* ---------- Statements (composite key) ---------- */
$exists = $pdo->prepare("
  SELECT id
  FROM colors
  WHERE brand=:brand AND name=:name AND hex6=:hex6
  LIMIT 1
");

$insert = $pdo->prepare("
  INSERT INTO colors (name, code, brand, color_url, hex6, r, g, b, lrv)
  VALUES (:name, :code, :brand,  :color_url, :hex6, :r, :g, :b, :lrv)
");

/*  IMPORTANT:
    We do NOT change `hex6` in UPDATE because (brand,name,hex6) is the identity.
    If a row comes in with same brand+name but *different* hex, we INSERT a new row.
*/
$update = $pdo->prepare("
  UPDATE colors SET
    code        = COALESCE(NULLIF(:code,''), code),
    color_url   = COALESCE(NULLIF(:color_url,''), color_url),
    r           = COALESCE(:r, r),
    g           = COALESCE(:g, g),
    b           = COALESCE(:b, b),
    lrv         = COALESCE(:lrv, lrv)
  WHERE brand=:brand AND name=:name AND hex6=:hex6
");

/* ---------- CSV ingest ---------- */
[$hdr, $rows] = readCsvAssoc($csvPath);
$need = ['id','name','hex6','url','lrv','r','g','b','hex6_detail'];
foreach ($need as $k) {
  if (!array_key_exists($k, $hdr)) {
    logmsg("FATAL: Missing required CSV column: $k");
    exit(1);
  }
}

$total=0; $inserted=0; $updatedN=0; $skipped=0;
$touched = []; // hexes we actually inserted/updated

$pdo->beginTransaction();
try {
  foreach ($rows as $row) {
    $total++;

    $name  = normalizeName($row['name'] ?? '');
    if ($name === '') { $skipped++; continue; }

    $code  = trim((string)($row['id'] ?? ''));    // site id; optional
    $url   = trim((string)($row['url'] ?? ''));
    $hex6  = strtoupper(trim((string)($row['hex6'] ?? '')));
    $hex6d = strtoupper(trim((string)($row['hex6_detail'] ?? '')));

    if ($hex6 === '' && $hex6d !== '') $hex6 = $hex6d;
    if ($hex6 !== '' && !preg_match('/^[0-9A-F]{6}$/', $hex6)) $hex6 = '';

    $r = normalizeInt($row['r'] ?? null);
    $g = normalizeInt($row['g'] ?? null);
    $b = normalizeInt($row['b'] ?? null);
    $lrv = normalizeLrv($row['lrv'] ?? null);

    // Backfill RGB from hex; or hex from RGB
    if ($hex6 && ($r===null || $g===null || $b===null)) {
      [$r,$g,$b] = rgbFromHex($hex6);
    } elseif (!$hex6 && $r!==null && $g!==null && $b!==null) {
      $hex6 = hexFromRgb($r,$g,$b);
    }

    // INSERT path requires hex6 (NOT NULL)
    if ($hex6 === '') { $skipped++; continue; }

    $params = [
      ':name'        => $name,
      ':code'        => ($code !== '' ? $code : null),
      ':brand'       => $BRAND,
      ':color_url'   => ($url !== '' ? $url : null),
      ':hex6'        => $hex6,
      ':r'           => $r,
      ':g'           => $g,
      ':b'           => $b,
      ':lrv'         => $lrv,
    ];

    // Exists by (brand, name, hex6)
    $exists->execute([':brand'=>$BRAND, ':name'=>$name, ':hex6'=>$hex6]);
    $rowId = $exists->fetchColumn();

    if ($rowId) {
      $update->execute($params);
      $touched[$hex6] = true;
      $updatedN++;
    } else {
      $insert->execute($params);
      $touched[$hex6] = true;
      $inserted++;
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  logmsg('FATAL: Transaction failed at row '.$total.': '.$e->getMessage());
  exit(1);
}

logmsg("✅ Valspar import complete. total=$total inserted=$inserted updated=$updatedN skipped=$skipped");

/* ---------- Trigger scoped recalc for just the touched hexes ---------- */
if (!empty($touched)) {
  triggerRecalc(array_keys($touched));   // this calls /api/recalc-colors.php?hexes=...
} else {
  logmsg("Nothing to recalc (no rows inserted/updated).");
}

exit(0);
