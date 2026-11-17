<?php
declare(strict_types=1);
namespace App\PHOTOS\Services;

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\PHOTOS\Repos\PdoPhotoCropRepository;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use RuntimeException;
use Throwable;

/** PHOTOS derivatives only: original bytes and persisted crop geometry never change. */
final class PhotoThumbnailService
{
    private const RECIPE = 'v3';
    private const WIDTHS = [160, 200, 240, 320, 360, 420, 480, 520, 640, 720, 800, 960, 1200, 1600, 1920];
    private const MAX_PIXELS = 24000000;
    private const MAX_BYTES = 67108864;
    public function __construct(private PDO $pdo) {}

    public static function invalidate(int $id, string $root): void
    {
        if ($id <= 0) return;
        foreach (glob(rtrim($root, '/') . '/photos/cache/thumbs/pl-' . $id . '-*') ?: [] as $file) {
            if (is_file($file) && !is_link($file) && !str_ends_with($file, '.lock')) { @unlink($file); }
        }
    }

    /** Width-sized cards; inside-sized displays. All derivatives have a 1920px longest-edge ceiling. */
    public function get(int $photoId, int $width, int $quality, string $root, string $cropType = 'swatch', string $fit = 'width'): array
    {
        if ($photoId <= 0) throw new InvalidArgumentException('Missing id');
        if (!in_array($fit, ['width', 'inside'], true)) throw new InvalidArgumentException('Invalid fit');
        $width = min(1920, max(160, $width));
        foreach (self::WIDTHS as $candidate) { if ($candidate >= $width) { $width = $candidate; break; } }
        $quality = $quality <= 80 ? 80 : 88;
        $crops = new PhotoCropService(new PdoPhotoCropRepository($this->pdo));
        if ($cropType !== 'original') $crops->preset($cropType);
        $photo = (new PdoPhotoLibraryRepository($this->pdo))->findThumbnailSource($photoId);
        if (!$photo) throw new OutOfBoundsException('Not found');
        $relPath = trim((string)$photo['rel_path']);
        if ($relPath === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $relPath)) throw new OutOfBoundsException('Unsupported image');
        $root = realpath($root);
        if (!$root) throw new RuntimeException('Root missing');
        $sourcePath = realpath($root . '/' . ltrim((string)(parse_url($relPath, PHP_URL_PATH) ?: $relPath), '/'));
        if (!$sourcePath || !str_starts_with($sourcePath, $root . DIRECTORY_SEPARATOR) || !is_file($sourcePath)) throw new OutOfBoundsException('Source missing');
        $info = @getimagesize($sourcePath);
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg','image/png','image/webp'], true)) throw new RuntimeException('Invalid source image');
        // Existing normalized crops refer to stored source pixels. Crop before EXIF orientation.
        $crop = $cropType === 'original' ? null : $crops->getCrop($photoId, $info[0], $info[1], $cropType);
        if (empty($crop['is_custom'])) $crop = null;
        $fallback = ['path'=>$sourcePath, 'mime'=>$info['mime'], 'is_custom'=>false, 'optimized'=>false, 'requires_crop'=>$crop !== null];
        if (filesize($sourcePath) > self::MAX_BYTES || $info[0] * $info[1] > self::MAX_PIXELS) return $fallback;
        $orientation = 1;
        if ($info['mime'] === 'image/jpeg' && !function_exists('exif_read_data')) return $fallback;
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($sourcePath, 'IFD0', true);
            $orientation = (int)($exif['IFD0']['Orientation'] ?? 1);
        }
        $imagick = class_exists(\Imagick::class);
        $colorSensitive = self::needsColorManagement($sourcePath, $info);
        // Never silently discard an ICC profile or convert CMYK through unmanaged GD.
        if ($colorSensitive && !$imagick) return $fallback;
        if (!$imagick && !function_exists('imagecreatetruecolor')) return $fallback;
        if (!$imagick && !$this->hasMemory($info[0] * $info[1])) return $fallback;
        $format = ($imagick ? in_array('WEBP', \Imagick::queryFormats('WEBP'), true) : function_exists('imagewebp')) ? 'webp' : 'png';
        $stamp = hash('sha256', json_encode([self::RECIPE, $sourcePath, filemtime($sourcePath), filesize($sourcePath), $photo['updated_at'], $cropType, $crop, $fit, $orientation, $imagick]));
        $cacheDir = $root . '/photos/cache/thumbs';
        try {
            if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) throw new RuntimeException('Cache unavailable');
            $cachePath = "{$cacheDir}/pl-{$photoId}-w{$width}-q{$quality}-{$stamp}.{$format}";
            if (!is_file($cachePath) || filesize($cachePath) === 0) {
                // A fixed lock pool bounds lock files and prevents duplicate encodes.
                $lock = fopen($cacheDir . '/encode-' . ($photoId % 32) . '.lock', 'c');
                if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                    if (is_resource($lock)) fclose($lock);
                    return $fallback;
                }
                $temp = null;
                try {
                    clearstatcache(true, $cachePath);
                    if (!is_file($cachePath) || filesize($cachePath) === 0) {
                        $temp = tempnam($cacheDir, '.encoding-');
                        if (!$temp) throw new RuntimeException('Cache unavailable');
                        if ($imagick) $this->renderImagick($sourcePath, $temp, $width, $quality, $format, $info, $crop, $fit, $colorSensitive);
                        else $this->renderGd($sourcePath, $temp, $width, $quality, $format, $info, $crop, $fit, $orientation);
                        if (!@getimagesize($temp) || filesize($temp) <= 0) throw new RuntimeException('Invalid derivative');
                        chmod($temp, 0644);
                        if (!rename($temp, $cachePath)) throw new RuntimeException('Cache promotion failed');
                    }
                } finally {
                    if ($temp && is_file($temp)) @unlink($temp);
                    flock($lock, LOCK_UN); fclose($lock);
                }
            }
            return ['path'=>$cachePath, 'mime'=>$format === 'webp' ? 'image/webp' : 'image/png', 'is_custom'=>$crop !== null, 'optimized'=>true];
        } catch (Throwable $e) {
            error_log('PHOTOS derivative failed for #' . $photoId . ': ' . $e->getMessage());
            return $fallback;
        }
    }

    private function dimensions(int $w, int $h, int $target, string $fit): array
    {
        $scale = min(1, $target / ($fit === 'inside' ? max($w,$h) : $w), 1920 / max($w,$h));
        return [max(1,(int)round($w*$scale)), max(1,(int)round($h*$scale))];
    }

    private function rectangle(array $info, ?array $crop): array
    {
        $x = $crop ? (int)round($crop['crop_x']*$info[0]) : 0;
        $y = $crop ? (int)round($crop['crop_y']*$info[1]) : 0;
        return [$x, $y, $crop ? min($info[0]-$x,max(1,(int)round($crop['crop_width']*$info[0]))) : $info[0],
            $crop ? min($info[1]-$y,max(1,(int)round($crop['crop_height']*$info[1]))) : $info[1]];
    }

    private function renderGd(string $sourcePath, string $dest, int $width, int $quality, string $format, array $info, ?array $crop, string $fit, int $orientation): void
    {
        $source = match($info['mime']) {
            'image/jpeg'=>@imagecreatefromjpeg($sourcePath), 'image/png'=>@imagecreatefrompng($sourcePath),
            'image/webp'=>function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        };
        if (!$source) throw new RuntimeException('Decode failed');
        $thumb = null;
        try {
            [$x,$y,$sw,$sh] = $this->rectangle($info,$crop);
            $rotates = in_array($orientation,[5,6,7,8],true);
            [$tw,$th] = $this->dimensions($rotates ? $sh : $sw, $rotates ? $sw : $sh, $width,$fit);
            $thumb = imagecreatetruecolor($rotates ? $th : $tw, $rotates ? $tw : $th);
            imagealphablending($thumb,false); imagesavealpha($thumb,true);
            imagefill($thumb,0,0,imagecolorallocatealpha($thumb,0,0,0,127));
            imagecopyresampled($thumb,$source,0,0,$x,$y,imagesx($thumb),imagesy($thumb),$sw,$sh);
            if (in_array($orientation,[2,5,7],true)) imageflip($thumb,IMG_FLIP_HORIZONTAL);
            if ($orientation === 4) imageflip($thumb,IMG_FLIP_VERTICAL);
            $angle = match($orientation) { 3=>180, 5,8=>90, 6,7=>-90, default=>0 };
            if ($angle) {
                $rotated = imagerotate($thumb,$angle,imagecolorallocatealpha($thumb,0,0,0,127));
                if (!$rotated) throw new RuntimeException('Orientation failed');
                imagedestroy($thumb); $thumb=$rotated; imagesavealpha($thumb,true);
            }
            $ok = $format === 'webp' ? imagewebp($thumb,$dest,$quality) : imagepng($thumb,$dest,6);
            if (!$ok) throw new RuntimeException('Encode failed');
        } finally { imagedestroy($source); if ($thumb) imagedestroy($thumb); }
    }

    private function renderImagick(string $sourcePath, string $dest, int $width, int $quality, string $format, array $info, ?array $crop, string $fit, bool $colorSensitive): void
    {
        $im = new \Imagick();
        $im->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY,128*1024*1024);
        $im->setResourceLimit(\Imagick::RESOURCETYPE_MAP,256*1024*1024);
        $im->setResourceLimit(\Imagick::RESOURCETYPE_DISK,256*1024*1024);
        $im->setResourceLimit(\Imagick::RESOURCETYPE_THREAD,1);
        if (defined('Imagick::RESOURCETYPE_TIME')) $im->setResourceLimit(\Imagick::RESOURCETYPE_TIME,20);
        try {
            $im->readImage($sourcePath . '[0]');
            if ($im->getImageProfiles('icc')) {
                $profile = \App\Lib\EnvLoader::get('PHOTOS_SRGB_PROFILE') ?: '';
                if (!$profile || !is_readable($profile)) throw new RuntimeException('sRGB ICC profile required for tagged source');
                $im->profileImage('icc',file_get_contents($profile));
            } elseif ($colorSensitive) {
                // Do not guess the source profile of untagged CMYK/nonstandard gamma data.
                throw new RuntimeException('Source color profile requires review');
            }
            if ($crop) { [$x,$y,$sw,$sh]=$this->rectangle($info,$crop); $im->cropImage($sw,$sh,$x,$y); $im->setImagePage(0,0,0,0); }
            $im->autoOrientImage();
            [$tw,$th]=$this->dimensions($im->getImageWidth(),$im->getImageHeight(),$width,$fit);
            if ($tw !== $im->getImageWidth() || $th !== $im->getImageHeight()) $im->resizeImage($tw,$th,\Imagick::FILTER_LANCZOS,1);
            $im->setImageFormat($format); $im->setImageCompressionQuality($quality);
            // Retain the converted ICC profile, remove unrelated metadata only.
            foreach (['exif','xmp','iptc'] as $profile) { if ($im->getImageProfiles($profile)) $im->removeImageProfile($profile); }
            $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            if (!$im->writeImage($dest)) throw new RuntimeException('Encode failed');
        } finally { $im->clear(); $im->destroy(); }
    }

    private function hasMemory(int $pixels): bool
    {
        $raw = ini_get('memory_limit');
        if ($raw === '-1') return $pixels*8 + memory_get_usage(true) < 256*1024*1024;
        $unit = strtolower(substr($raw,-1));
        $limit = (int)$raw * match($unit) { 'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1 };
        return $pixels*8 + memory_get_usage(true) + 32*1024*1024 < $limit;
    }

    /** Inspect bounded metadata without decoding a raster; unknown profiles fall back intact. */
    private static function needsColorManagement(string $path, array $info): bool
    {
        if (($info['channels'] ?? 3) === 4) return true;
        $f = fopen($path,'rb');
        if (!$f) return true;
        try {
            if ($info['mime'] === 'image/png') {
                fseek($f,8);
                for ($i=0;$i<128;$i++) {
                    $header=fread($f,8); if (strlen($header)!==8) return true;
                    $length=unpack('N',substr($header,0,4))[1]; $type=substr($header,4,4);
                    if ($type==='iCCP' || $type==='cHRM' || $type==='eXIf') return true;
                    if ($type==='IDAT' || $type==='IEND') return false;
                    if ($type==='gAMA') { $gamma=fread($f,$length); if ($length!==4 || abs(unpack('N',$gamma)[1]-45455)>10) return true; fseek($f,4,SEEK_CUR); }
                    else fseek($f,$length+4,SEEK_CUR);
                }
                return true;
            }
            if ($info['mime'] === 'image/webp') {
                $header=fread($f,21);
                return strlen($header)>=21 && substr($header,12,4)==='VP8X' && (ord($header[20]) & 40)!==0;
            }
            // ICC APP2 segments precede compressed JPEG scan data.
            fseek($f,2);
            for ($i=0;$i<512;$i++) {
                $marker=fread($f,2); if (strlen($marker)!==2) return true;
                if ($marker==="\xff\xda" || $marker==="\xff\xd9") return false;
                $size=fread($f,2); if (strlen($size)!==2) return true;
                $length=unpack('n',$size)[1]-2; if ($length<0) return true;
                $data=$length ? fread($f,$length) : "";
                if ($marker==="\xff\xe2" && str_starts_with($data,'ICC_PROFILE')) return true;
            }
            return true;
        } finally { fclose($f); }
    }
}
