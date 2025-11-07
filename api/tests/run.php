<?php
declare(strict_types=1);

// Start output buffering so accidental whitespace/echo won't break headers
ob_start();

// Send JSON header immediately
header('Content-Type: application/json; charset=UTF-8');

// Quiet, per-run cache reset (no output)
if (function_exists('opcache_reset')) { @opcache_reset(); }
if (function_exists('apcu_clear_cache')) { @apcu_clear_cache(); }
clearstatcache();

@ini_set('display_errors','0');
@ini_set('log_errors','1');
@ini_set('error_log', __DIR__ . '/../php_error.log');

// return JSON for PHP errors/exceptions/fatals
set_error_handler(function($sev,$msg,$file,$line){
  http_response_code(500);
  echo json_encode(['ok'=>false,'where'=>'tests/run.php:error','error'=>"PHP error: $msg",'at'=>"$file:$line"]);
  // Flush buffer to avoid more output surprises
  if (ob_get_level()) { @ob_end_flush(); }
  exit;
});
set_exception_handler(function($e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'where'=>'tests/run.php:exception','error'=>$e->getMessage(),'trace'=>substr($e->getTraceAsString(),0,400)]);
  if (ob_get_level()) { @ob_end_flush(); }
  exit;
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'where'=>'tests/run.php:shutdown','fatal'=>$e['message'],'at'=>$e['file'].':'.$e['line']]);
    if (ob_get_level()) { @ob_end_flush(); }
  }
});

// Autoload app code (MUST be before ReflectionClass)
require_once __DIR__ . '/../autoload.php';


// Autoload app code (MUST be before ReflectionClass)
require_once __DIR__ . '/../autoload.php';

if (class_exists(\ColorFix\Lib\Logger::class, true)) {
    @error_log('[autoload] Logger file='.(new \ReflectionClass(\ColorFix\Lib\Logger::class))->getFileName());
}
if (class_exists(\App\repos\PdoColorRepository::class, true)) {
    @error_log('[autoload] PdoColorRepository file='.(new \ReflectionClass(\App\repos\PdoColorRepository::class))->getFileName());
}


// Log the exact files PHP is using
if (class_exists(\App\repos\PdoColorRepository::class, true)) {
    @error_log('[tests] PdoColorRepository file='.(new \ReflectionClass(\App\repos\PdoColorRepository::class))->getFileName());
} else {
    @error_log('[tests] PdoColorRepository NOT FOUND');
}
if (class_exists(\App\services\ColorSaveService::class, true)) {
    @error_log('[tests] ColorSaveService file='.(new \ReflectionClass(\App\services\ColorSaveService::class))->getFileName());
} else {
    @error_log('[tests] ColorSaveService NOT FOUND');
}


// Pin the exact files so we know what's executing (from /api/tests → /app)
$APP = dirname(__DIR__, 2) . '/app';
require_once $APP . '/repos/PdoColorRepository.php';
require_once $APP . '/services/ColorSaveService.php';





// Optional DB bootstrap (for tests that need $pdo)
$haveDb = is_file(__DIR__ . '/../db.php');
if ($haveDb) require_once __DIR__ . '/../db.php';

// --- Minimal test framework ---
$TESTS = [];
$CURRENT_TEST_FILE = null; // <— add this

/** Register a test */

/** Register a test */
function test(string $name, callable $fn): void {
    global $TESTS, $CURRENT_TEST_FILE;
    // Prefer the file currently being loaded; fall back to backtrace
    $file = $CURRENT_TEST_FILE;
    if ($file === null) {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $file = $bt[1]['file'] ?? __FILE__;
    }
    $TESTS[] = ['name' => $name, 'fn' => $fn, 'file' => $file];
}

function assert_true(bool $cond, string $msg = 'assert_true failed'): void {
    if (!$cond) throw new RuntimeException($msg);
}

function assert_equals(mixed $a, mixed $b, string $msg = 'assert_equals failed'): void {
    if ($a !== $b) {
        $msg .= " (got " . var_export($a, true) . ", expected " . var_export($b, true) . ")";
        throw new RuntimeException($msg);
    }
}

function assert_approx(float $a, float $b, float $eps = 1e-6, string $msg = 'assert_approx failed'): void {
    if (abs($a - $b) > $eps) {
        $msg .= " (got {$a}, expected {$b} ± {$eps})";
        throw new RuntimeException($msg);
    }
}

// --- Load all *Test.php files in this folder ---
// --- Load all *Test.php files in this folder ---
$testFiles = glob(__DIR__ . '/*Test.php');
foreach ($testFiles as $file) {
    if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
    $CURRENT_TEST_FILE = $file;          // <— set before require
    require_once $file;
}
$CURRENT_TEST_FILE = null;  


// --- Run tests ---
$results = [];
$passes = 0;
$fails  = 0;

foreach ($TESTS as $t) {
    $name = $t['name'];
    try {
        $ctx = [
            'haveDb' => $haveDb,
            'pdo'    => $haveDb ? ($pdo ?? null) : null,
        ];
        ($t['fn'])($ctx);
        $results[] = ['file' => basename($t['file']), 'name' => $name, 'pass' => true];
        $passes++;
    } catch (Throwable $e) {
        $results[] = [
            'file'  => basename($t['file']),
            'name'  => $name,
            'pass'  => false,
            'error' => $e->getMessage()
        ];
        $fails++;
    }
}


echo json_encode([
    'summary' => ['total' => count($TESTS), 'passes' => $passes, 'fails' => $fails],
    'results' => $results,
], JSON_UNESCAPED_SLASHES);
