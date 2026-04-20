<?php
declare(strict_types=1);

namespace App\Services;

final class ImageMetadataPrompt
{
    public static function build(array $context = []): string
    {
        $title = trim((string)($context['title'] ?? ''));
        $tags = trim((string)($context['tags'] ?? ''));
        return trim(
            "You are writing useful SEO image metadata for ColorFix image assets.\n"
            . "Describe the actual visible image in one concise alt text sentence, 8 to 22 words.\n"
            . "Do not start with \"Image of\" or \"Photo of\".\n"
            . "Do not invent brand names, paint colors, locations, client names, or claims that are not visible.\n"
            . "Mention room/exterior type, visible design features, colors, materials, and composition when obvious.\n"
            . "Also create filename_slug: a lowercase Google-readable file-name slug, 4 to 9 words, hyphen-separated, no file extension.\n"
            . "Return strict JSON only with keys: alt_text, filename_slug, image_summary, suggested_tags, dominant_subjects, seo_notes.\n"
            . ($title !== '' ? "Known internal title: {$title}\n" : '')
            . ($tags !== '' ? "Known internal tags: {$tags}\n" : '')
        );
    }

    public static function cleanAltText(string $altText): string
    {
        $altText = preg_replace('/\s+/', ' ', $altText) ?? $altText;
        $altText = trim($altText, " \t\n\r\0\x0B\"'");
        return mb_substr($altText, 0, 240);
    }

    public static function cleanFilenameSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        $value = preg_replace('/-+/', '-', $value) ?? $value;
        $value = trim($value, '-');
        return $value !== '' ? mb_substr($value, 0, 180) : '';
    }

    public static function parseJsonText(string $text): array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('AI text payload was not valid JSON');
        }
        return $decoded;
    }

    public static function normalizeResult(array $parsed): array
    {
        $altText = trim((string)($parsed['alt_text'] ?? ''));
        if ($altText === '') {
            throw new \RuntimeException('AI returned no alt_text');
        }
        $cleanAltText = self::cleanAltText($altText);
        $filenameSlug = self::cleanFilenameSlug((string)($parsed['filename_slug'] ?? $cleanAltText));
        return [
            'alt_text' => $cleanAltText,
            'filename_slug' => $filenameSlug,
            'metadata' => [
                'image_summary' => $parsed['image_summary'] ?? null,
                'seo_notes' => $parsed['seo_notes'] ?? null,
                'filename_slug' => $filenameSlug,
                'suggested_tags' => $parsed['suggested_tags'] ?? [],
                'dominant_subjects' => $parsed['dominant_subjects'] ?? [],
                'raw' => $parsed,
            ],
        ];
    }
}
