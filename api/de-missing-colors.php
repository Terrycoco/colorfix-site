<?php declare(strict_types=1);
/**
 * de-colors-from-edges.php
 * Pull all DE friend nodes from edges CSV(s) and insert into colors(brand,code,name,hex6,r,g,b).
 *
 * - Reads: ./data/de-friend-edges.csv by default (glob supported via --in=)
 * - Brand: 'de' (override with --brand=de)
 * - Map:   optional code->hex CSV for fallback (--map=./data/de-code-hex.csv)
 * - Dedupe: INSERT IGNORE (let your DB UNIQUEs handle duplicates)
 *
 * Usage:
 *   php de-colors-from-edges.php
 *   php de-colors-from-edges.php --in="./data/de-friend-edges*.csv" --map=./data/de-code-hex.csv --verbose
 *   php de-colors-from-edges.php --dry-run --verbose
 */

require_once __DIR__ . '/db.php';  // must define $pdo (PDO)
if (!isset($pdo) || !($pdo instanceof PDO)) {
  fwrite(STDERR, "db.php did not provide a valid \$pdo\n");
  exit(2);
}

// -------- args ----------
$args = [];
foreach ($argv as $i => $arg) {
  if ($i === 0 || strpos($arg, '--') !== 0) continue;
  [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
  $args[$k] = $v === null ? true : $v;
}
$inArg   = $args['in']     ?? (__DIR__ . '/data/de-friend-edges.csv');
$mapPath = $args['map']    ?? (__DIR__ . '/data/de-code-hex.csv');   // optional
$brand   = $args['brand']  ?? 'de';
$dryRun  = !empty($args['dry-run']);
$verbose = !empty($args['verbose']);

// -------- utils ----------
function parse_csv_line(string $line): array {
  $out = []; $cur = ''; $inQ = false; $len = strlen($line);
  for ($i=0; $i<$len; $i++) {
    $ch = $line[$i];
    if ($inQ) {
      if ($ch === '"') {
        if ($i+1<$len && $line[$i+1]==='"') { $cur.='"'; $i++; }
        else { $inQ=false; }
      } else { $cur .= $ch; }
    } else {
      if ($ch === '"') { $inQ = true; }
      elseif ($ch === ',') { $out[] = $cur; $cur = ''; }
      else { $cur .= $ch; }
    }
  }
  $out[] = $cur;
  return $out;
}
function normalize_hex6(?string $s): string {
  $h = strtoupper(ltrim(trim((string)$s), '#'));
  return preg_match('/^[0-9A-F]{6}$/', $h) ? $h : '';
}
function rgb_to_hex(?string $rgb): string {
  if (!$rgb) return '';
  if (!preg_match('/(\d+)[,\s]+(\d+)[,\s]+(\d+)/', $rgb, $m)) return '';
  $toH = fn($n)=>strtoupper(str_pad(dechex(max(0,min(255,(int)$n))),2,'0',STR_PAD_LEFT));
  return $toH($m[1]).$toH($m[2]).$toH($m[3]);
}
function hex_to_rgb(string $hex): array {
  $h = normalize_hex6($hex);
  if (!$h) return [null,null,null];
  return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))];
}
function parse_rgb_triplet(?string $rgb): array {
  if (!$rgb) return [null,null,null];
  if (!preg_match('/(\d+)[,\s]+(\d+)[,\s]+(\d+)/', $rgb, $m)) return [null,null,null];
  return [max(0,min(255,(int)$m[1])), max(0,min(255,(int)$m[2])), max(0,min(255,(int)$m[3]))];
}
function standardize_code(?string $code): string {
  $c = strtoupper(trim((string)$code));
  if (preg_match('/(\d{1,5})/', $c, $m)) {
    return 'DEW-' . $m[1]; // normalize DET/DEA/DEW -> DEW-####
  }
  return $c;
}
function norm_code_keys(string $code): array {
  $c = strtoupper(trim($code));
  $digits = '';
  if (preg_match('/(\d{1,5})/', $c, $m)) $digits = $m[1];
  $std = $digits ? "DEW-$digits" : $c;
  return array_values(array_unique([
    $c,
    str_replace('-', '', $c),
    $std,
    str_replace('-', '', $std),
    $digits
  ]));
}

// -------- load code->hex map (optional) ----------
function load_code_hex_map(string $path, bool $verbose=false): array {
  $map = [];
  if (!is_readable($path)) { if ($verbose) fwrite(STDERR, "ℹ️  No map loaded (missing $path)\n"); return $map; }
  $fh = fopen($path, 'r'); if (!$fh) return $map;
  $header = null; $iCode = -1; $iHex = -1;
  while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') continue;
    $cols = parse_csv_line($line);
    if ($header === null) {
      $header = array_map(fn($h)=>strtolower(trim($h)), $cols);
      $iCode = array_search('code', $header);
      $iHex  = array_search('hex6', $header);
      continue;
    }
    $code = trim((string)($cols[$iCode] ?? ''));
    $hex  = normalize_hex6($cols[$iHex] ?? '');
    if (!$code || !$hex) continue;
    foreach (norm_code_keys($code) as $k) $map[$k] = $hex;
  }
  fclose($fh);
  if ($verbose) fwrite(STDOUT, "📦 Map loaded: ".count($map)." code→hex entries from $path\n");
  return $map;
}
$codeHexMap = load_code_hex_map($mapPath, $verbose);

// -------- expand inputs ----------
$files = [];
foreach (explode(',', (string)$inArg) as $part) {
  $part = trim($part);
  if ($part === '') continue;
  $matches = glob($part, GLOB_BRACE) ?: [];
  if (!$matches && file_exists($part)) $matches = [$part];
  $files = array_merge($files, $matches);
}
$files = array_values(array_unique($files));
if (!$files) { fwrite(STDERR, "No input files match: $inArg\n"); exit(1); }
if ($verbose) fwrite(STDOUT, "🗂 Inputs:\n- ".implode("\n- ", $files)."\n");

// -------- harvest unique friend nodes (A & B) ----------
/** @var array<string,array{code:string,name:string,hex:string,r:?int,g:?int,b:?int}> $nodesByHex */
$nodesByHex = [];
$seenRows = 0; $filledFromRgb = 0; $filledFromMap = 0; $skippedNoHex = 0;

foreach ($files as $f) {
  $fh = fopen($f, 'r');
  if (!$fh) { if ($verbose) fwrite(STDERR, "Cannot open $f\n"); continue; }

  $header = null; $idx = [];
  while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') continue;
    $cols = parse_csv_line($line);

    if ($header === null) {
      $header = array_map(fn($h)=>strtolower(trim($h)), $cols);
      foreach (['a_code','a_name','a_hex','a_rgb','b_code','b_name','b_hex','b_rgb'] as $h) {
        $idx[$h] = array_search($h, $header);
      }
      continue;
    }

    $seenRows++;

    // ---- side helper (closure) ----
    $harvest = function(string $side) use (&$idx, &$cols, &$codeHexMap, &$filledFromRgb, &$filledFromMap, &$skippedNoHex): void {
      $code = $idx[$side.'_code']!==false ? standardize_code($cols[$idx[$side.'_code']] ?? '') : '';
      $name = $idx[$side.'_name']!==false ? trim((string)($cols[$idx[$side.'_name']] ?? '')) : '';
      $hex  = $idx[$side.'_hex']  !==false ? normalize_hex6($cols[$idx[$side.'_hex']] ?? '') : '';
      $rgbS = $idx[$side.'_rgb']  !==false ? (string)($cols[$idx[$side.'_rgb']] ?? '') : '';

      // fill hex from rgb/map if needed
      if (!$hex && $rgbS) { $hex = normalize_hex6(rgb_to_hex($rgbS)); if ($hex) $filledFromRgb++; }
      if (!$hex && $code && $codeHexMap) {
        foreach (norm_code_keys($code) as $k) { if (isset($codeHexMap[$k])) { $hex=$codeHexMap[$k]; $filledFromMap++; break; } }
      }
      if (!$hex) { $skippedNoHex++; return; }

      // figure out r,g,b (prefer parsed rgb, else derive from hex)
      [$r,$g,$b] = parse_rgb_triplet($rgbS);
      if ($r===null || $g===null || $b===null) { [$r,$g,$b] = hex_to_rgb($hex); }

      // store / merge
      if (!isset($GLOBALS['nodesByHex'][$hex])) {
        $GLOBALS['nodesByHex'][$hex] = ['code'=>$code, 'name'=>$name, 'hex'=>$hex, 'r'=>$r, 'g'=>$g, 'b'=>$b];
      } else {
        // keep better info if missing
        if (!$GLOBALS['nodesByHex'][$hex]['code'] && $code) $GLOBALS['nodesByHex'][$hex]['code'] = $code;
        if (!$GLOBALS['nodesByHex'][$hex]['name'] && $name) $GLOBALS['nodesByHex'][$hex]['name'] = $name;
        if ($GLOBALS['nodesByHex'][$hex]['r']===null && $r!==null) $GLOBALS['nodesByHex'][$hex]['r']=$r;
        if ($GLOBALS['nodesByHex'][$hex]['g']===null && $g!==null) $GLOBALS['nodesByHex'][$hex]['g']=$g;
        if ($GLOBALS['nodesByHex'][$hex]['b']===null && $b!==null) $GLOBALS['nodesByHex'][$hex]['b']=$b;
      }
    };

    $harvest('a');
    $harvest('b');
  }
  fclose($fh);
}

if ($verbose) {
  fwrite(STDOUT, "👀 scanned rows: $seenRows\n");
  fwrite(STDOUT, "🧩 unique friend hexes: ".count($nodesByHex)."\n");
  fwrite(STDOUT, "🎯 filled from map: $filledFromMap | from rgb: $filledFromRgb | skipped(no hex on side): $skippedNoHex\n");
}

// -------- insert into colors(brand,code,name,hex6,r,g,b) ----------
$inserted = 0; $ignored = 0;

if ($dryRun) {
  echo "DRY RUN — would upsert ".count($nodesByHex)." color(s) into colors(brand,code,name,hex6,r,g,b) with brand='{$brand}'\n";
  exit(0);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->beginTransaction();

/**
 * Assumes your `colors` table has columns:
 *   brand (VARCHAR), code (VARCHAR), name (VARCHAR), hex6 (CHAR(6)), r INT, g INT, b INT
 * and a UNIQUE key that prevents duplicates (e.g., UNIQUE(brand,hex6) or UNIQUE(brand,code)).
 */
$stmt = $pdo->prepare("INSERT IGNORE INTO `colors` (`brand`,`code`,`name`,`hex6`,`r`,`g`,`b`) VALUES (?, ?, ?, ?, ?, ?, ?)");

foreach ($nodesByHex as $hex => $info) {
  $code = $info['code'] ?? '';
  $name = $info['name'] ?? '';
  $r = $info['r']; $g = $info['g']; $b = $info['b'];
  if ($r===null || $g===null || $b===null) { [$r,$g,$b] = hex_to_rgb($hex); } // last safety
  $stmt->execute([$brand, $code, $name, $hex, $r, $g, $b]);
  if ($stmt->rowCount() > 0) $inserted++; else $ignored++;
}
$pdo->commit();

echo "✅ Inserted {$inserted} color(s). Ignored (dupes) {$ignored}. Total considered: ".count($nodesByHex)."\n";
