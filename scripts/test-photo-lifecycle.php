<?php
declare(strict_types=1);
// Deliberately does not load api/db.php or the production-oriented test runner.
$tests = [];
function test(string $name, callable $callback): void { global $tests; $tests[$name] = $callback; }
function assert_true($value, string $message = ''): void { if (!$value) { throw new RuntimeException($message ?: 'Assertion failed'); } }
function assert_equals($expected, $actual, string $message = ''): void { if ($expected != $actual) { throw new RuntimeException($message ?: var_export([$expected, $actual], true)); } }
require __DIR__ . '/../api/tests/PhotoLibraryUsageTest.php';
require __DIR__ . '/../api/tests/PhotoLifecycleTest.php';
foreach (['PhotoLibraryPaletteTest', 'PhotoLibraryPaletteMigrationTest', 'ProjectPhotosPlaylistTest', 'PVManagementTest', 'SavedPaletteMembershipTest', 'SASPlaylistTest', 'PlaylistViewerTargetsTest', 'PlaylistThumbsResolverTest', 'PlaylistSetExperienceTest'] as $suite) {
    require_once __DIR__ . '/../api/tests/' . $suite . '.php';
}
require_once __DIR__ . '/../api/tests/PhotoRetirementTest.php';
$failed = 0;
foreach ($tests as $name => $callback) {
    try { $callback(); echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n"; }
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
exit($failed ? 1 : 0);
