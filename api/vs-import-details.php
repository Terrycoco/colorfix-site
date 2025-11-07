<?php
/**
 * Import Valspar details into `colors`, keyed by (brand, name).
 * - brand short code: 'vs'
 * - code is stored but NOT part of uniqueness
 * - UPDATE if exists (brand,name), INSERT otherwise
 * - Never overwrite fields with blanks (COALESCE/NULLIF)
 * - INSERT requires hex6 (column is NOT NULL in your DB)
 */

declare(strict_types=1);

// ---------- Config ----------
$BRAND = 'vs';
$DEFAULT_INPUT = __DIR__ . '/data/valspar_details.csv';
$LOG_DIR = __DIR__ . '/logs';
$LOG_FILE = $LOG_DIR . '/import_valspar_details.log';

// ---------- Bootstrap ----------
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0775, true);

function logmsg(string $msg) {
  global $LOG_FILE;
  $line = sprintf("[%s] %s\n", date('c'), $msg);
  echo $line;
  error_log($line, 3, $LOG_FILE);
}

try {
  require_once 'db.php'; // must set $pdo
  if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('db.php did not provide a valid $pdo');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  logmsg("FATAL: DB bootstrap failed: " . $e->getMessage());
  http_response_code(500);
  exit(1);
}

// ---------- Input ----------
$inputPath = $argv[1] ?? $DEFAULT_INPUT;
if (!is_file($inputPath)) {
  logmsg("FATAL: Input CSV not found: $inputPath");
  exit(1);
}
logmsg("Starting import from: $inputPath");

// ---------- Ensure schema (light-touch; won’t fight your NOT NULL) ----------
try {
  ensureColumn($pdo, 'colors', 'brand',      "VARCHAR(64)  NOT NULL");
  ensureColumn($pdo, 'colors', 'name',       "VARCHAR(255) NOT NULL");
  ensureColumn($pdo, 'colors', 'code',       "VARCHAR(64)  NULL");
  ensureColumn($pdo, 'colors', 'brand_descr',"VARCHAR(255) NULL");
  ensureColumn($pdo, 'colors', 'color_url',  "TEXT NULL");
  ensureColumn($pdo, 'colors', 'hex6',       "CHAR(6) NOT NULL"); // honor your NOT NULL
  ensureColumn($pdo, 'colors', 'r',          "INT NULL");
  ensureColumn($pdo, 'colors', 'g',          "INT NULL");
  ensureColumn($pdo, 'colors', 'b',          "INT NULL");
  ensureColumn($pdo, 'colors', 'lrv',        "DECIMAL(5,2) NULL");

  // Unique by (brand, name)
  ensureUniqueIndex($pdo, 'colors', 'unique_brand_name', ['brand','name']);
} catch (Throwable $e) {
  logmsg("WARN: Schema ensure step had a problem (continuing): " . $e->getMessage());
}

// ---------- Prepared statements ----------
$existsSql = "SELECT 1 FROM colors WHERE brand=:brand AND name=:name LIMIT 1";
$existsStmt = $pdo->prepare($existsSql);

$updateSql = <<<SQL
UPDATE colors
   SET code        = COALESCE(NULLIF(:code, ''), code),
       -- brand_descr intentionally left alone unless provided (we pass NULL)
       brand_descr = COALESCE(NULLIF(:brand_descr, ''), brand_descr),
       color_url   = COALESCE(NULLIF(:color_url, ''), color_url),
       hex6        = COALESCE(NULLIF(:hex6, ''), hex6),
       r           = COALESCE(:r, r),
       g           = COALESCE(:g, g),
       b           = COALESCE(:b, b),
       lrv         = COALESCE(:lrv, lrv)
 WHERE brand = :brand AND name = :name
SQL;
$updateStmt = $pdo->prepare($updateSql);

$insertSql = <<<SQL
INSERT INTO colors
  (name, code, brand, brand_descr, color_url, hex6, r, g, b, lrv)
VALUES
  (:name, :code, :brand, :brand_descr, :color_url, :hex6, :r, :g, :b, :lrv)
SQL;
$insertStmt = $pdo->prepare($insertSql);

// ---------- CSV ingest ----------
[$header, $rows] = readCsvAssoc($inputPath);
$need = ['id','name','hex6','url','lrv','r','g','b','hex6_detail'];
foreach ($need as $h) {
  if (!array_key_exists($h, $header)) {
    logmsg("FATAL: Missing required column in CSV header: $h");
    exit(1);
  }
}

$total=0; $inserted=0; $updated=0; $skipped=0;

$pdo->beginTransaction();
try {
  foreach ($rows as $row) {
    $total++;

    $name = normalizeName($row['name'] ?? '');
    if ($name === '') { logmsg("SKIP row #$total (missing name)"); $skipped++; continue; }

    $code = trim((string)($row['id'] ?? ''));           // optional / unstable
    $url  = trim((string)($row['url'] ?? ''));          // may be blank
    $hex6 = strtoupper(trim((string)($row['hex6'] ?? '')));
    $hex6_detail = strtoupper(trim((string)($row['hex6_detail'] ?? '')));
    if ($hex6 === '' && $hex6_detail !== '') $hex6 = $hex6_detail;
    if ($hex6 !== '' && !preg_match('/^[0-9A-F]{6}$/', $hex6)) $hex6 = '';

    $lrv = normalizeLrv($row['lrv'] ?? null);
    $r = normalizeInt($row['r'] ?? null);
    $g = normalizeInt($row['g'] ?? null);
    $b = normalizeInt($row['b'] ?? null);

    // Backfill RGB from hex; or hex from RGB
    if ($hex6 && ($r===null || $g===null || $b===null)) {
      [$r,$g,$b] = rgbFromHex($hex6);
    } elseif (!$hex6 && $r!==null && $g!==null && $b!==null) {
      $hex6 = hexFromRgb($r,$g,$b);
    }

    // Build params once (same names for both statements)
    $params = [
      ':brand'       => $BRAND,
      ':name'        => $name,
      ':code'        => ($code !== '' ? $code : null),
      ':brand_descr' => null,                    // DO NOT set marketing text here
      ':color_url'   => ($url !== '' ? $url : null),
      ':hex6'        => ($hex6 !== '' ? $hex6 : null), // NULL means "don’t overwrite" on UPDATE
      ':r'           => $r,
      ':g'           => $g,
      ':b'           => $b,
      ':lrv'         => $lrv,
    ];

    // Exist check first (avoids rowCount() == 0 pitfall)
    $existsStmt->execute([':brand'=>$BRAND, ':name'=>$name]);
    $exists = (bool)$existsStmt->fetchColumn();

    if ($exists) {
      $updateStmt->execute($params);
      $updated++; // even if rowCount()=0, we count it as processed
    } else {
      // INSERT path must satisfy NOT NULL hex6
      if ($hex6 === '') { logmsg("SKIP row #$total (no hex6 to INSERT for {$name})"); $skipped++; continue; }
      $insertStmt->execute($params);
      $inserted++;
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  logmsg("FATAL: Transaction failed at row $total: " . $e->getMessage());
  exit(1);
}

logmsg("✅ Import complete. total=$total inserted~$inserted updated~$updated skipped=$skipped");
exit(0);

/* ================= Helpers ================= */

function ensureColumn(PDO $pdo, string $table, string $col, string $definition): void {
  $q = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c";
  $st = $pdo->prepare($q);
  $st->execute([':t'=>$table, ':c'=>$col]);
  if ((int)$st->fetchColumn() > 0) return;
  $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
}

function ensureUniqueIndex(PDO $pdo, string $table, string $indexName, array $cols): void {
  $q = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i";
  $st = $pdo->prepare($q);
  $st->execute([':t'=>$table, ':i'=>$indexName]);
  if ((int)$st->fetchColumn() > 0) return;

  $colsSql = implode('`,`', $cols);
  try {
    $pdo->exec("CREATE UNIQUE INDEX `$indexName` ON `$table` (`$colsSql`)");
  } catch (Throwable $e) {
    if (strpos($e->getMessage(), 'key length') !== false) {
      // Fallback for old MySQL byte limits
      $pdo->exec("CREATE UNIQUE INDEX `$indexName` ON `$table` (`brand`(64), `name`(191))");
    } else {
      throw $e;
    }
  }
}

function readCsvAssoc(string $path): array {
  $fh = fopen($path, 'rb');
  if (!$fh) throw new RuntimeException("Cannot open CSV: $path");
  $header = fgetcsv($fh);
  if ($header === false) throw new RuntimeException("Empty CSV: $path");

  $map = [];
  foreach ($header as $i => $h) $map[strtolower(trim($h))] = $i;

  $rows = [];
  while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === 1 && trim($row[0]) === '') continue;
    $assoc = [];
    foreach ($map as $k => $i) $assoc[$k] = $row[$i] ?? '';
    $rows[] = $assoc;
  }
  fclose($fh);
  return [$map, $rows];
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
  $r = hexdec(substr($hex6, 0, 2));
  $g = hexdec(substr($hex6, 2, 2));
  $b = hexdec(substr($hex6, 4, 2));
  return [$r,$g,$b];
}

function hexFromRgb(int $r, int $g, int $b): string {
  $r = max(0, min(255, $r));
  $g = max(0, min(255, $g));
  $b = max(0, min(255, $b));
  return strtoupper(str_pad(dechex($r),2,'0',STR_PAD_LEFT)
      .str_pad(dechex($g),2,'0',STR_PAD_LEFT)
      .str_pad(dechex($b),2,'0',STR_PAD_LEFT));
}

function normalizeName($s): string {
  $s = trim((string)$s);
  return preg_replace('/\s+/u', ' ', $s);
}
