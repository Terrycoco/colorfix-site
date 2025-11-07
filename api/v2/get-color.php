<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

@ini_set('display_errors','0'); // don't leak warnings into JSON
@ini_set('log_errors','1');

// Buffer output so we can detect empties/fatals and still return JSON
ob_start();
register_shutdown_function(function () {
    $out = ob_get_contents();
    if ($out === '' || $out === false) {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
            http_response_code(500);
            $msg = $err['message'] . ' @ ' . $err['file'] . ':' . $err['line'];
            echo json_encode(['ok' => false, 'error' => 'FATAL: ' . $msg], JSON_UNESCAPED_SLASHES);
        }
    }
});

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoColorDetailRepository; // <-- fixed casing

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Missing or invalid color ID'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $repo  = new PdoColorDetailRepository($pdo);
    $color = $repo->getById($id);
    if (!$color) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'Color not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    echo json_encode(['ok'=>true,'color'=>$color], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
