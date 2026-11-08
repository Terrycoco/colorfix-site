<?php
declare(strict_types=1);

namespace App\PHOTOS\Services;

use App\PHOTOS\Config\PhotoCropPresets;
use App\PHOTOS\Repos\PdoPhotoCropRepository;
use InvalidArgumentException;

/**
 * Canonical crop rules for PHOTOS. Coordinates are fractions of the ORIGINAL
 * image width/height, not thumbnail pixels. Nothing modifies image bytes.
 *
 * Image dimensions must be taken from a trusted PHOTOS source, not client input.
 */
final class PhotoCropService
{
    public function __construct(private PdoPhotoCropRepository $repository)
    {
    }

    public function presets(): array
    {
        return PhotoCropPresets::PRESETS;
    }

    public function preset(string $type): array
    {
        if (!isset(PhotoCropPresets::PRESETS[$type])) {
            throw new InvalidArgumentException('Unsupported crop type: ' . $type);
        }
        return PhotoCropPresets::PRESETS[$type];
    }

    /**
     * Read a crop, falling back to a centered crop if no explicit record exists.
     * Returned geometry is normalized; `is_custom` indicates persistence.
     */
    public function getCrop(
        int $photoLibraryId,
        int $imageWidth,
        int $imageHeight,
        ?string $type = null
    ): array {
        $this->assertImage($photoLibraryId, $imageWidth, $imageHeight);
        $type ??= PhotoCropPresets::DEFAULT_TYPE;
        $this->preset($type);
        $row = $this->repository->find($photoLibraryId, $type);
        if ($row === null) {
            return ['photo_library_id' => $photoLibraryId, 'crop_type' => $type,
                'is_custom' => false] + $this->centerCrop($imageWidth, $imageHeight, $type);
        }
        $crop = [
            'crop_x' => (float) $row['crop_x'],
            'crop_y' => (float) $row['crop_y'],
            'crop_width' => (float) $row['crop_width'],
            'crop_height' => (float) $row['crop_height'],
        ];
        // Fail explicitly on corrupt/outdated crop data rather than display an arbitrary crop.
        $this->assertCrop($crop, $imageWidth, $imageHeight, $type);
        return ['photo_library_id' => $photoLibraryId, 'crop_type' => $type,
            'is_custom' => true] + $crop;
    }

    /** Centered largest rectangle of the requested aspect ratio. */
    public function centerCrop(int $imageWidth, int $imageHeight, ?string $type = null): array
    {
        if ($imageWidth <= 0 || $imageHeight <= 0) {
            throw new InvalidArgumentException('Original image dimensions must be positive.');
        }
        $preset = $this->preset($type ?? PhotoCropPresets::DEFAULT_TYPE);
        $target = $preset['width'] / $preset['height'];
        $actual = $imageWidth / $imageHeight;

        if ($actual >= $target) {
            $w = ($imageHeight * $target) / $imageWidth;
            $h = 1.0;
        } else {
            $w = 1.0;
            $h = ($imageWidth / $target) / $imageHeight;
        }

        return [
            'crop_x' => (1.0 - $w) / 2.0,
            'crop_y' => (1.0 - $h) / 2.0,
            'crop_width' => $w,
            'crop_height' => $h,
        ];
    }

    /**
     * Persist a manual crop. Ensure the caller has edit permission and provides
     * verified original image dimensions. Requires an existing Photo Library row.
     */
    public function saveCrop(
        int $photoLibraryId,
        string $type,
        array $crop,
        int $imageWidth,
        int $imageHeight
    ): array {
        $this->assertImage($photoLibraryId, $imageWidth, $imageHeight);
        $this->assertCrop($crop, $imageWidth, $imageHeight, $type);
        $this->repository->upsert(
            $photoLibraryId, $type,
            (float) $crop['crop_x'], (float) $crop['crop_y'],
            (float) $crop['crop_width'], (float) $crop['crop_height']
        );
        return $this->getCrop($photoLibraryId, $imageWidth, $imageHeight, $type);
    }

    /** Removing an override restores the automatic centered crop. */
    public function resetCrop(int $photoLibraryId, string $type): void
    {
        if ($photoLibraryId <= 0) {
            throw new InvalidArgumentException('Invalid Photo Library ID.');
        }
        $this->preset($type);
        $this->repository->delete($photoLibraryId, $type);
    }

    private function assertImage(int $photoLibraryId, int $imageWidth, int $imageHeight): void
    {
        if ($photoLibraryId <= 0 || $imageWidth <= 0 || $imageHeight <= 0) {
            throw new InvalidArgumentException('A valid photo ID and original dimensions are required.');
        }
    }

    private function assertCrop(array $crop, int $imageWidth, int $imageHeight, string $type): void
    {
        $preset = $this->preset($type);
        $values = [];
        foreach (['crop_x', 'crop_y', 'crop_width', 'crop_height'] as $field) {
            if (!array_key_exists($field, $crop) || !is_numeric($crop[$field])) {
                throw new InvalidArgumentException('Missing or invalid ' . $field);
            }
            $value = (float) $crop[$field];
            if (!is_finite($value) || $value < 0 || $value > 1) {
                throw new InvalidArgumentException($field . ' must be between 0 and 1.');
            }
            $values[$field] = $value;
        }
        if ($values['crop_width'] <= 0 || $values['crop_height'] <= 0) {
            throw new InvalidArgumentException('Crop width and height must be positive.');
        }
        // Allow six-decimal quantization from DECIMAL(7,6) at image-scale sizes.
        $epsilon = 0.000003;
        if ($values['crop_x'] + $values['crop_width'] > 1 + $epsilon
            || $values['crop_y'] + $values['crop_height'] > 1 + $epsilon) {
            throw new InvalidArgumentException('Crop rectangle exceeds image bounds.');
        }

        $pixelsWide = $values['crop_width'] * $imageWidth;
        $pixelsHigh = $values['crop_height'] * $imageHeight;
        $expected = $preset['width'] / $preset['height'];
        // One source-pixel tolerance accommodates normalized DB rounding and UI drags.
        $difference = abs($pixelsWide - $expected * $pixelsHigh);
        if ($difference > max(1.5, $expected)) {
            throw new InvalidArgumentException('Crop does not match the selected aspect ratio.');
        }
    }
}
