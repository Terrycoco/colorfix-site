<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\GALLERY\Repos\PdoItemRepository;
use App\GALLERY\Endpoints\DeleteItemEndpoint;

// Isolated fixtures only: never load api/db.php or contact production.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, handle TEXT, image_url TEXT)');
$pdo->exec("INSERT INTO items VALUES (1,'','photo:746'),(2,'Keep','photo:747')");
$pdo->exec('CREATE TABLE dependent (id INTEGER PRIMARY KEY, item_id INTEGER REFERENCES items(id))');
$pdo->exec('INSERT INTO dependent VALUES (1,2)');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$repo = new PdoItemRepository($pdo);
$expect($repo->deleteById(1), 'Delete selected blank item');
$expect(!$repo->deleteById(1), 'Missing item returns false');
$expect((int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn() === 1, 'Other items are retained');
try {
    $repo->deleteById(2);
    throw new RuntimeException('Referenced deletion should fail');
} catch (PDOException $error) {
    $expect(str_starts_with((string)$error->getCode(), '23'), 'Constraint failure propagates to endpoint');
}
$expect($pdo->query('SELECT image_url FROM items WHERE id=2')->fetchColumn() === 'photo:747', 'References and image fields survive failed deletion');
foreach ([['GET','application/json',405], ['POST','text/plain',415], ['POST','application/json',400]] as [$method,$contentType,$expected]) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['CONTENT_TYPE'] = $contentType;
    ob_start(); DeleteItemEndpoint::handle($pdo); $payload = json_decode(ob_get_clean(), true);
    $expect(http_response_code() === $expected && $payload['success'] === false, 'Reject invalid method/content/body');
}
echo "PASS: isolated item deletion, exact ID, missing records, FK protection, failure preservation and endpoint rejection checks.\n";
