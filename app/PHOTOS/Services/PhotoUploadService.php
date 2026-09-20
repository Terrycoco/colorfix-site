<?php
declare(strict_types=1);

namespace App\PHOTOS\Services;

use App\PHOTOS\Entities\PhotoEntity;
use App\Repos\PdoAssetLibraryRepository;
use App\Repos\PdoPhotoLibraryRepository;
use App\Services\AssetLibraryService;
use App\Services\PhotoAltTextQueueService;
use App\Services\PhotoLibraryService;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PhotoUploadService
{
    private const SOURCE_TYPE = 'extra_photo';

    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ];

    public function __construct(
        private readonly PdoPhotoLibraryRepository $photoRepo,
        private readonly PhotoLibraryService $photoLibrary,
        private readonly AssetLibraryService $assetLibrary,
        private readonly PhotoAltTextQueueService $altTextQueue,
    ) {
    }


    public static function fromPdo(
        PDO $pdo
    ): self {
        $photoRepo =
            new PdoPhotoLibraryRepository(
                $pdo
            );

        return new self(
            $photoRepo,

            new PhotoLibraryService(
                $photoRepo
            ),

            new AssetLibraryService(
                new PdoAssetLibraryRepository(
                    $pdo
                ),
                ''
            ),

            PhotoAltTextQueueService::fromPdo(
                $pdo
            ),
        );
    }


    public function upload(
        array $file,
        array $metadata = []
    ): PhotoEntity {
        $this->validateUpload(
            $file
        );

        $tags = trim(
            (string)(
                $metadata['tags']
                ?? ''
            )
        );

        if ($tags === '') {
            throw new InvalidArgumentException(
                'At least one tag is required.'
            );
        }

        $tmpPath = (string)(
            $file['tmp_name']
            ?? ''
        );

        $originalName = (string)(
            $file['name']
            ?? 'photo'
        );

        $extension = strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );

        if (
            !in_array(
                $extension,
                self::ALLOWED_EXTENSIONS,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Photo must be JPG, JPEG, PNG, or WEBP.'
            );
        }

        $imageInfo =
            @getimagesize(
                $tmpPath
            );

        if (!$imageInfo) {
            throw new InvalidArgumentException(
                'Uploaded file is not a valid image.'
            );
        }

        $title = trim(
            (string)(
                $metadata['title']
                ?? ''
            )
        );

        if ($title === '') {
            $title = trim(
                pathinfo(
                    $originalName,
                    PATHINFO_FILENAME
                )
            );
        }

        $altText = trim(
            (string)(
                $metadata['alt_text']
                ?? ''
            )
        );

        $documentRoot = rtrim(
            (string)(
                $_SERVER['DOCUMENT_ROOT']
                ?? dirname(
                    __DIR__,
                    4
                )
            ),
            '/'
        );

        $folder =
            date('Ym');

        $relativeDirectory =
            "/photos/library/{$folder}";

        $absoluteDirectory =
            $documentRoot
            . $relativeDirectory;

        if (
            !is_dir(
                $absoluteDirectory
            )
            &&
            !mkdir(
                $absoluteDirectory,
                0775,
                true
            )
            &&
            !is_dir(
                $absoluteDirectory
            )
        ) {
            throw new RuntimeException(
                'Failed to create photo upload directory.'
            );
        }

        $slug =
            bin2hex(
                random_bytes(8)
            );

        $filename =
            "photo_{$slug}.{$extension}";

        $absolutePath =
            $absoluteDirectory
            . '/'
            . $filename;

        $relativePath =
            $relativeDirectory
            . '/'
            . $filename;

        if (
            !move_uploaded_file(
                $tmpPath,
                $absolutePath
            )
        ) {
            throw new RuntimeException(
                'Failed to store uploaded photo.'
            );
        }

        try {
            $photoLibraryId =
                $this->photoLibrary
                    ->createStandalone(
                        self::SOURCE_TYPE,
                        $relativePath,
                        [
                            'title' =>
                                $title,

                            'tags' =>
                                $tags,

                            'alt_text' =>
                                $altText !== ''
                                    ? $altText
                                    : null,

                            'show_in_gallery' =>
                                0,

                            'has_palette' =>
                                0,
                        ]
                    );

            if (
                $photoLibraryId
                <= 0
            ) {
                throw new RuntimeException(
                    'Photo Library record was not created.'
                );
            }

            $asset =
                $this->assetLibrary
                    ->upsertAssetForPhoto(
                        $photoLibraryId,
                        $relativePath,
                        [
                            'asset_kind' =>
                                'image',

                            'mime_type' =>
                                $imageInfo['mime']
                                ?? null,

                            'title' =>
                                $title,

                            'tags' =>
                                $tags,

                            'alt_text' =>
                                $altText !== ''
                                    ? $altText
                                    : null,

                            'note' =>
                                'Uploaded via PHOTOS',

                            'source_type' =>
                                'photo_library',

                            'source_id' =>
                                $photoLibraryId,

                            'width' =>
                                isset(
                                    $imageInfo[0]
                                )
                                    ? (int)$imageInfo[0]
                                    : null,

                            'height' =>
                                isset(
                                    $imageInfo[1]
                                )
                                    ? (int)$imageInfo[1]
                                    : null,

                            'file_size_bytes' =>
                                is_file(
                                    $absolutePath
                                )
                                    ? filesize(
                                        $absolutePath
                                    )
                                    : null,

                            'checksum' =>
                                is_file(
                                    $absolutePath
                                )
                                    ? hash_file(
                                        'sha256',
                                        $absolutePath
                                    )
                                    : null,

                            'metadata_json' => [
                                'photo_library_id' =>
                                    $photoLibraryId,

                                'upload_source_type' =>
                                    self::SOURCE_TYPE,
                            ],

                            'is_inactive' =>
                                0,

                            'is_retired' =>
                                0,
                        ]
                    );

            if (
                !empty(
                    $asset[
                        'asset_library_id'
                    ]
                )
            ) {
                $this->photoRepo
                    ->update(
                        $photoLibraryId,
                        [
                            'asset_library_id' =>
                                (int)$asset[
                                    'asset_library_id'
                                ],
                        ]
                    );
            }

            if ($altText === '') {
                $this->altTextQueue
                    ->enqueue(
                        $photoLibraryId
                    );
            }

            return new PhotoEntity(
                photoLibraryId:
                    $photoLibraryId,

                imageUrl:
                    $relativePath,

                filePath:
                    $relativePath,

                title:
                    $title !== ''
                        ? $title
                        : null,
            );

        } catch (\Throwable $e) {
            /*
             * The upload has not successfully become
             * a usable Photo Library asset.
             *
             * Avoid leaving an orphaned physical file
             * when creation fails before completion.
             */
            if (
                isset($photoLibraryId)
                &&
                $photoLibraryId > 0
            ) {
                /*
                 * A DB record already exists.
                 * Do not unlink the underlying file here,
                 * because the record may now reference it.
                 */
            } elseif (
                is_file(
                    $absolutePath
                )
            ) {
                @unlink(
                    $absolutePath
                );
            }

            throw $e;
        }
    }


    private function validateUpload(
        array $file
    ): void {
        $error =
            (int)(
                $file['error']
                ?? UPLOAD_ERR_NO_FILE
            );

        if (
            $error
            !== UPLOAD_ERR_OK
        ) {
            throw new InvalidArgumentException(
                $this->uploadErrorMessage(
                    $error
                )
            );
        }

        $tmpPath = trim(
            (string)(
                $file['tmp_name']
                ?? ''
            )
        );

        if (
            $tmpPath === ''
            ||
            !is_uploaded_file(
                $tmpPath
            )
        ) {
            throw new InvalidArgumentException(
                'Uploaded photo is invalid.'
            );
        }
    }


    private function uploadErrorMessage(
        int $error
    ): string {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE =>
                'Uploaded photo is too large.',

            UPLOAD_ERR_PARTIAL =>
                'Photo upload was incomplete.',

            UPLOAD_ERR_NO_FILE =>
                'Choose a photo to upload.',

            default =>
                'Photo upload failed.',
        };
    }
}