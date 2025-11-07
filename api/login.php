<?php
declare(strict_types=1);

/**
 * /api/login.php — NO CORS HEADERS (Apache sends ACAO:*), form-or-JSON, logs.
 */

if (ob_get_level() === 0) ob_start();         // avoid "headers already sent"
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/logs/php-errors.log');
// DO NOT set any Access-Control-* headers here.

header('Content-Type: application/json; charset=utf-8'); // ok to keep

function log_line(string $lvl, string $msg, array $ctx=[]): void {
  $line = json_encode([
    'ts'=>date('c'),'lvl'=>$lvl,'msg'=>$msg,'ctx'=>$ctx,
    'uri'=>$_SERVER['REQUEST_URI'] ?? null,'ip'=>$_SERVER['REMOTE_ADDR'] ?? null,
  ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
  foreach ([sys_get_temp_dir().'/colorfix-login.log', __DIR__.'/logs/login.log'] as $p) {
    $d = dirname($p); if (!is_dir($d)) @mkdir($d,0755,true);
    @error_log($line, 3, $p);
  }
}

log_line('boot','login.php hit',['method'=>$_SERVER['REQUEST_METHOD'] ?? null,'ctype'=>$_SERVER['CONTENT_TYPE'] ?? null]);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  if (ob_get_length()) ob_clean();
  echo json_encode(['success'=>false,'message'=>'Method not allowed (use POST)']);
  if (ob_get_level()) ob_end_flush();
  exit;
}

// Read body: prefer form (simple request); also accept JSON
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
$raw   = file_get_contents('php://input') ?: '';
$input = [];

if (stripos($ctype,'application/x-www-form-urlencoded') !== false) {
  $input = $_POST;
} elseif (stripos($ctype,'application/json') !== false) {
  $input = json_decode($raw, true) ?: [];
} else {
  $input = !empty($_POST) ? $_POST : (json_decode($raw, true) ?: []);
}

$email = trim((string)($input['email'] ?? ''));
$pass  = trim((string)($input['password'] ?? ''));

if ($email === '' || $pass === '') {
  log_line('missing_fields','email or password missing',['ctype'=>$ctype]);
  http_response_code(400);
  if (ob_get_length()) ob_clean();
  echo json_encode(['success'=>false,'message'=>'Missing email or password']);
  if (ob_get_level()) ob_end_flush();
  exit;
}

log_line('login_attempt','incoming',['email'=>$email]);

// TEMP auth
$real_email='terrymarr280@gmail.com';
$real_password='sunshine88';

if ($email === $real_email && $pass === $real_password) {
  $user = ['id'=>'terry','email'=>$real_email,'firstname'=>'Terry','name'=>'Terry Marr','is_admin'=>true];
  log_line('login_success','ok',['email'=>$real_email]);
  if (ob_get_length()) ob_clean();
  echo json_encode(['success'=>true,'user'=>$user]);
  if (ob_get_level()) ob_end_flush();
  exit;
}

log_line('login_fail','invalid creds',['email'=>$email]);
http_response_code(401);
if (ob_get_length()) ob_clean();
echo json_encode(['success'=>false,'message'=>'Invalid credentials']);
if (ob_get_level()) ob_end_flush();
exit;
