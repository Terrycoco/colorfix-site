<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';
use App\REX\Endpoints\RexResolveEndpoint;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$checks = 0;
function request(PDO $pdo, string $token, int $status, ?string $error): array {
    global $checks;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['token' => $token];
    http_response_code(200);
    ob_start();
    RexResolveEndpoint::handle($pdo);
    $body = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    if (http_response_code() !== $status || ($error !== null && $body['error'] !== $error)) {
        throw new RuntimeException('Unexpected resolver response: ' . json_encode([$status, http_response_code(), $body]));
    }
    $checks++;
    return $body;
}
// A database failure is 500 and must not expose SQLite/SQL exception details.
request($pdo, 'test', 500, 'Unable to load this ColorFix link right now. Please try again.');
$pdo->exec('CREATE TABLE rex_reservations (id INTEGER, token TEXT, label TEXT, admin_note TEXT, resolver_key TEXT, resource_type TEXT, resource_id INTEGER, context_json TEXT, status TEXT, revoked_at TEXT, created_at TEXT, updated_at TEXT)');
request($pdo, 'missing', 404, 'Link not found.');
$pdo->exec("INSERT INTO rex_reservations VALUES (1,'disabled','Fixture',NULL,'route','route',1,'{}','inactive',NULL,NULL,NULL)");
request($pdo, 'disabled', 404, 'Link not found.');
$pdo->exec("UPDATE rex_reservations SET status='active', resolver_key='unknown'");
request($pdo, 'disabled', 500, 'Unable to load this ColorFix link right now. Please try again.');
$pdo->exec("UPDATE rex_reservations SET resolver_key='route',resource_id=999999");
request($pdo, 'disabled', 404, 'Link not found.');
request($pdo, '', 400, 'REX token is required.');
echo "PASS: $checks resolver error/status checks\n";
