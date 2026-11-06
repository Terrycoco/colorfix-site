<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || getenv('COLORFIX_HEX_TEST') !== '1') { http_response_code(404); exit; }
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/HexSearchFixture.php';

\App\COLORS\Endpoints\HexSearchEndpoint::handle(hexSearchFixture());
