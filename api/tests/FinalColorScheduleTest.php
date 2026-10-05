<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PROJECTS\Managers\ProjectDocumentManager;

test('final color schedule adds safe hex swatches beside color names', function () {
    $manager = new ProjectDocumentManager(new PDO('sqlite::memory:'));
    $method = new ReflectionMethod($manager, 'finalColorScheduleHtml');
    $palettes = [[
        'is_final' => 1, 'area_label' => 'Living Room', 'nickname' => 'Final Colors',
        'colors' => [
            ['color_name' => 'Gunsmoke', 'color_hex6' => '878573', 'color_brand_name' => 'Dunn Edwards', 'role' => 'Walls'],
            ['color_name' => 'White', 'color_hex6' => '#FFFFFF'],
            ['color_name' => 'Unknown & safe', 'color_hex6' => 'bad" onclick="alert(1)'],
            ['color_name' => 'Missing'],
        ],
    ], [
        'is_final' => 0, 'colors' => [['color_name' => 'Not final', 'color_hex6' => '123456']],
    ]];
    $html = $method->invoke($manager, $palettes);
    assert_equals(2, substr_count($html, 'class="document-color-schedule__swatch"'));
    assert_true(str_contains($html, 'background-color:#878573;'));
    assert_true(str_contains($html, 'background-color:#FFFFFF;'));
    assert_true(str_contains($html, 'class="document-color-schedule__name">Gunsmoke'));
    assert_true(str_contains($html, 'style="display:none">, Dunn Edwards</span>'));
    assert_true(str_contains($html, 'grid-row: 1 / 3;'));
    assert_true(str_contains($html, '@media screen and (max-width: 560px)'));
    assert_true(str_contains($html, 'width:28px;height:28px;'));
    assert_true(str_contains($html, 'print-color-adjust:exact;'));
    assert_true(str_contains($html, 'Unknown &amp; safe'));
    assert_true(!str_contains($html, 'onclick'));
    assert_true(!str_contains($html, 'Not final'));
    $plain = (new ReflectionMethod($manager, 'finalColorSchedulePlain'))->invoke($manager, $palettes);
    assert_true(str_contains($plain, 'Gunsmoke | Dunn Edwards | Walls'));
    assert_true(!str_contains($plain, '<span'));
});
