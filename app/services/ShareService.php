<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\UrlNormalizer;
use App\Repos\PdoSavedPaletteRepository;
use InvalidArgumentException;

final class ShareService
{
    public function __construct(
        private ?PdoSavedPaletteRepository $savedPaletteRepo = null
    ) {}

    public function buildSharePath(string $assetType, int|string $assetRef, array $options = []): string
    {
        $type = $this->normalizeAssetType($assetType);

        return match ($type) {
            'playlist_instance' => $this->buildPlaylistInstancePath((int)$assetRef, $options),
            'saved_palette' => $this->buildSavedPalettePath($assetRef, $options),
            'applied_palette' => $this->buildAppliedPalettePath((int)$assetRef),
            'playlist_instance_set' => $this->buildPlaylistInstanceSetPath((int)$assetRef, $options),
            default => throw new InvalidArgumentException('Unsupported asset type: ' . $assetType),
        };
    }

    public function buildShareUrl(string $assetType, int|string $assetRef, array $options = []): string
    {
        return $this->toAbsoluteUrl($this->buildSharePath($assetType, $assetRef, $options));
    }

    public function resolveShareUrl(
        string $assetType,
        int|string $assetRef,
        ?string $providedUrl = null,
        array $options = []
    ): string {
        $url = trim((string)$providedUrl);
        if ($url !== '') {
            return $this->toAbsoluteUrl($url);
        }
        return $this->buildShareUrl($assetType, $assetRef, $options);
    }

    public function toAbsoluteUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Share URL cannot be empty');
        }
        return UrlNormalizer::absolute($trimmed, $this->baseUrl());
    }

    private function buildPlaylistInstancePath(int $playlistInstanceId, array $options): string
    {
        if ($playlistInstanceId <= 0) {
            throw new InvalidArgumentException('playlist_instance_id required');
        }

        $params = ['id' => $playlistInstanceId];
        $audience = trim((string)($options['audience'] ?? ''));
        if ($audience !== '' && strtolower($audience) !== 'any') {
            $params['aud'] = $audience;
        }

        $path = '/share/playlist.php';
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($query !== '') {
            $path .= '?' . $query;
        }
        return $path;
    }

    private function buildSavedPalettePath(int|string $assetRef, array $options): string
    {
        $paletteHash = trim((string)($options['palette_hash'] ?? ''));
        if ($paletteHash === '') {
            $paletteHash = $this->resolveSavedPaletteHash($assetRef);
        }

        $path = '/palette/' . rawurlencode($paletteHash) . '/share';
        $setId = isset($options['set_id']) ? (int)$options['set_id'] : 0;
        if ($setId > 0) {
            $path .= '?set_id=' . $setId;
        }

        return $path;
    }

    private function buildAppliedPalettePath(int $paletteId): string
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        return '/view/' . $paletteId;
    }

    private function buildPlaylistInstanceSetPath(int $setId, array $options): string
    {
        if ($setId <= 0) {
            throw new InvalidArgumentException('set_id required');
        }

        $params = ['psi' => $setId];
        foreach (['aud', 'add_cta_group', 'demo', 'return_to'] as $key) {
            $value = trim((string)($options[$key] ?? ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return '/picker?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function resolveSavedPaletteHash(int|string $assetRef): string
    {
        $hash = trim((string)$assetRef);
        if ($hash !== '' && !ctype_digit($hash)) {
            return $hash;
        }

        $paletteId = (int)$assetRef;
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }
        if (!$this->savedPaletteRepo) {
            throw new InvalidArgumentException('saved palette repository required to resolve palette hash');
        }

        $palette = $this->savedPaletteRepo->getSavedPaletteById($paletteId);
        $paletteHash = trim((string)($palette['palette_hash'] ?? ''));
        if ($paletteHash === '') {
            throw new InvalidArgumentException('saved palette not found');
        }

        return $paletteHash;
    }

    private function normalizeAssetType(string $assetType): string
    {
        return match (strtolower(trim($assetType))) {
            'playlist_instance', 'playlist-instance', 'playlist' => 'playlist_instance',
            'saved_palette', 'saved-palette', 'palette' => 'saved_palette',
            'applied_palette', 'applied-palette', 'view' => 'applied_palette',
            'playlist_instance_set', 'playlist-instance-set', 'playlist_set', 'playlist-set', 'set' => 'playlist_instance_set',
            default => trim($assetType),
        };
    }

    private function baseUrl(): string
    {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return UrlNormalizer::baseUrl();
        }

        return $this->schemeForHost($host) . '://' . $host;
    }

    private function schemeForHost(string $host): string
    {
        $normalized = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
        $isLocal = $normalized === 'localhost'
            || $normalized === '127.0.0.1'
            || $normalized === '0.0.0.0'
            || str_ends_with($normalized, '.local');

        if ($isLocal) {
            return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }

        return 'https';
    }
}
