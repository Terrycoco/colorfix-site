<?php
// /colorfix/api/import-stage-hexpairs.php
// Minimal: take hex1..hex4 from stage and insert all 6 unordered pairs into color_friends.
// Assumes color_friends already exists.
// Usage example:
//   /colorfix/api/import-stage-hexpairs.php?token=CHANGE_ME_SECRET&source=behr%20detail
// Optional params:
//   &stage_table=behr_coordinating_stage  (default)
//   &batch=5000   (stage id range per loop)
//   &mini=200     (pairs per multi-insert)

@ini_set('display_errors','1'); error_reporting(E_ALL);
@set_time_limit(0); ignore_user_abort(true);
header('Content-Type: text/plain; charset=utf-8');

require_once 'db.php';

// ---- params ----
$token  = $_GET['token']  ?? '';
$expect = 'CHANGE_ME_SECRET';                 // set your secret
if ($expect && $token !== $expect) { http_response_code(403); echo "Forbidden\n"; exit; }

$stage  = $_GET['stage_table'] ?? 'behr_coordinating_stage';
$source = $_GET['source']      ?? 'behr detail';
$batch  = max(1000, (int)($_GET['batch'] ?? 5000));   // rows per ID range
$mini   = max(50,   (int)($_GET['mini']  ?? 200));    // pairs per multi-insert

 

// ---- helpers ----
function nhex($s){
  $s = strtoupper(trim((string)$s));
  if ($s !== '' && $s[0] === '#') $s = substr($s,1);
  return preg_match('/^[0-9A-F]{6}$/', $s) ? $s : '';
}

// ---- get ID range from stage ----
list($minId,$maxId) = $pdo->query("SELECT COALESCE(MIN(id),0), COALESCE(MAX(id),0) FROM {$stage}")->fetch(PDO::FETCH_NUM);
$minId = (int)$minId; $maxId = (int)$maxId;
if ($maxId === 0) { echo "Stage empty: {$stage}\n"; exit; }
echo "Stage ID range: {$minId}..{$maxId}\n";

// ---- select rows in ranges ----
$sel = $pdo->prepare("
  SELECT id, hex1, hex2, hex3, hex4
  FROM {$stage}
  WHERE id BETWEEN :a AND :b
");

// ---- batched multi-insert ----
$inserted = 0;
function flushPairs(&$vals,&$args,$pdo,&$inserted){
  if (!$vals) return;
  $sql = "INSERT IGNORE INTO color_friends (hex1,hex2,source) VALUES ".implode(',', $vals);
  $st  = $pdo->prepare($sql);
  $st->execute($args);
  $inserted += $st->rowCount();
  $vals = []; $args = [];
}

for ($start=$minId; $start <= $maxId; $start += $batch) {
  $end = min($start + $batch - 1, $maxId);
  $sel->execute([':a'=>$start, ':b'=>$end]);

  $vals = []; $args = []; $count=0; $seen = [];

  while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
    // normalize valid hexes present on the row
    $h = [];
    foreach (['hex1','hex2','hex3','hex4'] as $k) {
      $hx = nhex($r[$k] ?? '');
      if ($hx) $h[] = $hx;
    }
    if (count($h) < 2) continue;

    // all 6 unordered pairs among up to 4 hexes
    $n = count($h);
    for ($i=0; $i<$n; $i++) {
      for ($j=$i+1; $j<$n; $j++) {
        $a = $h[$i]; $b = $h[$j];
        if ($a === $b) continue;
        // order pair
        if ($a > $b) { $t=$a; $a=$b; $b=$t; }
        $key = "$a-$b";
        if (isset($seen[$key])) continue; // de-dupe within this range
        $seen[$key] = true;
        $vals[] = "(?,?,?)";
        $args[] = $a; $args[] = $b; $args[] = $source;
        $count++;
        if ($count % $mini === 0) { flushPairs($vals,$args,$pdo,$inserted); }
      }
    }
  }

  flushPairs($vals,$args,$pdo,$inserted);
  echo "range {$start}-{$end}: pairs inserted so far = {$inserted}\n"; @flush();
}

echo "Done. Total pairs inserted (affected): {$inserted}\n";
