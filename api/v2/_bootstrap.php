<?php
// PHP 7.2+ compatible JSON bootstrap for v2 endpoints

// Always JSON
header('Content-Type: application/json; charset=UTF-8');

// Never emit HTML errors
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

// Buffer EVERYTHING so we can suppress stray output
if (!ob_get_level()) { ob_start(); }

// Simple file logger
if (!defined('ADV_LOG')) {
  define('ADV_LOG', __DIR__ . '/../logs/advanced-search.log');
}
if (!function_exists('adv_log')) {
  function adv_log($lvl, $msg, $ctx = array()) {
    $dir = dirname(ADV_LOG);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @error_log(json_encode(array(
      'ts'  => date('c'),
      'lvl' => $lvl,
      'msg' => $msg,
      'ctx' => $ctx,
      'uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
      'ip'  => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
    ), JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, ADV_LOG);
  }
}

// Flag to know if we emitted a well-formed JSON response
$GLOBALS['__JSON_OK__'] = false;

// Convert PHP notices/warnings to exceptions
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
  if (!(error_reporting() & $errno)) return false;
  adv_log('php_error', $errstr, array('file'=>$errfile,'line'=>$errline,'no'=>$errno));
  throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Final safety net for fatals/parse errors
register_shutdown_function(function () {
  if ($GLOBALS['__JSON_OK__'] === true) return;
  $e = error_get_last();
  if ($e && ($e['type'] & (E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR))) {
    adv_log('shutdown_fatal', $e['message'], array('file'=>$e['file'],'line'=>$e['line'],'type'=>$e['type']));
  }
  // Kill any buffered HTML and return JSON
  while (ob_get_level()) ob_end_clean();
  http_response_code(500);
  echo json_encode(array('ok'=>false, 'error'=>'Server error'));
});

// Helper to emit JSON and exit cleanly
if (!function_exists('json_exit')) {
  function json_exit($payload, $status = 200) {
    $GLOBALS['__JSON_OK__'] = true;
    while (ob_get_level()) ob_end_clean();
    http_response_code($status);
    echo json_encode($payload);
    exit;
  }
}
