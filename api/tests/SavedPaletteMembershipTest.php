<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\Repos\PdoSavedPaletteRepository;

test('palette membership check does not require swatch_view or color details', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE saved_palette_members (saved_palette_id INTEGER, color_id INTEGER)');
    $pdo->exec('INSERT INTO saved_palette_members VALUES (124, 1), (124, 2), (133, 999)');
    $repo = new PdoSavedPaletteRepository($pdo);

    assert_true($repo->hasMembersForPalette(124));
    assert_true($repo->hasMembersForPalette(133));
    assert_true(!$repo->hasMembersForPalette(136));
});
