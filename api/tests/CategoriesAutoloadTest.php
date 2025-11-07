<?php
declare(strict_types=1);

use App\Repos\PdoCategoryRepository;
use App\Services\CategoriesService;

test('Autoload: Category classes resolve', function () {
    assert_true(class_exists(PdoCategoryRepository::class, true), 'PdoCategoryRepository missing (App\\Repos)');
    assert_true(class_exists(CategoriesService::class, true), 'CategoriesService missing (App\\Services)');
    assert_true(class_exists(\App\Lib\Logger::class, true), 'Logger missing (App\\Lib\\Logger)');
});
