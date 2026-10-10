<?php
declare(strict_types=1);

test('archived baseline migrations retain their bytes and cannot be replayed as active files', function () {
    $database = __DIR__ . '/../../database';
    $archive = $database . '/archive/2026_10_10_baseline';
    $manifest = json_decode(file_get_contents($archive . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    assert_equals(176, count($manifest['files']));
    foreach ($manifest['files'] as $file) {
        assert_equals($file['sha256'], hash_file('sha256', $archive . '/' . $file['filename']));
        assert_true(!is_file($database . '/migrations/' . $file['filename']), 'Archived migration must not be active');
    }
    assert_true(is_dir($database . '/migrations'));
});
