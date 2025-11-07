<?php
require_once 'db.php';

/* ==== CONFIG ==== */
$csvFile = __DIR__ . '/data/mmpc_colors.csv';
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/import_mmpc_colors_errors.log';

/* Ensure PDO throws exceptions so we SEE DB errors */
try {
    if (isset($pdo)) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Throwable $e) {
    // ignore, some db.php already sets it
}

/* Helpers */
function clean_hex6($hex) {
    $h = strtoupper(trim((string)$hex));
    $h = ltrim($h, '#');
    return preg_match('/^[0-9A-F]{6}$/', $h) ? $h : '';
}
function clean_code($raw) {
    $slug = strtoupper(trim((string)$raw));
    if ($slug === '') return '';

    $parts = preg_split('/-+/', $slug);
    $n = count($parts);
    if ($n === 0) return $slug;

    // find rightmost segment that has a digit
    $idx = -1;
    for ($i = $n - 1; $i >= 0; $i--) {
        if (preg_match('/\d/', $parts[$i])) { $idx = $i; break; }
    }
    if ($idx === -1) return $slug;

    $tail = $parts[$idx];
    $head = '';
    if ($idx > 0) {
        $prev = $parts[$idx - 1];
        if (
            preg_match('/^(SW|OC|HC|NO)$/', $prev) ||     // short prefixes
            preg_match('/^(PPG\d*)$/', $prev) ||          // PPG1068
            (strlen($prev) <= 8 && preg_match('/^[A-Z]{1,5}\d*$/', $prev))
        ) {
            $head = $prev;
        }
    }
    $code = $head ? ($head . '-' . $tail) : $tail;
    return preg_replace('/[^A-Z0-9\-]/', '', $code);
}

/* Files */
if (!file_exists($csvFile)) {
    die("<h2>❌ CSV file not found: $csvFile</h2>");
}
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logH = @fopen($logFile, 'a');
$log = function($msg) use ($logH) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    if ($logH) fwrite($logH, $line);
};

/* Open CSV */
$handle = fopen($csvFile, 'r');
if (!$handle) die("<h2>❌ Unable to open CSV file</h2>");

$header = fgetcsv($handle);
if (!$header) die("<h2>❌ Empty CSV</h2>");

/* Map headers (case-insensitive) */
$idx = [];
foreach ($header as $i => $col) {
    $idx[strtolower(trim($col))] = $i;
}
$required = ['brand','code','name','hex'];
foreach ($required as $k) {
    if (!array_key_exists($k, $idx)) {
        die("<h2>❌ CSV is missing required column: <code>$k</code></h2>");
    }
}

/* RGB layout */
$hasRgbCombined = array_key_exists('rgb', $idx);
$hasSplitRGB = array_key_exists('r', $idx) && array_key_exists('g', $idx) && array_key_exists('b', $idx);

/* Prepare statement */
try {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO colors (name, code, brand, r, g, b, hex6)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
} catch (Throwable $e) {
    $log("Prepare failed: " . $e->getMessage());
    die("<h2>❌ DB prepare failed</h2><pre>{$e->getMessage()}</pre>");
}

/* Counters */
$lineNum   = 1; // header consumed
$inserted  = 0;
$duplicates= 0;
$skipped   = 0;
$sqlErrors = 0;

/* Process */
while (($row = fgetcsv($handle)) !== false) {
    $lineNum++;

    // Pull fields
    $brand  = strtolower(trim($row[$idx['brand']] ?? ''));
    $rawCode= $row[$idx['code']] ?? '';
    $name   = trim($row[$idx['name']] ?? '');
    $hex6   = clean_hex6($row[$idx['hex']] ?? '');

    // RGB extraction
    $r = $g = $b = null;
    if ($hasRgbCombined) {
        $rgb = trim($row[$idx['rgb']] ?? '');
        if ($rgb !== '' && strpos($rgb, ',') !== false) {
            [$r, $g, $b] = array_map('intval', explode(',', $rgb));
        }
    } elseif ($hasSplitRGB) {
        $r = intval($row[$idx['r']] ?? 0);
        $g = intval($row[$idx['g']] ?? 0);
        $b = intval($row[$idx['b']] ?? 0);
    }
    if (($r === null || $g === null || $b === null) && $hex6 !== '') {
        $int = hexdec($hex6);
        $r = ($int >> 16) & 255;
        $g = ($int >> 8) & 255;
        $b = $int & 255;
    }

    $code = clean_code($rawCode);

    // Validation
    $errs = [];
    if ($brand === '') $errs[] = 'brand';
    if ($code  === '') $errs[] = 'code';
    if ($name  === '') $errs[] = 'name';
    if ($hex6  === '') $errs[] = 'hex6';
    if (!is_int($r) || !is_int($g) || !is_int($b)) $errs[] = 'rgb';

    if (!empty($errs)) {
        $skipped++;
        $log("Line $lineNum SKIP: missing/invalid [" . implode(',', $errs) . "] | brand={$brand} rawCode={$rawCode} name={$name} hex={$hex6}");
        continue;
    }

    // Insert w/ error handling
    try {
        $stmt->execute([$name, $code, $brand, $r, $g, $b, $hex6]);
        $affected = $stmt->rowCount(); // with INSERT IGNORE: 1=new row, 0=duplicate
        if ($affected === 1) {
            $inserted++;
        } else {
            $duplicates++;
        }
    } catch (Throwable $e) {
        $sqlErrors++;
        $log("Line $lineNum SQL ERROR: " . $e->getMessage() . " | data=" . json_encode([
            'brand'=>$brand,'code'=>$code,'name'=>$name,'hex6'=>$hex6,'r'=>$r,'g'=>$g,'b'=>$b
        ]));
        // Keep going, but also echo a visible line every time
        echo "<p>❌ SQL error on line $lineNum: <code>" . htmlspecialchars($e->getMessage()) . "</code></p>";
    }

    if (($lineNum % 200) === 0) {
        echo "<p>…processed $lineNum lines (inserted: $inserted, dupes: $duplicates, skipped: $skipped, sql errors: $sqlErrors)</p>";
        @ob_flush(); @flush();
    }
}

fclose($handle);
if ($logH) fclose($logH);

/* Summary */
echo "<h2>✅ Import finished</h2>";
echo "<ul>";
echo "<li>Inserted: <strong>{$inserted}</strong></li>";
echo "<li>Duplicates (ignored): <strong>{$duplicates}</strong></li>";
echo "<li>Skipped (validation): <strong>{$skipped}</strong></li>";
echo "<li>SQL errors: <strong>{$sqlErrors}</strong></li>";
echo "</ul>";
echo "<p>📄 Error log: <code>$logFile</code></p>";
