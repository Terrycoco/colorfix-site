<?php
declare(strict_types=1);

use App\Repos\PdoAppConfigRepository;

function normalizeCardTargetUrl(string $target): string
{
    $target = trim($target);
    if ($target === '') {
        return '/';
    }
    if (str_starts_with($target, '/')) {
        return $target;
    }
    if (preg_match('#^https?://#i', $target) === 1) {
        return $target;
    }
    return '/';
}

/**
 * @return array{target_url:string}
 */
function loadCardConfig(?PDO $pdo = null): array
{
    if ($pdo instanceof PDO) {
        try {
            $repo = new PdoAppConfigRepository($pdo);
            $data = $repo->getJson('business_card');
            if (is_array($data)) {
                return [
                    'target_url' => normalizeCardTargetUrl((string)($data['target_url'] ?? '/')),
                ];
            }
        } catch (\Throwable) {
            // Default to the front page if app config is unavailable.
        }
    }

    return ['target_url' => '/'];
}

function saveCardConfig(string $targetUrl, ?PDO $pdo = null): bool
{
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $repo = new PdoAppConfigRepository($pdo);
        $repo->setJson('business_card', [
            'target_url' => normalizeCardTargetUrl($targetUrl),
        ]);
        return true;
    } catch (\Throwable) {
        return false;
    }
}
