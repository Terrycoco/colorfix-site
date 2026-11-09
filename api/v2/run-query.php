<?php
declare(strict_types=1);

// Older cached frontend bundles still use this URL. Preserve POST bodies.
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /api/v2/gallery/run-query.php' . ($query !== '' ? '?' . $query : ''), true, 307);
exit;
