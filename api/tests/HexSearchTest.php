<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/HexSearchFixture.php';

use App\COLORS\Repos\PdoHexSearchRepository;
use App\COLORS\Services\HexSearchService;

function expectHex(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$pdo = hexSearchFixture();
$service = new HexSearchService(new PdoHexSearchRepository($pdo));
foreach (['808080', '#808080', '  #808080  '] as $input) {
    $out = $service->search($input);
    expectHex($out['hex'] === '808080', 'Normalized hex');
    $reference = $out['reference_color'];
    expectHex($reference['item_type'] === 'reference-swatch' && $reference['hex6'] === '808080', 'Computed reference swatch');
    expectHex(!array_intersect(['id', 'name', 'brand', 'brand_name'], array_keys($reference)), 'Reference has no paint identity');
    expectHex(is_finite($reference['hcl_h']) && is_finite($reference['hcl_c']) && is_finite($reference['hcl_l']), 'Computed HCL values');
    $rows = $out['results'];
    expectHex(array_column($rows, 'id') === [1, 2, 7, 3, 4, 5], 'All exact matches before closest swatches; inactive excluded');
    expectHex(array_column(array_slice($rows, 0, 3), 'hex_match_type') === ['exact', 'exact', 'exact'], 'Exact flags');
    $distances = array_column(array_slice($rows, 3), 'match_delta_e');
    $sorted = $distances; sort($sorted);
    expectHex($distances === $sorted, 'Increasing perceptual distance');
}
expectHex(HexSearchService::normalize('  #aAbBcC ') === 'AABBCC', 'Mixed case');
foreach (['', '#', 'abc', '#12345', '#1234567', 'gggggg', '12345678', '12 3456', '##808080', '808080;DROP TABLE colors'] as $invalid) {
    try { $service->search($invalid); throw new RuntimeException('Invalid input accepted'); }
    catch (InvalidArgumentException) {}
}
expectHex(array_unique(array_column($service->search('808080')['results'], 'brand')) === ['sw', 'bm', 'behr'], 'All brands included');
$noExact = $service->search('828282')['results'];
expectHex($noExact !== [] && !in_array('exact', array_column($noExact, 'hex_match_type'), true), 'Nearby colors when no exact match exists');
$black = $service->search('000000')['reference_color'];
expectHex($black['hcl_l'] === 0.0 && $black['hcl_c'] === 0.0, 'Black reference HCL');
$white = $service->search('FFFFFF')['reference_color'];
expectHex(abs($white['hcl_l'] - 100) < 0.001, 'White reference lightness');
// Exact rows must not be discarded by the nearby-result cap.
$pdo->exec("INSERT INTO colors (id,name,brand,hex6,is_inactive) SELECT id+100,'Twin','sw','808080',0 FROM colors WHERE id=1");
expectHex(array_column(array_slice($service->search('#808080')['results'], 0, 4), 'id') === [1, 2, 7, 101], 'Same-hex twins remain included');
echo "PASS: hex normalization, input validation, exact-first ranking, perceptual ordering, twins, missing Lab, active colors, all brands, no-exact fallback\n";
