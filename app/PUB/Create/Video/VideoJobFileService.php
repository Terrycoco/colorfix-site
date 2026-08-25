<?php
declare(strict_types=1);

namespace App\PUB\Create\Video;

use InvalidArgumentException;

/**
 * PUB VIDEO JOB FILE SERVICE
 *
 * Owns temporary/private file storage for completed video files
 * returned by the current video worker.
 *
 * Video-job files are CREATE-stage working files.
 * They are NOT yet published PUB assets.
 *
 * Storage:
 *   storage/pub-video-jobs/
 *
 * Responsibilities:
 *   - validate uploaded video files
 *   - store them privately
 *   - return the stored relative path
 *
 * Renderer-neutral:
 *   - does not know Remotion
 *   - does not know where rendering happened
 *   - accepts the completed video file only
 *
 * Must NOT:
 *   - create or update pub_assets
 *   - package
 *   - schedule
 *   - dispatch
 *   - publish
 */
final class VideoJobFileService
{
    private const MAX_FILE_SIZE = 524288000; // 500 MB


    public function store(
        int $jobId,
        array $file
    ): array {
        if ($jobId <= 0) {
            throw new InvalidArgumentException(
                'Valid video job ID required.'
            );
        }


        $this->ensureStorageReady();
        $this->validateUpload(
            $file
        );


        $mimeType =
            (string)(
                $file['type']
                ?? 'application/octet-stream'
            );

        $originalName =
            (string)(
                $file['name']
                ?? 'video.mp4'
            );

        $extension =
            strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            );

        if ($extension === '') {
            $extension =
                'mp4';
        }


        $storedName =
            sprintf(
                'video-job-%d-%s.%s',
                $jobId,
                bin2hex(
                    random_bytes(8)
                ),
                $extension
            );


        $destination =
            $this->storageDir()
            . '/'
            . $storedName;


        if (
            !move_uploaded_file(
                (string)$file[
                    'tmp_name'
                ],
                $destination
            )
        ) {
            throw new InvalidArgumentException(
                'Failed to move uploaded video.'
            );
        }


        return [
            'stored_name' =>
                $storedName,

            /*
             * Relative to the ColorFix project root.
             */
            'rel_path' =>
                'storage/pub-video-jobs/'
                . $storedName,

            'mime_type' =>
                $mimeType,

            'file_size_bytes' =>
                (int)(
                    $file['size']
                    ?? 0
                ),
        ];
    }


    private function validateUpload(
        array $file
    ): void {
        $error =
            (int)(
                $file['error']
                ?? UPLOAD_ERR_NO_FILE
            );


        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(
                'Video upload failed.'
            );
        }


        $size =
            (int)(
                $file['size']
                ?? 0
            );


        if ($size <= 0) {
            throw new InvalidArgumentException(
                'Uploaded video is empty.'
            );
        }


        if (
            $size >
            self::MAX_FILE_SIZE
        ) {
            throw new InvalidArgumentException(
                'Video is larger than the 500 MB limit.'
            );
        }


        $tmpName =
            (string)(
                $file['tmp_name']
                ?? ''
            );


        if (
            $tmpName === ''
            || !is_uploaded_file(
                $tmpName
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid video upload payload.'
            );
        }
    }


    private function ensureStorageReady(): void
    {
        $dir =
            $this->storageDir();


        if (
            !is_dir($dir)
            && !mkdir(
                $dir,
                0775,
                true
            )
            && !is_dir($dir)
        ) {
            throw new InvalidArgumentException(
                'Failed to create PUB video-job storage.'
            );
        }


        /*
         * Video-job files are private working files.
         */
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


    private function storageDir(): string
    {
        return dirname(
            __DIR__,
            4
        )
            . '/storage/pub-video-jobs';
    }
}
