<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

ini_set('display_errors','0');
ini_set('log_errors','1');

$CSV = __DIR__ . '/data/fb-designer-schemes.csv';
$LOG = __DIR__ . '/import-fb-schemes-friends.log';

function logf(string $file, string $msg): void {
  @file_put_contents($file, '['.date('c')."] $msg\n", FILE_APPEND);
}

/** "Cornforth White No. 228" -> "Cornforth White" */
function clean_name(string $s): string {
  $s = trim($s);
  $s = preg_replace('/\s+No\.?\s*[0-9A-Za-z]+$/i', '', $s) ?? $s;
  return ucwords(strtolower(trim($s)));
}

/** "No. 2011" -> "2011"; " 5 "->"5"; return null if empty */
function clean_code(?string $s): ?string {
  $s = strtoupper(trim((string)$s));
  if ($s === '') return null;
  $s = preg_replace('/[^0-9A-Z]/', '', $s);
  return $s !== '' ? $s : null;
}

logf($LOG, "=== FB schemes->friends import started ===");

try {
  if (!is_file($CSV)) throw new RuntimeException("CSV not found: $CSV");
  $fh = fopen($CSV, 'rb');
  if (!$fh) throw new RuntimeException("Could not open CSV: $CSV");

  $hdr = fgetcsv($fh); // header
  if ($hdr === false) throw new RuntimeException("Empty CSV");

  // Prepare lookups
  $qByCode = $pdo->prepare("SELECT DISTINCT UPPER(hex6) AS hex6 FROM colors WHERE brand='fb' AND code = :code AND hex6 IS NOT NULL");
  $qByName = $pdo->prepare("SELECT DISTINCT UPPER(hex6) AS hex6 FROM colors WHERE brand='fb' AND LOWER(name) = :name AND hex6 IS NOT NULL");

  // Insert pair
  $ins = $pdo->prepare("
    INSERT IGNORE INTO color_friends (hex1, hex2, source)
    VALUES (:hex1, :hex2, 'fb site')
  ");

  // Group rows by (family, scheme)
  $groups = []; // key => [ [name, code], ... ]
  $rownum = 0;
  while (($row = fgetcsv($fh)) !== false) {
    $rownum++;
    if (count($row) < 6) { logf($LOG, "skip row#$rownum: too few cols"); continue; }
    [$family, $scheme, $idx, $nameRaw, $codeRaw, $url] = $row;
    $family = trim((string)$family);
    $scheme = trim((string)$scheme);
    $key = $family . '|' . $scheme;
    $groups[$key][] = [
      'name' => clean_name((string)$nameRaw),
      'code' => clean_code((string)$codeRaw),
    ];
  }
  fclose($fh);

  $totalGroups = count($groups);
  $rows = 0; $inserted = 0; $dupes = 0; $skipped = 0;
  $missingItems = 0;

  $pdo->beginTransaction();

  foreach ($groups as $key => $items) {
    // Resolve each item -> array of hexes (allow multiples)
    $resolved = []; // index => array of hex6
    foreach ($items as $i => $it) {
      $hexes = [];
      if ($it['code']) {
        $qByCode->execute([':code' => $it['code']]);
        $hexes = array_column($qByCode->fetchAll(PDO::FETCH_ASSOC), 'hex6');
      }
      if (!$hexes && $it['name'] !== '') {
        $qByName->execute([':name' => strtolower($it['name'])]);
        $hexes = array_column($qByName->fetchAll(PDO::FETCH_ASSOC), 'hex6');
      }
      if (!$hexes) {
        $missingItems++;
        logf($LOG, "no match in {$key}: name='{$it['name']}' code='{$it['code']}'");
      }
      $resolved[$i] = $hexes; // may be empty
    }

    // Build all unique pairs among group members (i<j)
    $n = count($items);
    for ($i=0; $i<$n; $i++) {
      for ($j=$i+1; $j<$n; $j++) {
        $A = $resolved[$i] ?? [];
        $B = $resolved[$j] ?? [];
        if (!$A || !$B) { $skipped++; continue; }

        foreach ($A as $ha) {
          foreach ($B as $hb) {
            if (!$ha || !$hb) continue;
            if (strcasecmp($ha, $hb) === 0) continue; // skip self-pair
            $h1 = (strcasecmp($ha, $hb) < 0) ? $ha : $hb;
            $h2 = (strcasecmp($ha, $hb) < 0) ? $hb : $ha;

            try {
              $ins->execute([':hex1' => $h1, ':hex2' => $h2]);
              $rc = $ins->rowCount();
              if ($rc === 1) $inserted++; else $dupes++;
              $rows++;
            } catch (Throwable $e) {
              $skipped++;
              logf($LOG, "DB error pair {$h1}-{$h2} in {$key}: ".$e->getMessage());
            }
          }
        }
      }
    }
  }

  $pdo->commit();

  logf($LOG, "✅ Done. groups=$totalGroups pairs_seen=$rows inserted=$inserted dupes=$dupes skipped=$skipped missing_items=$missingItems");
  echo "✅ FB schemes→friends complete. groups=$totalGroups pairs_seen=$rows inserted=$inserted dupes=$dupes skipped=$skipped missing_items=$missingItems\nSee log: import-fb-schemes-friends.log\n";

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  logf($LOG, "❌ Fatal: ".$e->getMessage());
  http_response_code(500);
  echo "❌ Import failed. See import-fb-schemes-friends.log\n";
}
