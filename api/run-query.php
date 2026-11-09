<?php
declare(strict_types=1);

// Compatibility for cached SQL Editor clients. Preserve method/body and parameters.
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /api/v2/gallery/run-query.php' . ($query !== '' ? '?' . $query : ''), true, 307);
exit;
