<?php
// api/import-chip-columns.php
// Bulk-import Dunn-Edwards chip numbers from ChipColumns.csv
// CSV format: name, chip_num (NO HEADERS)

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(60);

require_once  'db.php';

const CSV_PATH      = __DIR__ . '/data/ChipColumns.csv';
const LOG_PATH      = __DIR__ . '/logs/import-chip-columns.log';
const UPDATED_OUT   = __DIR__ . '/data/ChipColumns.updated.csv';
const UNMATCHED_OUT = __DIR__ . '/data/ChipColumns.unmatched.csv';

function log_event(string $lvl, string $msg, array $ctx=[]): void {
  $dir = dirname(LOG_PATH);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @error_log(json_encode(['ts'=>date('c'),'lvl'=>$lvl,'msg'=>$msg,'ctx'=>$ctx]).PHP_EOL, 3, LOG_PATH);
}

if (!file_exists(CSV_PATH)) { echo "CSV not found: ".CSV_PATH.PHP_EOL; exit; }
$fh = fopen(CSV_PATH, 'r');
if (!$fh) { echo "Unable to open CSV".PHP_EOL; exit; }

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->beginTransaction();

$upd = $pdo->prepare("
  UPDATE colors
     SET chip_num = :chip_num
   WHERE brand = 'de'
     AND name = :name
  LIMIT 1
");

$total=0; $updated=0; $skipped_blank=0; $skipped_badnum=0; $no_match=0;
$updatedRows=[]; $unmatchedRows=[];

while (($row = fgetcsv($fh)) !== false) {
  $total++;
  if (count($row) < 2) { $skipped_blank++; continue; }
  $name = trim($row[0]);
  $chipRaw = trim($row[1]);

  if ($name === '') { $skipped_blank++; continue; }
  if ($chipRaw === '' || $chipRaw === '???') { $skipped_blank++; continue; }

  $chipDigits = preg_replace('/\D+/', '', $chipRaw);
  if ($chipDigits === '') { $skipped_badnum++; continue; }
  $chip = (int)$chipDigits;

  $upd->execute([':chip_num'=>$chip, ':name'=>$name]);
  if ($upd->rowCount() === 1) {
    $updated++;
    $updatedRows[] = [$name,$chip];
  } else {
    $no_match++;
    $unmatchedRows[] = [$name,$chipRaw];
  }
}
fclose($fh);
$pdo->commit();

// Write audit files
@file_put_contents(UPDATED_OUT, "color_name,chip_num\n");
foreach ($updatedRows as $r) {
  @file_put_contents(UPDATED_OUT, $r[0].",".$r[1]."\n", FILE_APPEND);
}
@file_put_contents(UNMATCHED_OUT, "color_name,chip_num_raw\n");
foreach ($unmatchedRows as $r) {
  @file_put_contents(UNMATCHED_OUT, $r[0].",".$r[1]."\n", FILE_APPEND);
}

$result = [
  'status'=>'ok',
  'total_rows_read'=>$total,
  'updated'=>$updated,
  'skipped_blank_or_missing'=>$skipped_blank,
  'skipped_bad_number'=>$skipped_badnum,
  'no_match_in_colors_brand_de'=>$no_match,
  'updated_out'=>UPDATED_OUT,
  'unmatched_out'=>UNMATCHED_OUT,
  'log'=>LOG_PATH
];

log_event('info','chip import complete',$result);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT),PHP_EOL;
