<?php
// Default SMTP config. Copy this file to config/mail.local.php and fill in real password.
$config = [
    'host'       => 'mail.terrymarr.com',
    'port'       => 465,
    'username'   => 'terry@terrymarr.com',
    'password'   => '', // override in mail.local.php
    'from_email' => 'terry@terrymarr.com',
    'from_name'  => 'Terry Marr TEST',
];

$local = __DIR__ . '/mail.local.php';
if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) {
        $config = array_merge($config, $overrides);
    }
}

return $config;
