<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}

$isAdmin = (isset($_COOKIE['cf_admin']) && $_COOKIE['cf_admin'] === '1')
  || (isset($_COOKIE['cf_admin_global']) && $_COOKIE['cf_admin_global'] === '1');

if (!$isAdmin) {
  http_response_code(401);
  echo json_encode(['ok' => false]);
  exit;
}

$user = [
  'id' => 'terry',
  'email' => 'terrymarr280@gmail.com',
  'firstname' => 'Terry',
  'name' => 'Terry Marr',
  'is_admin' => true,
];

echo json_encode(['ok' => true, 'user' => $user]);
