<?php
require_once __DIR__.'/api/autoload.php';
require_once __DIR__.'/api/db.php';
use App\Repos\PdoAppliedPaletteRepository;
$repo = new PdoAppliedPaletteRepository($pdo);
var_dump($repo->listPalettes([], 10, 0));
