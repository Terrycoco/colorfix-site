<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoPlaylistInstanceUrlReservationRepository
{
    public function __construct(private PDO $pdo) {}

    public function reserveForPlaylistChannel(int $playlistId, string $channel, string $title, string $baseUrl = ''): array
    {
        if ($playlistId <= 0) {
            throw new \InvalidArgumentException('playlist_id required');
        }
        $channel = $this->normalizeChannel($channel);
        if ($channel === '') {
            throw new \InvalidArgumentException('channel required');
        }

        $reservationKey = $this->reservationKey($playlistId, $channel);
        $existing = $this->findByKey($reservationKey);
        if ($existing) {
            return $existing;
        }

        $baseSlug = $this->slugify($title !== '' ? $title : "playlist-{$playlistId}");
        if ($channel !== 'site') {
            $baseSlug = $this->slugify($baseSlug . '-' . $channel);
        }
        $slug = $this->uniqueReservedSlug($baseSlug);
        $path = '/playlist/' . rawurlencode($slug);
        $publicUrl = $this->absoluteUrl($path, $baseUrl, $channel);

        $stmt = $this->pdo->prepare(
            'INSERT INTO playlist_instance_url_reservations
                (playlist_id, channel, reservation_key, slug, path, public_url, status, metadata_json)
             VALUES
                (:playlist_id, :channel, :reservation_key, :slug, :path, :public_url, :status, :metadata_json)'
        );
        $stmt->execute([
            ':playlist_id' => $playlistId,
            ':channel' => $channel,
            ':reservation_key' => $reservationKey,
            ':slug' => $slug,
            ':path' => $path,
            ':public_url' => $publicUrl,
            ':status' => 'reserved',
            ':metadata_json' => json_encode([
                'reserved_for' => 'playlist_instance',
                'source' => 'asset_creator_analyzer',
            ], JSON_UNESCAPED_SLASHES),
        ]);

        return $this->findByKey($reservationKey) ?: [
            'playlist_instance_url_reservation_id' => (int)$this->pdo->lastInsertId(),
            'playlist_id' => $playlistId,
            'channel' => $channel,
            'reservation_key' => $reservationKey,
            'slug' => $slug,
            'path' => $path,
            'public_url' => $publicUrl,
            'status' => 'reserved',
            'playlist_instance_id' => null,
        ];
    }

    public function findByKey(string $reservationKey): ?array
    {
        $reservationKey = trim($reservationKey);
        if ($reservationKey === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM playlist_instance_url_reservations
              WHERE reservation_key = :reservation_key
              LIMIT 1'
        );
        $stmt->execute([':reservation_key' => $reservationKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM playlist_instance_url_reservations
              WHERE slug = :slug
              LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeRow($row) : null;
    }

    public function claim(string $reservationKey, int $playlistInstanceId, ?string $actualSlug = null): ?array
    {
        $reservation = $this->findByKey($reservationKey);
        if (!$reservation || $playlistInstanceId <= 0) {
            return $reservation;
        }

        $slug = trim((string)($actualSlug ?: $reservation['slug']));
        $path = '/playlist/' . rawurlencode($slug);
        $publicUrl = $this->withSource($this->originFromUrl((string)$reservation['public_url']) . $path, (string)$reservation['channel']);

        $stmt = $this->pdo->prepare(
            'UPDATE playlist_instance_url_reservations
                SET status = :status,
                    playlist_instance_id = :playlist_instance_id,
                    slug = :slug,
                    path = :path,
                    public_url = :public_url,
                    updated_at = NOW()
              WHERE reservation_key = :reservation_key'
        );
        $stmt->execute([
            ':status' => 'claimed',
            ':playlist_instance_id' => $playlistInstanceId,
            ':slug' => $slug,
            ':path' => $path,
            ':public_url' => $publicUrl,
            ':reservation_key' => $reservationKey,
        ]);

        return $this->findByKey($reservationKey);
    }

    private function uniqueReservedSlug(string $baseSlug): string
    {
        $base = $baseSlug !== '' ? substr($baseSlug, 0, 175) : 'playlist-instance';
        $candidate = $base;
        $suffix = 2;
        while ($this->slugExists($candidate)) {
            $candidate = substr($base, 0, 175) . '-' . $suffix;
            $suffix += 1;
        }
        return $candidate;
    }

    private function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
               FROM playlist_instances
              WHERE slug = :slug
              LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1
               FROM playlist_instance_url_reservations
              WHERE slug = :slug
              LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);
        return (bool)$stmt->fetchColumn();
    }

    private function reservationKey(int $playlistId, string $channel): string
    {
        return 'playlist:' . $playlistId . ':channel:' . $channel;
    }

    private function absoluteUrl(string $path, string $baseUrl, string $channel): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://colorfix.terrymarr.com';
        }
        return $this->withSource($baseUrl . $path, $channel);
    }

    private function withSource(string $url, string $channel): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'src=' . rawurlencode($channel);
    }

    private function originFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? 'colorfix.terrymarr.com');
        return $scheme . '://' . $host;
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        $channel = preg_replace('/[^a-z0-9_-]+/', '-', $channel) ?? '';
        return trim($channel, '-_');
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? substr($value, 0, 191) : '';
    }

    private function normalizeRow(array $row): array
    {
        foreach (['playlist_instance_url_reservation_id', 'playlist_id', 'playlist_instance_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        return $row;
    }
}
