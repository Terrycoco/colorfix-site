<?php
declare(strict_types=1);

namespace App\ERR\Services;

use App\Lib\SmtpMailer;
use RuntimeException;

/** Shared ERR email formatting; uses ColorFix's existing SMTP transport. */
final class ErrorEmailNotifier
{
    public function __construct(private string $projectRoot) {}

    /** @param array<string, mixed> $registration @param array<string, mixed> $incident */
    public function send(array $registration, array $incident): void
    {
        $path = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . '/config/mail.php';
        if (!is_file($path)) {
            throw new RuntimeException('Missing ERR notification mail configuration.');
        }
        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('Invalid ERR notification mail configuration.');
        }
        $recipient = trim((string)($config['notification_email'] ?? $config['from_email'] ?? $config['username'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('ERR notification recipient is not configured.');
        }

        $code = (int)($incident['err_num'] ?? 0);
        $department = (string)($incident['department'] ?? 'ColorFix');
        $problem = trim((string)($registration['description'] ?? $incident['message'] ?? 'Error requires attention'));
        $instructions = trim((string)($registration['explanation'] ?? ''));
        $resource = trim((string)($incident['resource_type'] ?? ''));
        $resourceId = (int)($incident['resource_id'] ?? 0);
        $subject = "[ColorFix ERR] Action required: {$department} {$code} — {$problem}";
        $lines = [
            'ColorFix ERR needs your attention.',
            '',
            "Error: {$department} {$code}",
            "Problem: {$problem}",
        ];
        if ($resource !== '' && $resourceId > 0) {
            $lines[] = "Affected resource: {$resource} #{$resourceId}";
        }
        if ($instructions !== '') {
            $lines[] = '';
            $lines[] = 'What to do: ' . $instructions;
        }
        $lines[] = '';
        $lines[] = 'Open ColorFix Admin → ERR → Working to review the interrupted work.';
        $plain = implode(PHP_EOL, $lines);
        $html = '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')) . '</p>';
        (new SmtpMailer($config))->send($recipient, $subject, $html, $plain);
    }
}
