<?php
declare(strict_types=1);

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/functions/watch-config.php';

$reservationKey = 'business-card:qr';
$source = trim((string)($_GET['src'] ?? 'qr'));
if ($source === '') {
    $source = 'qr';
}

$config = loadWatchConfig($pdo ?? null);
$configuredPlaylistInstanceId = (int)($config['playlist_instance_id'] ?? 0);
$row = null;

if ($configuredPlaylistInstanceId > 0) {
    $stmt = $pdo->prepare(
        'SELECT playlist_instance_id, slug
           FROM playlist_instances
          WHERE playlist_instance_id = :playlist_instance_id
            AND is_active = 1
          LIMIT 1'
    );
    $stmt->execute([':playlist_instance_id' => $configuredPlaylistInstanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$row) {
    $stmt = $pdo->prepare(
    'SELECT r.playlist_instance_id, pi.slug
       FROM playlist_instance_url_reservations r
  LEFT JOIN playlist_instances pi
         ON pi.playlist_instance_id = r.playlist_instance_id
      WHERE r.reservation_key = :reservation_key
        AND r.status IN ("reserved", "claimed")
      LIMIT 1'
    );
    $stmt->execute([':reservation_key' => $reservationKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$row || empty($row['playlist_instance_id'])) {
    $fallback = $pdo->query(
        'SELECT playlist_instance_id, slug
           FROM playlist_instances
          WHERE audience = "qr"
            AND is_active = 1
       ORDER BY playlist_instance_id DESC
          LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC) ?: null;
    $row = $fallback;
}

if (!$row || empty($row['playlist_instance_id'])) {
    header('Location: /', true, 302);
    exit;
}

$target = trim((string)($row['slug'] ?? ''));
if ($target === '') {
    $target = (string)(int)$row['playlist_instance_id'];
}

$params = $_GET;
$params['src'] = $source;
$query = http_build_query($params);
$location = '/p/' . rawurlencode($target) . ($query !== '' ? '?' . $query : '');
header('Location: ' . $location, true, 302);
exit;
