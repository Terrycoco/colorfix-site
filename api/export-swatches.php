<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    ob_end_clean();
}

require_once __DIR__ . '/db.php';

$brand = trim((string)($_GET['brand'] ?? ''));
if ($brand === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'brand required';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT name, code, r, g, b
       FROM colors
      WHERE brand = :brand
        AND is_inactive = 0
      ORDER BY name"
);
$stmt->execute([':brand' => $brand]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$swatches = [];
foreach ($rows as $row) {
    if ($row['r'] === null || $row['g'] === null || $row['b'] === null) {
        continue;
    }

    $name = trim((string)($row['name'] ?? ''));
    $code = trim((string)($row['code'] ?? ''));
    $label = trim($name . ' ' . $code);

    $swatches[] = [
        'name' => $label,
        'name_length' => mb_strlen($label, 'UTF-8') + 1,
        'r' => max(0, min(255, (int)$row['r'])) * 257,
        'g' => max(0, min(255, (int)$row['g'])) * 257,
        'b' => max(0, min(255, (int)$row['b'])) * 257,
    ];
}

function packUint16(int $value): string
{
    return pack('n', $value & 0xffff);
}

function packUint32(int $value): string
{
    return pack('N', $value);
}

function buildColorRecord(array $swatch): string
{
    return
        packUint16(0) .
        packUint16((int)$swatch['r']) .
        packUint16((int)$swatch['g']) .
        packUint16((int)$swatch['b']) .
        packUint16(0);
}

function encodeUtf16Be(string $value): string
{
    return mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
}

$count = count($swatches);

$version1 = packUint16(1) . packUint16($count);
foreach ($swatches as $swatch) {
    $version1 .= buildColorRecord($swatch);
}

$version2 = packUint16(2) . packUint16($count);
foreach ($swatches as $swatch) {
    $nameData = encodeUtf16Be($swatch['name']) . "\x00\x00";
    $version2 .= buildColorRecord($swatch);
    $version2 .= packUint32((int)$swatch['name_length']);
    $version2 .= $nameData;
}

$binary = $version1 . $version2;
$filename = str_replace(["\r", "\n", '"'], '', $brand) . '.aco';

if (function_exists('header_remove')) {
    header_remove('Content-Type');
    header_remove('Content-Length');
    header_remove('Content-Encoding');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($binary));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo $binary;
exit;
