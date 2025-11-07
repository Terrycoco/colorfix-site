<?php
// TEMP DIAGNOSTIC PING — no bootstrap, no includes
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '1');          // show errors instead of silent 500
ini_set('log_errors', '1');

$docRoot   = $_SERVER['DOCUMENT_ROOT'] ?? '(unset)';
$script    = __FILE__;
$dir       = __DIR__;
$phpSapi   = php_sapi_name();
$errLogIni = ini_get('error_log');

$bootstrap = $docRoot . '/colorfix/app/bootstrap.php';
$logDir    = $docRoot . '/colorfix/logs';
$logFile   = $logDir . '/latest.log';

$out = [
  'ok'          => true,
  'ts'          => date('c'),
  'php_sapi'    => $phpSapi,
  'document_root' => $docRoot,
  'script_file' => $script,
  'script_dir'  => $dir,
  'bootstrap_path' => $bootstrap,
  'bootstrap_exists' => file_exists($bootstrap),
  'bootstrap_readable' => is_readable($bootstrap),
  'logs_dir'    => $logDir,
  'logs_dir_exists' => is_dir($logDir),
  'log_file'    => $logFile,
  'log_writable' => is_writable($logDir),  // can we write in logs/
  'error_log_ini' => $errLogIni,
];

echo json_encode($out);
