<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Use GET'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $baseDir = dirname(__DIR__, 3) . '/content/email-templates';
    $htmlDir = $baseDir . '/html';
    $subjectDir = $baseDir . '/subjects';
    $messageDir = $baseDir . '/messages';
    if (!is_dir($htmlDir)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Missing email templates folder'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
    $templates = [];

    foreach (glob($htmlDir . '/*.html') ?: [] as $path) {
        $fileKey = basename($path, '.html');
        $label = ucwords(str_replace('-', ' ', $fileKey));
        $subject = '';
        $message = '';

        $subjectPath = $subjectDir . '/' . $fileKey . '.txt';
        if (is_file($subjectPath)) {
            $subject = trim((string)file_get_contents($subjectPath));
        }
        $messagePath = $messageDir . '/' . $fileKey . '.txt';
        if (is_file($messagePath)) {
            $message = trim((string)file_get_contents($messagePath));
        }
        $html = (string)file_get_contents($path);

        $templates[] = [
            'key' => $fileKey,
            'label' => $label,
            'subject' => $subject,
            'message' => $message,
            'html' => $html,
        ];
    }

    if ($key !== '') {
        foreach ($templates as $template) {
            if ($template['key'] === $key) {
                echo json_encode(['ok' => true, 'template' => $template], JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Template not found'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    usort($templates, static fn($a, $b) => strcmp($a['label'], $b['label']));
    echo json_encode(['ok' => true, 'templates' => $templates], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
