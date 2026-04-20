<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\EnvLoader;
use RuntimeException;

class GeminiImageAltTextService
{
    private string $model;

    public function __construct(?string $model = null)
    {
        $this->model = $model ?: (EnvLoader::get('GEMINI_MODEL', 'gemini-2.5-flash') ?? 'gemini-2.5-flash');
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function generateForFile(string $absolutePath, array $context = []): array
    {
        $apiKey = EnvLoader::get('GEMINI_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('GEMINI_API_KEY is missing');
        }
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('Image file is missing or unreadable');
        }

        $info = @getimagesize($absolutePath);
        if (!$info || empty($info['mime'])) {
            throw new RuntimeException('File is not a readable image');
        }

        $bytes = file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Image file could not be read');
        }

        $prompt = ImageMetadataPrompt::build($context);
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inline_data' => [
                            'mime_type' => (string)$info['mime'],
                            'data' => base64_encode($bytes),
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 350,
                'responseMimeType' => 'application/json',
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model)
            . ':generateContent?key='
            . rawurlencode($apiKey);

        $response = $this->postJson($url, $payload);
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return ImageMetadataPrompt::normalizeResult(ImageMetadataPrompt::parseJsonText((string)$text));
    }

    private function postJson(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is not available');
        }
        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('Failed to initialize Gemini request');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $body === '') {
            throw new RuntimeException('Gemini request failed: ' . ($error ?: 'empty response'));
        }

        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? '') : '';
            throw new RuntimeException('Gemini HTTP ' . $status . ($message !== '' ? ': ' . $message : ''));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Gemini response was not valid JSON');
        }
        return $decoded;
    }

}
