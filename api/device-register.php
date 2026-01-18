<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method not allowed']);
  exit;
}

$isAdmin = (isset($_COOKIE['cf_admin']) && $_COOKIE['cf_admin'] === '1')
  || (isset($_COOKIE['cf_admin_global']) && $_COOKIE['cf_admin_global'] === '1');

if (!$isAdmin) {
  http_response_code(401);
  echo json_encode(['ok' => false]);
  exit;
}

$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input') ?: '';
$input = [];

if (stripos($ctype, 'application/x-www-form-urlencoded') !== false) {
  $input = $_POST;
} elseif (stripos($ctype, 'application/json') !== false) {
  $input = json_decode($raw, true) ?: [];
} else {
  $input = !empty($_POST) ? $_POST : (json_decode($raw, true) ?: []);
}

$token = (string)($input['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{32,}$/i', $token)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid token']);
  exit;
}

$tokenFile = __DIR__ . '/data/device_tokens.json';
$tokenDir = dirname($tokenFile);
if (!is_dir($tokenDir)) @mkdir($tokenDir, 0755, true);

$tokens = [];
if (is_file($tokenFile)) {
  $raw = @file_get_contents($tokenFile);
  $decoded = $raw ? json_decode($raw, true) : null;
  if (is_array($decoded)) $tokens = $decoded;
}

$tokens[$token] = [
  'created_at' => $tokens[$token]['created_at'] ?? date('c'),
  'last_used' => null,
  'label' => 'register',
];

@file_put_contents($tokenFile, json_encode($tokens, JSON_UNESCAPED_SLASHES));

echo json_encode(['ok' => true]);
