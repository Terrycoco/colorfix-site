<?php
declare(strict_types=1);

namespace App\PUB\Create\Video;

use RuntimeException;

/**
 * VIDEO WORKER HEALTH SERVICE
 *
 * Renderer-neutral heartbeat storage for the external worker
 * that fulfills PUB video jobs.
 *
 * The current worker happens to run Remotion on the Mac.
 * CREATE only cares whether a video worker has checked in
 * recently enough to accept new work.
 */
final class VideoWorkerHealthService
{
    private const STALE_AFTER_SECONDS = 20;


    public function __construct(
        private string $projectRoot
    ) {}


    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public function recordHeartbeat(
        array $details = []
    ): array {
        $this->ensureStorageDirectory();

        $now = time();

        $heartbeat = [
            'last_seen_epoch' =>
                $now,

            'last_seen_at' =>
                gmdate(
                    'c',
                    $now
                ),

            'details' =>
                $details,
        ];

        $json = json_encode(
            $heartbeat,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        );

        $target =
            $this->heartbeatFile();

        $temporary =
            $target
            . '.tmp-'
            . bin2hex(
                random_bytes(6)
            );

        if (
            file_put_contents(
                $temporary,
                $json,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Could not write video worker heartbeat.'
            );
        }

        if (
            !rename(
                $temporary,
                $target
            )
        ) {
            @unlink(
                $temporary
            );

            throw new RuntimeException(
                'Could not publish video worker heartbeat.'
            );
        }

        return [
            ...$heartbeat,

            'alive' =>
                true,

            'age_seconds' =>
                0,

            'stale_after_seconds' =>
                self::STALE_AFTER_SECONDS,
        ];
    }


    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $file =
            $this->heartbeatFile();

        if (!is_file($file)) {
            return $this->unavailableStatus(
                'no_heartbeat'
            );
        }

        $raw =
            file_get_contents(
                $file
            );

        if (
            $raw === false
            || trim($raw) === ''
        ) {
            return $this->unavailableStatus(
                'heartbeat_unreadable'
            );
        }

        try {
            $heartbeat =
                json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

        } catch (\Throwable) {
            return $this->unavailableStatus(
                'heartbeat_invalid'
            );
        }

        if (!is_array($heartbeat)) {
            return $this->unavailableStatus(
                'heartbeat_invalid'
            );
        }

        $lastSeenEpoch =
            (int)(
                $heartbeat[
                    'last_seen_epoch'
                ]
                ?? 0
            );

        if ($lastSeenEpoch <= 0) {
            return $this->unavailableStatus(
                'heartbeat_invalid',
                $heartbeat
            );
        }

        $age =
            max(
                0,
                time()
                - $lastSeenEpoch
            );

        $alive =
            $age <=
            self::STALE_AFTER_SECONDS;

        return [
            'alive' =>
                $alive,

            'reason' =>
                $alive
                    ? 'recent_heartbeat'
                    : 'heartbeat_stale',

            'last_seen_epoch' =>
                $lastSeenEpoch,

            'last_seen_at' =>
                $heartbeat[
                    'last_seen_at'
                ]
                ?? gmdate(
                    'c',
                    $lastSeenEpoch
                ),

            'age_seconds' =>
                $age,

            'stale_after_seconds' =>
                self::STALE_AFTER_SECONDS,

            'details' =>
                is_array(
                    $heartbeat[
                        'details'
                    ]
                    ?? null
                )
                    ? $heartbeat[
                        'details'
                    ]
                    : [],
        ];
    }


    /**
     * @param array<string, mixed> $heartbeat
     * @return array<string, mixed>
     */
    private function unavailableStatus(
        string $reason,
        array $heartbeat = []
    ): array {
        return [
            'alive' =>
                false,

            'reason' =>
                $reason,

            'last_seen_epoch' =>
                null,

            'last_seen_at' =>
                $heartbeat[
                    'last_seen_at'
                ]
                ?? null,

            'age_seconds' =>
                null,

            'stale_after_seconds' =>
                self::STALE_AFTER_SECONDS,

            'details' =>
                is_array(
                    $heartbeat[
                        'details'
                    ]
                    ?? null
                )
                    ? $heartbeat[
                        'details'
                    ]
                    : [],
        ];
    }


    private function ensureStorageDirectory(): void
    {
        $dir =
            $this->storageDirectory();

        if (
            !is_dir($dir)
            && !mkdir(
                $dir,
                0775,
                true
            )
            && !is_dir($dir)
        ) {
            throw new RuntimeException(
                'Could not create video worker heartbeat directory.'
            );
        }

        $htaccess =
            $dir
            . '/.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "Deny from all\n"
            );
        }

        $index =
            $dir
            . '/index.html';

        if (!is_file($index)) {
            @file_put_contents(
                $index,
                ''
            );
        }
    }


    private function heartbeatFile(): string
    {
        return $this->storageDirectory()
            . '/heartbeat.json';
    }


    private function storageDirectory(): string
    {
        $root =
            rtrim(
                $this->projectRoot,
                DIRECTORY_SEPARATOR
            );

        if ($root === '') {
            throw new RuntimeException(
                'Video worker health service requires a project root.'
            );
        }

        return $root
            . '/storage/pub-video-worker';
    }
}
