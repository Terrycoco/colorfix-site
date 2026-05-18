<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\AppTime;
use App\Lib\SmtpMailer;
use App\Repos\PdoClientActivityRepository;
use App\Repos\PdoClientEmailRepository;
use App\Repos\PdoClientRepository;
use App\Repos\PdoEmailTemplateRepository;
use InvalidArgumentException;

final class ClientEmailService
{
    public function __construct(
        private PdoClientRepository $clientRepo,
        private PdoClientEmailRepository $emailRepo,
        private PdoClientActivityRepository $activityRepo,
        private PdoEmailTemplateRepository $templateRepo
    ) {}

    public function sendClientEmail(array $payload): array
    {
        $clientId = (int)($payload['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw new InvalidArgumentException('client_id required');
        }

        $client = $this->clientRepo->findById($clientId);
        if (!$client) {
            throw new InvalidArgumentException('client not found');
        }

        $toEmail = strtolower(trim((string)($payload['to_email'] ?? $client['email'] ?? '')));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Valid to_email required');
        }

        $templateKey = trim((string)($payload['template_key'] ?? ''));
        $template = $templateKey !== '' ? $this->templateRepo->findByKey($templateKey) : null;

        $siteUrl = trim((string)($payload['site_url'] ?? 'https://colorfix.terrymarr.com'));
        $subject = trim((string)($payload['subject'] ?? ''));
        $message = trim((string)($payload['message'] ?? ''));
        $htmlBody = trim((string)($payload['html_body'] ?? ''));
        $purpose = $this->normalizeOptionalString($payload['purpose'] ?? null);
        $activityType = $this->normalizeOptionalString($payload['activity_type'] ?? null) ?? 'email_sent';
        $activitySummary = $this->normalizeOptionalString($payload['activity_summary'] ?? null);
        $markPermissionRequested = !empty($payload['mark_permission_requested']);

        $resolvedFirstName = trim((string)($client['first_name'] ?? ''));
        if ($resolvedFirstName === '') {
            $resolvedName = trim((string)($client['name'] ?? ''));
            if ($resolvedName !== '') {
                $parts = preg_split('/\s+/', $resolvedName) ?: [];
                $resolvedFirstName = trim((string)($parts[0] ?? ''));
            }
        }

        $tokens = [
            '{client_name}' => $resolvedFirstName,
            '{{client_name}}' => $resolvedFirstName,
            '{client_first_name}' => $resolvedFirstName,
            '{{client_first_name}}' => $resolvedFirstName,
            '{client-first-name}' => $resolvedFirstName,
            '{{client-first-name}}' => $resolvedFirstName,
            '{site_url}' => $siteUrl,
            '{{site_url}}' => $siteUrl,
        ];
        $hydrate = static function (?string $value) use ($tokens): string {
            return str_replace(array_keys($tokens), array_values($tokens), trim((string)$value));
        };

        $subject = $hydrate($subject !== '' ? $subject : (string)($template['subject_template'] ?? ''));
        $message = $hydrate($message !== '' ? $message : (string)($template['message_template'] ?? ''));
        $htmlBody = $hydrate(
            $htmlBody !== ''
                ? $htmlBody
                : ($message === '' ? (string)($template['html_template'] ?? '') : '')
        );

        if ($subject === '') {
            throw new InvalidArgumentException('subject required');
        }
        if ($message === '' && $htmlBody === '') {
            throw new InvalidArgumentException('message or html_body required');
        }

        $emailTemplateService = new EmailTemplateService();
        if ($htmlBody !== '') {
            [$finalSubject, $finalHtmlBody, $finalTextBody] = $emailTemplateService->renderBrandedHtmlEmail(
                $subject,
                $htmlBody,
                $message
            );
        } else {
            $finalSubject = $subject;
            $finalHtmlBody = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
            [$finalSubject, $finalHtmlBody, $finalTextBody] = $emailTemplateService->renderBrandedHtmlEmail(
                $finalSubject,
                '<p>' . str_replace("\n", "</p><p>", $finalHtmlBody) . '</p>',
                $message
            );
        }

        $projectRoot = dirname(__DIR__, 2);
        $mailConfigPath = $projectRoot . '/config/mail.php';
        if (!is_file($mailConfigPath)) {
            throw new \RuntimeException('Missing mail.php config at ' . $mailConfigPath);
        }
        $mailConfig = require $mailConfigPath;
        $mailer = new SmtpMailer($mailConfig);

        $ccList = $this->normalizeEmailList($payload['cc'] ?? $payload['cc_emails'] ?? []);
        $bccList = $this->normalizeEmailList($payload['bcc'] ?? $payload['bcc_emails'] ?? []);
        $sentAt = AppTime::now();
        $messageId = sprintf(
            'client-email-%d-%s@%s',
            $clientId,
            bin2hex(random_bytes(8)),
            preg_replace('/[^a-z0-9.-]+/i', '', parse_url($siteUrl, PHP_URL_HOST) ?: 'colorfix.local')
        );

        $mailer->send($toEmail, $finalSubject, $finalHtmlBody, $finalTextBody, [
            'cc' => $ccList,
            'bcc' => $bccList,
            'message_id' => $messageId,
        ]);
        $smtpTransaction = $mailer->getLastTransaction();

        error_log('[client-email] ' . json_encode([
            'client_id' => $clientId,
            'to_email' => $toEmail,
            'subject' => $finalSubject,
            'message_id' => $messageId,
            'smtp' => $smtpTransaction,
        ], JSON_UNESCAPED_SLASHES));

        $clientEmailId = $this->emailRepo->create([
            'client_id' => $clientId,
            'direction' => 'outbound',
            'status' => 'sent',
            'purpose' => $purpose,
            'template_key' => $templateKey !== '' ? $templateKey : null,
            'from_email' => (string)($mailConfig['from_email'] ?? $mailConfig['username'] ?? ''),
            'to_email' => $toEmail,
            'cc_emails' => $ccList ? implode(', ', $ccList) : null,
            'bcc_emails' => $bccList ? implode(', ', $bccList) : null,
            'subject' => $finalSubject,
            'text_body' => $finalTextBody,
            'html_body' => $finalHtmlBody,
            'provider_message_id' => $messageId,
            'sent_at' => $sentAt,
        ]);

        if ($markPermissionRequested) {
            $this->clientRepo->update($clientId, [
                'photo_permission_status' => 'requested',
                'photo_permission_requested_at' => $sentAt,
            ]);
        }

        $this->activityRepo->create([
            'client_id' => $clientId,
            'activity_type' => $activityType,
            'summary' => $activitySummary ?? ('Email sent: ' . $finalSubject),
            'details' => $finalTextBody,
            'related_client_email_id' => $clientEmailId,
            'metadata_json' => json_encode([
                'to_email' => $toEmail,
                'cc_emails' => $ccList,
                'bcc_emails' => $bccList,
                'purpose' => $purpose,
                'template_key' => $templateKey !== '' ? $templateKey : null,
                'status' => 'sent',
                'message_id' => $messageId,
                'smtp' => $smtpTransaction,
            ], JSON_UNESCAPED_SLASHES),
            'occurred_at' => $sentAt,
        ]);

        $emailRow = $this->emailRepo->findById($clientEmailId);
        if (!$emailRow) {
            throw new \RuntimeException('Failed to load saved client email');
        }

        return [
            'client' => $client,
            'client_email' => $emailRow,
            'sent_at' => $sentAt,
            'permission_status' => $markPermissionRequested ? 'requested' : ($client['photo_permission_status'] ?? 'unknown'),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeEmailList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/\s*,\s*/', trim((string)$value)) ?: [];
        }
        $normalized = [];
        foreach ($items as $item) {
            $email = strtolower(trim((string)$item));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $normalized[$email] = $email;
        }
        return array_values($normalized);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string)$value);
        return $normalized === '' ? null : $normalized;
    }
}
