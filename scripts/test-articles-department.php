<?php
declare(strict_types=1);

// Autoload/constructor checks only: no application database or HTTP bootstrap.
require_once __DIR__ . '/../api/autoload.php';

use App\ARTICLES\Controllers\ArticleController;
use App\ARTICLES\Services\ArticleService;
use App\ARTICLES\Repos\PdoArticleRepository;

$root = dirname(__DIR__);
foreach ([ArticleController::class, ArticleService::class, PdoArticleRepository::class] as $class) {
    if (!class_exists($class)) throw new RuntimeException("Cannot autoload {$class}");
    $path = (new ReflectionClass($class))->getFileName();
    if (!str_starts_with($path, $root . '/app/ARTICLES/')) throw new RuntimeException("Wrong class location: {$path}");
}
// A PDO stand-in guarantees construction and validation cannot contact any DB.
$pdo = new class extends PDO { public function __construct() {} };
$repo = new PdoArticleRepository($pdo);
$controller = new ArticleController(new ArticleService($repo));
try {
    $controller->save(['title' => '', 'slug' => '']);
    throw new RuntimeException('Expected existing required-field validation');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'title and slug required') throw $error;
}

$endpoints = array_merge(glob($root . '/api/v2/admin/articles/*.php'), glob($root . '/api/v2/articles/*.php'));
foreach ($endpoints as $file) {
    $source = file_get_contents($file);
    preg_match_all('/^use (App\\\\ARTICLES\\\\[^;]+);/m', $source, $matches);
    if (!$matches[1]) throw new RuntimeException("No ARTICLES import: {$file}");
    foreach ($matches[1] as $class) {
        if (!class_exists($class)) throw new RuntimeException("Unresolved {$class} in {$file}");
    }
}
foreach (['Controllers/ArticleController', 'Services/ArticleService', 'Repos/PdoArticleRepository'] as $suffix) {
    if (class_exists('App\\' . str_replace('/', '\\', $suffix))) throw new RuntimeException("Legacy class still active: {$suffix}");
    [$folder, $name] = explode('/', $suffix);
    if (file_exists($root . '/app/' . strtolower($folder) . '/' . $name . '.php')) throw new RuntimeException("Legacy file still present: {$suffix}");
}
foreach ([App\ANA\Services\ANAReportService::class, App\Analytics\Services\AnalyticsService::class] as $class) {
    $params = (new ReflectionMethod($class, '__construct'))->getParameters();
    if ($params[2]->getType()->getName() !== PdoArticleRepository::class) throw new RuntimeException("Stale dependency in {$class}");
}
echo 'PASS: three migrated classes, constructors/validation, ' . count($endpoints) . " API import sets, reporting type dependencies, and legacy retirement.\n";
