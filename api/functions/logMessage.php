<?php

function logMessage($message) {
    $logDir = __DIR__ . '/../log';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] $message\n";

    file_put_contents("$logDir/colors-update.log", $logLine, FILE_APPEND);
}
?>