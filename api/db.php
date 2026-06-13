<?php
date_default_timezone_set('America/Los_Angeles');
file_put_contents(__DIR__ . '/assign.log', "[" . date('Y-m-d H:i:s') . "] 👣 entered db.php\n", FILE_APPEND);

ini_set('log_errors', 1);
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/php_error.log');



// Replace with your actual credentials
$host = 'localhost';       // or your host (sometimes 127.0.0.1 or a remote host)
$db   = 'shortgal_colorfix';   // your MySQL database name
$user = 'shortgal_colorfix_admin';   // your MySQL username
$pass = 'M0thersh1p!!';   // your MySQL password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $appTimezone = new DateTimeZone('America/Los_Angeles');
    $appOffset = (new DateTimeImmutable('now', $appTimezone))->format('P');
    $pdo->exec("SET time_zone = " . $pdo->quote($appOffset));
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}
?>
