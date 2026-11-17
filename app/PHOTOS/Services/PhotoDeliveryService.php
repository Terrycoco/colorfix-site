<?php
declare(strict_types=1);
namespace App\PHOTOS\Services;

/** PHOTOS URL/access contract. Issue capabilities only from an already-authorized response. */
final class PhotoDeliveryService
{
    public static function thumbnailUrl(int $photoId, int $width = 320, string $crop = 'original'): string
    {
        return '/api/v2/image-thumb.php?' . http_build_query(['id'=>$photoId,'w'=>$width,'q'=>88,'crop_type'=>$crop]);
    }

    public static function url(int $photoId, string $sourcePath, int $width = 1920, string $crop = 'original'): ?string
    {
        $key = \App\Lib\EnvLoader::get('PHOTOS_DELIVERY_SIGNING_KEY') ?: '';
        if ($photoId <= 0 || $sourcePath === '' || strlen($key) < 32) { return null; }
        // Stable within an hour, valid for one day. Never use a publisher credential.
        $expires = (int)(floor(time() / 3600) * 3600 + 86400);
        $revision = hash('sha256', self::path($sourcePath));
        $signature = hash_hmac('sha256', "photos-v1|{$photoId}|{$revision}|{$expires}", $key);
        return '/api/v2/image-thumb.php?' . http_build_query([
            'id' => $photoId, 'w' => $width, 'q' => 88, 'crop_type' => $crop,
            'fit' => 'inside', 'expires' => $expires, 'signature' => $signature,
        ]);
    }

    public static function authorized(array $photo, array $query, bool $admin, ?int $now = null): bool
    {
        if ($admin || self::isPublic($photo)) { return true; }
        $key = \App\Lib\EnvLoader::get('PHOTOS_DELIVERY_SIGNING_KEY') ?: '';
        $expires = (int)($query['expires'] ?? 0);
        $now ??= time();
        if (strlen($key) < 32 || $expires < $now || $expires > $now + 86400) { return false; }
        $id = (int)($photo['photo_library_id'] ?? 0);
        $revision = hash('sha256', self::path((string)($photo['rel_path'] ?? '')));
        return hash_equals(hash_hmac('sha256', "photos-v1|{$id}|{$revision}|{$expires}", $key), (string)($query['signature'] ?? ''));
    }

    public static function isPublic(array $photo): bool
    {
        return !empty($photo['show_in_gallery']) && empty($photo['client_id'])
            && empty($photo['is_inactive']) && empty($photo['is_retired'])
            && !in_array($photo['photo_permission_status'] ?? '', ['declined', 'requested'], true);
    }

    private static function path(string $value): string
    {
        if (str_starts_with($value, 'photo:')) { $value = explode('|', $value, 2)[1] ?? ''; }
        return (string)(parse_url($value, PHP_URL_PATH) ?: '');
    }
}
