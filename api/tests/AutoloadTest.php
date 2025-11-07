<?php
declare(strict_types=1);

test('Autoload: Logger class resolves', function () {
    assert_true(class_exists(\App\Lib\Logger::class, true), 'Logger class not found via autoload');
});

test('Autoload: PdoColorRepository resolves', function () {
    assert_true(class_exists(\App\Repos\PdoColorRepository::class, true), 'PdoColorRepository missing');
});

test('Autoload: ColorSaveService resolves', function () {
    assert_true(class_exists(\App\Services\ColorSaveService::class, true), 'ColorSaveService missing');
});
