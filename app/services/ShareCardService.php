<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoSavedPaletteRepository;
use RuntimeException;

final class ShareCardService
{
    public function __construct(
        private PdoSavedPaletteRepository $repo,
        private string $projectRoot
    ) {}

    public function generateSavedPaletteCard(int $paletteId, int $setId, string $viewerTemplateKey): array
    {
        if ($paletteId <= 0) {
            throw new \InvalidArgumentException('palette_id required');
        }
        if ($setId <= 0) {
            throw new \InvalidArgumentException('set_id required');
        }
        if (!class_exists(\Imagick::class)) {
            throw new RuntimeException('Imagick is required to generate share-card PNGs');
        }

        $viewerTemplateKey = $this->normalizeViewerTemplateKey($viewerTemplateKey);
        $content = $this->repo->getViewerContentForSet($paletteId, $setId, $viewerTemplateKey);
        if (!$content) {
            throw new RuntimeException('Viewer content not found for this palette and template');
        }

        $shareTemplateKey = $viewerTemplateKey === 'concept' ? 'concept' : 'palette';
        $template = $this->repo->getShareCardTemplateByKey($shareTemplateKey);
        if (!$template) {
            throw new RuntimeException('Share-card template not found');
        }

        $templatePath = $this->absoluteProjectPath((string)($template['svg_template_path'] ?? ''));
        if (!is_file($templatePath)) {
            throw new RuntimeException('Share-card SVG template file not found');
        }

        $fields = $this->defaultFieldsForContent($shareTemplateKey, $content);
        $savedFields = $this->decodeJsonObject($content['share_card_fields_json'] ?? null);
        $fields = array_merge($fields, $savedFields);

        $svg = file_get_contents($templatePath);
        if ($svg === false) {
            throw new RuntimeException('Unable to read share-card SVG template');
        }

        $svg = $this->embedRelativePngImages($svg, dirname($templatePath));
        $svg = $this->applyTitle($svg, (string)($fields['TITLE'] ?? ''));
        $svg = $this->replaceScalarPlaceholders($svg, $fields);

        $filename = $this->filename($paletteId, $setId, $viewerTemplateKey, $fields);
        $relativePath = '/share-cards/generated/' . $filename;
        $outputPath = rtrim($this->projectRoot, '/') . $relativePath;
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new RuntimeException('Unable to create share-card output directory');
        }

        $imagick = new \Imagick();
        $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
        $imagick->readImageBlob($svg);
        $imagick->setImageFormat('png');
        $imagick->setImageCompressionQuality(92);
        $imagick->writeImage($outputPath);
        $imagick->clear();
        $imagick->destroy();

        $this->repo->updateViewerContentShareCardImage($paletteId, $setId, $viewerTemplateKey, $relativePath);

        return [
            'image_path' => $relativePath,
            'image_url' => $relativePath . '?v=' . time(),
            'generated_at' => date('c'),
            'fields' => $fields,
        ];
    }

    private function normalizeViewerTemplateKey(string $templateKey): string
    {
        $key = strtolower(trim($templateKey));
        return $key === 'concept' ? 'concept' : 'full_palette';
    }

    private function absoluteProjectPath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            throw new RuntimeException('Share-card template path missing');
        }
        if ($trimmed[0] === '/') {
            return $trimmed;
        }
        return rtrim($this->projectRoot, '/') . '/' . ltrim($trimmed, '/');
    }

    private function defaultFieldsForContent(string $shareTemplateKey, array $content): array
    {
        return [
            'BRAND_LINE' => $shareTemplateKey === 'concept' ? 'YOUR COLORFIX DESIGN CONCEPT' : 'YOUR COLORFIX PALETTE',
            'TITLE' => (string)($content['title'] ?? ''),
            'SUBTITLE' => '',
            'CTA_LABEL' => (string)($content['cta_label'] ?? ($shareTemplateKey === 'concept' ? 'Tap to See the Reveal' : 'Tap to See Colors')),
            'FOOTER_NOTE' => 'ColorFix by Terry',
        ];
    }

    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function embedRelativePngImages(string $svg, string $templateDir): string
    {
        return preg_replace_callback(
            '/href="\.\/([^"]+\.png)"/i',
            function (array $match) use ($templateDir): string {
                $path = $templateDir . '/' . $match[1];
                if (!is_file($path)) {
                    return $match[0];
                }
                $data = base64_encode((string)file_get_contents($path));
                return 'href="data:image/png;base64,' . $data . '"';
            },
            $svg
        ) ?? $svg;
    }

    private function applyTitle(string $svg, string $title): string
    {
        return preg_replace_callback(
            '/<text([^>]*)>\s*\{\{TITLE\}\}\s*<\/text>/i',
            function (array $match) use ($title): string {
                $attrs = $match[1];
                $x = $this->extractAttribute($attrs, 'x') ?? '0';
                $fontSize = (float)($this->extractAttribute($attrs, 'font-size') ?? '56');
                $lineHeight = max(28, (int)round($fontSize * 1.18));
                $lines = $this->wrapText($title, $fontSize);
                if (!$lines) {
                    $lines = [''];
                }
                $tspans = [];
                foreach ($lines as $index => $line) {
                    $dy = $index === 0 ? '0' : (string)$lineHeight;
                    $tspans[] = '<tspan x="' . htmlspecialchars($x, ENT_QUOTES, 'UTF-8') . '" dy="' . $dy . '">' .
                        htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8') .
                        '</tspan>';
                }
                return '<text' . $attrs . '>' . implode('', $tspans) . '</text>';
            },
            $svg,
            1
        ) ?? $svg;
    }

    private function replaceScalarPlaceholders(string $svg, array $fields): string
    {
        foreach ($fields as $key => $value) {
            if ($key === 'TITLE') {
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $svg = str_replace(
                '{{' . strtoupper((string)$key) . '}}',
                htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                $svg
            );
        }
        return $svg;
    }

    private function extractAttribute(string $attributes, string $name): ?string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '="([^"]*)"/i', $attributes, $match)) {
            return $match[1];
        }
        return null;
    }

    private function wrapText(string $text, float $fontSize): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($clean === '') {
            return [];
        }
        $maxChars = $fontSize >= 54 ? 27 : 34;
        $words = preg_split('/\s+/', $clean) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($candidate) > $maxChars && $current !== '') {
                $lines[] = $current;
                $current = $word;
                continue;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return array_slice($lines, 0, 3);
    }

    private function filename(int $paletteId, int $setId, string $templateKey, array $fields): string
    {
        $hash = substr(sha1(json_encode($fields, JSON_UNESCAPED_SLASHES) ?: ''), 0, 12);
        return 'palette-' . $paletteId . '-set-' . $setId . '-' . $templateKey . '-' . $hash . '.png';
    }
}
