<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\AppTime;
use App\Repos\PdoClientActivityRepository;
use App\Repos\PdoClientEmailRepository;
use InvalidArgumentException;

final class SiteCommentService
{
    public function __construct(
        private ClientService $clientService,
        private PdoClientEmailRepository $emailRepo,
        private PdoClientActivityRepository $activityRepo
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array{client:array<string,mixed>, client_email_id:int, client_activity_id:int}
     */
    public function receive(array $payload): array
    {
        $firstName = $this->normalizeOptionalString($payload['first_name'] ?? null, 100);
        $lastName = $this->normalizeOptionalString($payload['last_name'] ?? null, 100);
        $email = strtolower($this->normalizeRequiredString($payload['email'] ?? null, 'email'));
        $message = $this->normalizeRequiredString($payload['message'] ?? null, 'message');
        $sourceUrl = $this->normalizeOptionalString($payload['source_url'] ?? null, 1000);
        $userAgent = $this->normalizeOptionalString($payload['user_agent'] ?? null, 1000);
        $ipAddress = $this->normalizeOptionalString($payload['ip_address'] ?? null, 100);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Valid email required');
        }
        if (mb_strlen($message) > 5000) {
            throw new InvalidArgumentException('Message is too long');
        }

        $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
        $displayName = $fullName !== '' ? $fullName : $email;
        $client = $this->clientService->findOrCreateByEmail($email, $fullName !== '' ? $fullName : null);
        $receivedAt = AppTime::now();
        $subject = 'Site note from ' . $displayName;
        $textBody = "Name: " . ($fullName !== '' ? $fullName : '-') . "\n"
            . "First name: " . ($firstName ?? '-') . "\n"
            . "Last name: " . ($lastName ?? '-') . "\n"
            . "Email: {$email}\n"
            . "Page: " . ($sourceUrl ?: '-') . "\n\n"
            . "Message:\n{$message}";
        $htmlBody = $this->buildHtmlBody($fullName, $firstName, $lastName, $email, $sourceUrl, $message);

        $clientEmailId = $this->emailRepo->create([
            'client_id' => (int)$client['id'],
            'direction' => 'inbound',
            'status' => 'received',
            'purpose' => 'site_note',
            'template_key' => null,
            'from_email' => $email,
            'to_email' => 'terry@terrymarr.com',
            'subject' => $subject,
            'text_body' => $textBody,
            'html_body' => $htmlBody,
            'received_at' => $receivedAt,
        ]);

        $clientActivityId = $this->activityRepo->create([
            'client_id' => (int)$client['id'],
            'activity_type' => 'site_note_received',
            'summary' => 'Site note received',
            'details' => $message,
            'related_client_email_id' => $clientEmailId,
            'metadata_json' => json_encode([
                'source_url' => $sourceUrl,
                'user_agent' => $userAgent,
                'ip_address' => $ipAddress,
            ], JSON_UNESCAPED_SLASHES),
            'occurred_at' => $receivedAt,
            'admin_read_at' => null,
        ]);

        return [
            'client' => $client,
            'client_email_id' => $clientEmailId,
            'client_activity_id' => $clientActivityId,
        ];
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = trim((string)$value);
        if ($normalized === '') {
            throw new InvalidArgumentException("{$field} required");
        }
        return $normalized;
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        $normalized = trim((string)$value);
        return $normalized === '' ? null : mb_substr($normalized, 0, $maxLength);
    }

    private function buildHtmlBody(
        string $fullName,
        ?string $firstName,
        ?string $lastName,
        string $email,
        ?string $sourceUrl,
        string $message
    ): string {
        $safeFullName = htmlspecialchars($fullName !== '' ? $fullName : '-', ENT_QUOTES, 'UTF-8');
        $safeFirstName = htmlspecialchars($firstName ?? '-', ENT_QUOTES, 'UTF-8');
        $safeLastName = htmlspecialchars($lastName ?? '-', ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeSourceUrl = $sourceUrl ? htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') : '-';
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        return "
<p><strong>Name:</strong> {$safeFullName}</p>
<p><strong>First name:</strong> {$safeFirstName}</p>
<p><strong>Last name:</strong> {$safeLastName}</p>
<p><strong>Email:</strong> {$safeEmail}</p>
<p><strong>Page:</strong> {$safeSourceUrl}</p>
<p><strong>Message:</strong><br />{$safeMessage}</p>
";
    }
}
