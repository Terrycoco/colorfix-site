<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\repos\PdoPaletteRepository;

$pid = (int)($_GET['palette_id'] ?? 0);
if ($pid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'palette_id required']); exit; }

$repo = new PdoPaletteRepository($pdo);
$tags = $repo->getTagsForPalette($pid);
echo json_encode(['ok'=>true,'tags'=>$tags], JSON_UNESCAPED_SLASHES);
