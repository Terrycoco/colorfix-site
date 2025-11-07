<?php
define('PROJECT_ROOT', __DIR__);
require_once PROJECT_ROOT . '/logs/logger.php';
ini_set('display_errors', 0); // Don't show errors to the browser
ini_set('log_errors', 1);     // Log all errors
ini_set('error_log', __DIR__ . '/logs/error.log'); // Log file location
error_reporting(E_ALL);       // Report ALL errors, even notices and warnings
