<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\EnvLoader;
use RuntimeException;

class OpenAiImageAltTextService
{
    private string $model;

    public function __construct(?string $model = null)
    {
        $this->model = $model ?: (EnvLoader::get('OPENAI_IMAGE_METADATA_MODEL', 'gpt-5.4-mini') ?? 'gpt-5.4-mini');
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function generateForFile(string $absolutePath, array $context = []): array
    {
        $apiKey = EnvLoader::get('OPENAI_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('OPENAI_API_KEY is missing');
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

        $payload = [
            'model' => $this->model,
            'input' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => ImageMetadataPrompt::build($context),
                    ],
                    [
                        'type' => 'input_image',
                        'image_url' => 'data:' . (string)$info['mime'] . ';base64,' . base64_encode($bytes),
                    ],
                ],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_object',
                ],
            ],
            'max_output_tokens' => 350,
        ];

        $response = $this->postJson('https://api.openai.com/v1/responses', $payload, $apiKey);
        $text = $this->extractOutputText($response);
        return ImageMetadataPrompt::normalizeResult(ImageMetadataPrompt::parseJsonText($text));
    }

    private function postJson(string $url, array $payload, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is not available');
        }
        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('Failed to initialize OpenAI request');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $body === '') {
            throw new RuntimeException('OpenAI request failed: ' . ($error ?: 'empty response'));
        }

        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? '') : '';
            throw new RuntimeException('OpenAI HTTP ' . $status . ($message !== '' ? ': ' . $message : ''));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI response was not valid JSON');
        }
        return $decoded;
    }

    private function extractOutputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }
        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    return (string)$content['text'];
                }
            }
        }
        throw new RuntimeException('OpenAI returned no output text');
    }
}
