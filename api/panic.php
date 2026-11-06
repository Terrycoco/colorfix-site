<?php

require_once __DIR__ . '/v2/admin/auth.php'; // SYSTEM admin validation

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Try including the target file to force a compile-time error to show
include __DIR__ . '/browse-palettes.php';

echo "OK\n";
