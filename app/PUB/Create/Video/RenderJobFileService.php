<?php
declare(strict_types=1);

namespace App\PUB\Create\Video;

use InvalidArgumentException;

/**
 * PUB VIDEO RENDER JOB FILE SERVICE
 *
 * Owns temporary/private file storage for completed video renders
 * returned by the local Remotion worker.
 *
 * Render-job files are CREATE-stage working files.
 * They are NOT yet published Assets.
 *
 * Storage:
 *   storage/pub-render-jobs/
 *
 * Responsibilities:
 *   - validate uploaded render files
 *   - store them privately
 *   - return the stored relative path
 *
 * Must NOT:
 *   - create Asset Library records
 *   - package
 *   - queue
 *   - publish
 */
final class RenderJobFileService
{
    private const MAX_FILE_SIZE = 524288000; // 500 MB

    public function store(
        int $jobId,
        array $file
    ): array {
        if ($jobId <= 0) {
            throw new InvalidArgumentException(
                'Valid render job ID required.'
            );
        }

        $this->ensureStorageReady();
        $this->validateUpload($file);

        $mimeType = (string)(
            $file['type']
            ?? 'application/octet-stream'
        );

        $originalName = (string)(
            $file['name']
            ?? 'render.mp4'
        );

        $extension = strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );

        if ($extension === '') {
            $extension = 'mp4';
        }

        $storedName = sprintf(
            'render-job-%d-%s.%s',
            $jobId,
            bin2hex(random_bytes(8)),
            $extension
        );

        $destination =
            $this->storageDir()
            . '/'
            . $storedName;

        if (!move_uploaded_file(
            (string)$file['tmp_name'],
            $destination
        )) {
            throw new InvalidArgumentException(
                'Failed to move uploaded render.'
            );
        }

        return [
            'stored_name' => $storedName,

            /*
             * Relative to the ColorFix project root.
             */
            'rel_path' =>
                'storage/pub-render-jobs/'
                . $storedName,

            'mime_type' => $mimeType,

            'file_size_bytes' =>
                (int)($file['size'] ?? 0),
        ];
    }

    private function validateUpload(array $file): void
    {
        $error = (int)(
            $file['error']
            ?? UPLOAD_ERR_NO_FILE
        );

        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(
                'Render upload failed.'
            );
        }

        $size = (int)(
            $file['size']
            ?? 0
        );

        if ($size <= 0) {
            throw new InvalidArgumentException(
                'Uploaded render is empty.'
            );
        }

        if ($size > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException(
                'Render is larger than the 500 MB limit.'
            );
        }

        $tmpName = (string)(
            $file['tmp_name']
            ?? ''
        );

        if (
            $tmpName === ''
            || !is_uploaded_file($tmpName)
        ) {
            throw new InvalidArgumentException(
                'Invalid render upload payload.'
            );
        }
    }

    private function ensureStorageReady(): void
    {
        $dir = $this->storageDir();

        if (
            !is_dir($dir)
            && !mkdir($dir, 0775, true)
            && !is_dir($dir)
        ) {
            throw new InvalidArgumentException(
                'Failed to create PUB render-job storage.'
            );
        }

        /*
         * Render-job files are private working files.
         */
        $htaccess = $dir . '/.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "Deny from all\n"
            );
        }

        $index = $dir . '/index.html';

        if (!is_file($index)) {
            @file_put_contents(
                $index,
                ''
            );
        }
    }

    private function storageDir(): string
    {
        return dirname(__DIR__, 4)
            . '/storage/pub-render-jobs';
    }
}