<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

$token = (string)($_GET['token'] ?? '');
if ($token === '' && isset($_COOKIE['cf_device_token'])) {
  $token = (string)$_COOKIE['cf_device_token'];
}
if ($token === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'missing token']);
  exit;
}

$tokenFile = __DIR__ . '/data/device_tokens.json';
$tokens = [];
if (is_file($tokenFile)) {
  $raw = @file_get_contents($tokenFile);
  $decoded = $raw ? json_decode($raw, true) : null;
  if (is_array($decoded)) $tokens = $decoded;
}

if (!isset($tokens[$token])) {
  http_response_code(401);
  echo json_encode(['ok' => false]);
  exit;
}

$tokens[$token]['last_used'] = date('c');
@file_put_contents($tokenFile, json_encode($tokens, JSON_UNESCAPED_SLASHES));

$user = [
  'id' => 'terry',
  'email' => 'terrymarr280@gmail.com',
  'firstname' => 'Terry',
  'name' => 'Terry Marr',
  'is_admin' => true,
];

echo json_encode(['ok' => true, 'user' => $user]);
