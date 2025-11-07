<?php
declare(strict_types=1);
require_once 'db.php';

ini_set('display_errors','0');
ini_set('log_errors','1');

function logf(string $file, string $msg): void {
  file_put_contents($file, '['.date('c')."] $msg\n", FILE_APPEND);
}
function titleCase(string $s): string { return ucwords(strtolower($s)); }
function baseUrl(): ?string {
  if (!empty($_SERVER['HTTP_HOST'])) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
  }
  return null;
}
function triggerRecalcHexes(array $hexes, int $chunk=400): void {
  if (!$hexes) return;
  $base = baseUrl(); if (!$base) return;
  $hexes = array_values(array_unique($hexes));
  foreach (array_chunk($hexes, $chunk) as $c) {
    $qs = implode(',', $c);
    @file_get_contents(rtrim($base,'/').'/api/recalc-colors.php?hexes='.urlencode($qs));
  }
}

$csv = __DIR__.'/data/fb-colors-details.csv';
$log = __DIR__.'/import-fb.log';
logf($log, "=== FB import started ===");

try {
  if (!is_file($csv)) throw new RuntimeException("CSV not found: $csv");
  $fh = fopen($csv, 'rb');
  if (!$fh) throw new RuntimeException("Could not open CSV: $csv");

  $hdr = fgetcsv($fh); // header row
  if ($hdr === false) throw new RuntimeException("Empty CSV");

  $insSql = <<<SQL
INSERT INTO colors
  (name, code, brand, brand_descr, color_url,
   hex6, r, g, b, lrv, interior, exterior, is_stain, notes)
VALUES
  (:name, :code, :brand, :brand_descr, :url,
   :hex6, :r, :g, :b, :lrv, :interior, :exterior, :is_stain, :notes)
ON DUPLICATE KEY UPDATE
  code        = COALESCE(VALUES(code), code),
  brand_descr = COALESCE(VALUES(brand_descr), brand_descr),
  color_url   = COALESCE(VALUES(color_url), color_url),
  r           = COALESCE(VALUES(r), r),
  g           = COALESCE(VALUES(g), g),
  b           = COALESCE(VALUES(b), b),
  hex6        = COALESCE(VALUES(hex6), hex6)
SQL;
  $ins = $pdo->prepare($insSql);

  $total=0; $inserted=0; $updated=0; $skipped=0;
  $newHexes = [];

  $pdo->beginTransaction();
  while (($row = fgetcsv($fh)) !== false) {
    $total++;
    if (count($row) < 7) { $skipped++; logf($log, "skip: too few cols (#$total)"); continue; }

    [$name,$code,$r_raw,$g_raw,$b_raw,$hex_raw,$url] = array_map('trim',$row);
    if ($name === '' || $hex_raw === '' || $url === '') {
      $skipped++; logf($log, "skip: required missing (#$total)"); continue;
    }

    $hex = strtoupper(ltrim($hex_raw, '#'));
    if (!preg_match('/^[0-9A-F]{6}$/',$hex)) {
      $skipped++; logf($log, "skip: bad hex '$hex' (#$total)"); continue;
    }

    $r = (int)$r_raw;
    $g = (int)$g_raw;
    $b = (int)$b_raw;

    $nameTC = titleCase($name);
    $codeUC = strtoupper($code);

    try {
      $ins->execute([
        ':name'        => $nameTC,
        ':code'        => ($codeUC !== '' ? $codeUC : null),
        ':brand'       => 'fb',
        ':brand_descr' => null,
        ':url'         => $url,
        ':hex6'        => $hex,
        ':r'           => $r,
        ':g'           => $g,
        ':b'           => $b,
        ':lrv'         => null,
        ':interior'    => 1,
        ':exterior'    => 1,
        ':is_stain'    => 0,
        ':notes'       => null,
      ]);

      $rc = $ins->rowCount();
      if ($rc === 1) { $inserted++; $newHexes[] = $hex; }
      elseif ($rc >= 2) { $updated++; }
    } catch (Throwable $e) {
      $skipped++;
      logf($log, "DB error for $codeUC ($nameTC) [$hex]: ".$e->getMessage());
    }
  }
  $pdo->commit();
  fclose($fh);

  logf($log, "✅ Done. total=$total inserted=$inserted updated=$updated skipped=$skipped");

  if ($inserted > 0) {
    triggerRecalcHexes($newHexes);
    logf($log, "Triggered recalc for ~".count(array_unique($newHexes))." new hexes");
  }

  echo "✅ FB Import complete. total=$total inserted=$inserted updated=$updated skipped=$skipped\nSee log: import-fb.log\n";

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  logf($log, "❌ Fatal: ".$e->getMessage());
  http_response_code(500);
  echo "❌ Import failed. See import-fb.log\n";
}
