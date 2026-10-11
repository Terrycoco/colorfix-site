<?php
declare(strict_types=1);

/**
 * One-time CLI-only integration test for PUB Dispatch -> ERR.
 * Tests ERR reporting + notification, without shipping or changing pub_assets.
 * Creates an intentionally synthetic ERR Working/History record; delete it in
 * Admin -> ERR -> Working after inspecting it.
 *
 * Usage: php scripts/test-pub-dispatch-err.php auth
 *        php scripts/test-pub-dispatch-err.php unknown
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$kind = $argv[1] ?? '';
if (!in_array($kind, ['auth', 'unknown'], true)) {
    fwrite(STDERR, "Usage: php scripts/test-pub-dispatch-err.php auth|unknown\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/api/autoload.php';
require_once $root . '/api/db.php';

if (!isset($pdo) || !$pdo instanceof \PDO) {
    fwrite(STDERR, "ColorFix api/db.php did not provide PDO.\n");
    exit(1);
}

// These IDs are deliberately not real PUB assets. Do NOT click Recover on them.
$fakeAssetId = $kind === 'auth' ? 900000002 : 900000003;
$providerCode = $kind === 'auth' ? 'invalid_grant' : 'unrecognized_provider_failure';
$message = $kind === 'auth'
    ? 'TEST ONLY: invalid_grant - account reconnection is required.'
    : 'TEST ONLY: unexpected API failure from a publishing provider.';
$asset = [
    'pub_asset_id' => $fakeAssetId,
    'channel' => 'youtube',
    'search_title' => 'TEST ONLY - ERR ' . ($kind === 'auth' ? '4002' : '4003'),
];

try {
    $dispatch = new \App\PUB\Dispatch\DispatchManager($pdo, $root);
    // Invoke the exact production classifier/reporting method, but bypass
    // shipOne(), external APIs, and all PUB asset state changes.
    $method = new \ReflectionMethod($dispatch, 'reportPublishingFailure');
    $method->invoke($dispatch, $asset, $fakeAssetId, $providerCode, $message);

    $stmt = $pdo->prepare(
        'SELECT id, err_num, handling_status, handling_result '
        . 'FROM err_log WHERE department = :department '
        . 'AND resource_type = :resource_type AND resource_id = :resource_id '
        . 'ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([
        ':department' => 'PUB',
        ':resource_type' => 'pub_asset',
        ':resource_id' => $fakeAssetId,
    ]);
    $log = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$log) {
        throw new \RuntimeException('No ERR History row found. Check app/ERR/err_fallback.log and PHP error_log.');
    }
    $expected = $kind === 'auth' ? 4002 : 4003;
    echo "Created TEST ERR History #{$log['id']}, code {$log['err_num']}, status {$log['handling_status']}\n";
    if ((int)$log['err_num'] !== $expected) {
        throw new \RuntimeException("Expected ERR {$expected}; received {$log['err_num']}.");
    }
    echo "Check ERR -> Working for TEST ONLY - ERR {$expected}, and your notification inbox.\n";
    echo "After confirming, DELETE the TEST ONLY record from Working. Do not click Recover.\n";
    echo "Remove scripts/test-pub-dispatch-err.php from the server afterward.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Dispatch ERR test failed: ' . $e->getMessage() . "\n");
    exit(1);
}
