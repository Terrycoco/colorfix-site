<?php
declare(strict_types=1);
namespace App\ARTICLES\Services;

/** Initial metadata for the existing React page; never a second article renderer. */
final class ArticleHtmlService
{
    public const ORIGIN = 'https://colorfix.terrymarr.com';
    public static function metadata(?array $item, bool $preview = false): array
    {
        $article = $item['article'] ?? null;
        $public = $article && ($article['status'] ?? '') === 'published' && !$preview;
        $title = $article ? (string)$article['title'] : 'Article unavailable';
        $description = $article ? trim(strip_tags((string)(($article['meta_description'] ?? '') ?: (($article['dek'] ?? '') ?: $title)))) : '';
        $canonical = $public ? self::ORIGIN . '/articles/' . (int)$article['id'] : '';
        $image = $public ? ($item['hero']['optimized_urls']['1600'] ?? '') : '';
        // PHOTOS response uses optimized_urls; omit a missing authorized image.
        if ($image !== '' && str_starts_with($image, '/')) $image = self::ORIGIN . $image;
        return ['title' => $title, 'description' => $description, 'canonical' => $canonical,
            'image' => $image, 'robots' => $public ? 'index,follow' : 'noindex,nofollow',
            'published_time' => $public && !empty($article['published_at']) ? str_replace(' ', 'T', $article['published_at']) . 'Z' : ''];
    }

    public static function render(string $shell, ?array $item, bool $preview = false): string
    {
        $meta = self::metadata($item, $preview);
        ['title' => $title, 'description' => $description, 'canonical' => $canonical, 'image' => $image] = $meta;
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $head = '<title>' . $e($title) . '</title>' . "\n";
        foreach (['description' => $description, 'robots' => $meta['robots'],
            'twitter:card' => $image ? 'summary_large_image' : 'summary', 'twitter:title' => $title,
            'twitter:description' => $description, 'twitter:image' => $image] as $key => $value) {
            if ($value !== '') $head .= '<meta name="' . $key . '" content="' . $e($value) . '">' . "\n";
        }
        foreach (['og:type' => 'article', 'og:title' => $title, 'og:description' => $description,
            'og:url' => $canonical, 'og:image' => $image,
            'article:published_time' => $meta['published_time']] as $key => $value) {
            if ($value !== '') $head .= '<meta property="' . $key . '" content="' . $e($value) . '">' . "\n";
        }
        if ($canonical) $head .= '<link rel="canonical" href="' . $e($canonical) . '">' . "\n";
        $shell = preg_replace('~<title\b[^>]*>.*?</title>~is', '', $shell);
        $shell = preg_replace('~<meta\b[^>]*(?:name|property)=["\'](?:description|robots|og:[^"\']*|twitter:[^"\']*|article:[^"\']*)["\'][^>]*>~i', '', $shell);
        $shell = preg_replace('~<link\b[^>]*rel=["\']canonical["\'][^>]*>~i', '', $shell);
        return str_replace('</head>', $head . '</head>', $shell);
    }
}
