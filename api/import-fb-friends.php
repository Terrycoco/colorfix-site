<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

ini_set('display_errors','0');
ini_set('log_errors','1');

$CSV = __DIR__ . '/data/fb-friends.csv';
$LOG = __DIR__ . '/import-fb-friends-v2.log';

function logf(string $file, string $msg): void {
  @file_put_contents($file, '['.date('c')."] $msg\n", FILE_APPEND);
}
function clean_hex(?string $h): ?string {
  $h = strtoupper(trim((string)$h));
  $h = ltrim($h, '#');
  if ($h === '') return null;
  if (!preg_match('/^[0-9A-F]{6}$/', $h)) return null;
  return $h;
}
function clean_name(string $s): string {
  $s = trim($s);
  // Remove trailing "No. 123A" or "No 123"
  $s = preg_replace('/\s+No\.?\s*[0-9A-Za-z]+$/i', '', $s) ?? $s;
  return ucwords(strtolower(trim($s)));
}
function clean_code(?string $s): ?string {
  $s = strtoupper(trim((string)$s));
  if ($s === '') return null;
  $s = preg_replace('/[^0-9A-Z]/', '', $s);
  return $s !== '' ? $s : null;
}

// Lookups
$qByHex  = $pdo->prepare("SELECT DISTINCT UPPER(hex6) AS hex6 FROM colors WHERE hex6 = :hex");
$qByCode = $pdo->prepare("SELECT DISTINCT UPPER(hex6) AS hex6 FROM colors WHERE brand='fb' AND code = :code AND hex6 IS NOT NULL");
$qByName = $pdo->prepare("SELECT DISTINCT UPPER(hex6) AS hex6 FROM colors WHERE brand='fb' AND LOWER(name) = :name AND hex6 IS NOT NULL");

// Insert
$ins = $pdo->prepare("
  INSERT IGNORE INTO color_friends (hex1, hex2, source)
  VALUES (:hex1, :hex2, :source)
");

function resolve_hexes(PDOStatement $qByHex, PDOStatement $qByCode, PDOStatement $qByName, ?string $hex, ?string $code, ?string $name): array {
  // Priority: explicit hex -> code -> name
  $out = [];
  if ($hex) {
    $qByHex->execute([':hex' => strtoupper($hex)]);
    $out = array_column($qByHex->fetchAll(PDO::FETCH_ASSOC), 'hex6');
    if ($out) return array_values(array_unique($out));
  }
  if ($code) {
    $qByCode->execute([':code' => $code]);
    $out = array_column($qByCode->fetchAll(PDO::FETCH_ASSOC), 'hex6');
    if ($out) return array_values(array_unique($out));
  }
  if ($name) {
    $qByName->execute([':name' => strtolower($name)]);
    $out = array_column($qByName->fetchAll(PDO::FETCH_ASSOC), 'hex6');
  }
  return array_values(array_unique($out));
}

logf($LOG, "=== FB friends v2 import started ===");

try {
  if (!is_file($CSV)) throw new RuntimeException("CSV not found: $CSV");
  $fh = fopen($CSV, 'rb');
  if (!$fh) throw new RuntimeException("Could not open CSV: $CSV");

  $hdr = fgetcsv($fh); // header
  if ($hdr === false) throw new RuntimeException("Empty CSV");

  // Column order:
  // anchor_name,anchor_code,anchor_hex6,friend_name,friend_hex6,friend_url,relation,slot,box
  $rows = 0; $inserted = 0; $dupes = 0; $skipped = 0;
  $missAnchor = 0; $missFriend = 0;

  // For carousel trios: group by (anchor_hex6_resolved, slot)
  // We'll resolve anchor to one/many hexes, then build per-slot friend lists for each anchor hex.
  $carousel = []; // key = anchorHex|slot => set of friend hexes (strings)

  $pdo->beginTransaction();

  // First pass: read and either (a) insert complementary white pairs, or (b) stage carousel friends
  while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 9) { $skipped++; logf($LOG, "skip: too few cols"); continue; }
    [$a_name_raw, $a_code_raw, $a_hex_raw, $f_name_raw, $f_hex_raw, $f_url, $relation, $slot_raw, $box_raw] = $row;
    $rows++;

    $a_name = clean_name((string)$a_name_raw);
    $a_code = clean_code($a_code_raw);
    $a_hex  = clean_hex($a_hex_raw);

    $f_name = clean_name((string)$f_name_raw);
    $f_code = clean_code(null); // friend_code not present in this file
    $f_hex  = clean_hex($f_hex_raw);

    $slot = trim((string)$slot_raw);

    // Resolve anchor hexes
    $anchorHexes = resolve_hexes($qByHex, $qByCode, $qByName, $a_hex, $a_code, $a_name);
    if (!$anchorHexes) { $missAnchor++; logf($LOG, "no anchor match name='{$a_name}' code='{$a_code}' hex='{$a_hex_raw}'"); continue; }

    if (strcasecmp($relation ?? '', 'complementary_white') === 0) {
      // Resolve friend (white) by hex or name
      $friendHexes = resolve_hexes($qByHex, $qByCode, $qByName, $f_hex, $f_code, $f_name);
      if (!$friendHexes) { $missFriend++; logf($LOG, "no friend match (complementary) name='{$f_name}' hex='{$f_hex_raw}'"); continue; }

      // Cross-product insert
      foreach ($anchorHexes as $ha) {
        foreach ($friendHexes as $hb) {
          if (!$ha || !$hb) continue;
          if (strcasecmp($ha, $hb) === 0) continue;
          $h1 = (strcasecmp($ha, $hb) < 0) ? $ha : $hb;
          $h2 = (strcasecmp($ha, $hb) < 0) ? $hb : $ha;
          try {
            $ins->execute([':hex1'=>$h1, ':hex2'=>$h2, ':source'=>'fb site']);
            $rc = $ins->rowCount();
            if ($rc === 1) $inserted++; else $dupes++;
          } catch (Throwable $e) {
            $skipped++;
            logf($LOG, "DB error complementary {$h1}-{$h2}: ".$e->getMessage());
          }
        }
      }

    } else {
      // relation = carousel (or anything else treated as carousel)
      // friend_hex may be present; friend_name often blank; resolve anyway
      $friendHexes = resolve_hexes($qByHex, $qByCode, $qByName, $f_hex, $f_code, $f_name);
      if (!$friendHexes) { $missFriend++; logf($LOG, "no friend match (carousel) name='{$f_name}' hex='{$f_hex_raw}'"); continue; }

      // For each resolved anchor hex, stage each friend hex into the slot bucket
      foreach ($anchorHexes as $ha) {
        $key = $ha . '|' . $slot;
        if (!isset($carousel[$key])) $carousel[$key] = [];
        foreach ($friendHexes as $hb) {
          if (!$hb || strcasecmp($ha, $hb) === 0) continue;
          $carousel[$key][$hb] = true; // use as set
        }
      }
    }
  }
  fclose($fh);

  // Second pass: mesh each carousel trio (anchor + two friends per slot)
  foreach ($carousel as $key => $friendSet) {
    [$anchorHex, $slot] = explode('|', $key, 2);
    // Build the trio members: anchor + unique friend hexes in this slot
    $friends = array_keys($friendSet);
    // If there are only 1 friend rows due to partial scrape, skip; normally there should be ≥2
    if (count($friends) < 2) { $skipped++; logf($LOG, "slot incomplete for {$key}, friends=".count($friends)); continue; }

    // Trio pairs: (anchor, f1), (anchor, f2), (f1, f2); if more than 2 friends, just mesh all against anchor and among themselves
    $members = array_merge([$anchorHex], $friends);
    $n = count($members);
    for ($i=0; $i<$n; $i++) {
      for ($j=$i+1; $j<$n; $j++) {
$ha = strtoupper((string)$members[$i]);
$hb = strtoupper((string)$members[$j]);
        if ($ha === $hb) continue;
        $h1 = (strcasecmp($ha, $hb) < 0) ? $ha : $hb;
        $h2 = (strcasecmp($ha, $hb) < 0) ? $hb : $ha;
        try {
          $ins->execute([':hex1'=>$h1, ':hex2'=>$h2, ':source'=>'fb site']);
          $rc = $ins->rowCount();
          if ($rc === 1) $inserted++; else $dupes++;
        } catch (Throwable $e) {
          $skipped++;
          logf($LOG, "DB error carousel {$h1}-{$h2} (key={$key}): ".$e->getMessage());
        }
      }
    }
  }

  $pdo->commit();

  logf($LOG, "✅ Done. rows=$rows inserted=$inserted dupes=$dupes skipped=$skipped missing_anchor=$missAnchor missing_friend=$missFriend slots=".count($carousel));
  echo "✅ FB v2 friends import complete. rows=$rows inserted=$inserted dupes=$dupes skipped=$skipped missing_anchor=$missAnchor missing_friend=$missFriend slots=".count($carousel)."\nSee log: import-fb-friends-v2.log\n";

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  logf($LOG, "❌ Fatal: ".$e->getMessage());
  http_response_code(500);
  echo "❌ Import failed. See import-fb-friends-v2.log\n";
}
