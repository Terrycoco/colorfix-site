<?php
declare(strict_types=1);

// Absolute path aliases (no ../ soup)
define('CF_ROOT', realpath($_SERVER['DOCUMENT_ROOT'] . '/colorfix'));
define('CF_APP',  CF_ROOT . '/app');
define('CF_LOGS', CF_ROOT . '/logs');

// Manually include core classes (no Composer)
require_once CF_APP . '/lib/Logger.php';

// Route PHP errors to the same log file
ini_set('log_errors', '1');
ini_set('error_log', CF_LOGS . '/latest.log');

// Catch uncaught exceptions
set_exception_handler(function (Throwable $e) {
    \ColorFix\Lib\Logger::error('Uncaught exception', [
        'type' => get_class($e),
        'msg'  => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status'=>500,'error'=>'Internal Server Error']);
    exit;
});
