<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;

test('Photo Library repository autoloads from the PHOTOS module', function () {
    assert_true(class_exists(PdoPhotoLibraryRepository::class));
    $class = new ReflectionClass(PdoPhotoLibraryRepository::class);
    assert_equals(
        realpath(__DIR__ . '/../../app/PHOTOS/Repos/PdoPhotoLibraryRepository.php'),
        $class->getFileName()
    );
    assert_true(!is_file(__DIR__ . '/../../app/repos/PdoPhotoLibraryRepository.php'));
});
