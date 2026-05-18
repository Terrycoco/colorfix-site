<?php
declare(strict_types=1);

namespace App\Lib;

class SmtpMailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    /** @var array<string, mixed> */
    private array $lastTransaction = [];

    public function __construct(array $config)
    {
        $this->host      = (string)($config['host'] ?? '');
        $this->port      = (int)($config['port'] ?? 465);
        $this->username  = (string)($config['username'] ?? '');
        $this->password  = (string)($config['password'] ?? '');
        $this->fromEmail = (string)($config['from_email'] ?? $this->username);
        $this->fromName  = (string)($config['from_name'] ?? 'ColorFix');

        if ($this->host === '' || $this->username === '' || $this->password === '') {
            throw new \InvalidArgumentException('SMTP configuration incomplete');
        }
    }

    public function send(string $toEmail, string $subject, string $htmlBody, string $textBody = '', array $options = []): bool
    {
        $toEmail = trim($toEmail);
        if ($toEmail === '') {
            throw new \InvalidArgumentException('Recipient email required');
        }

        $cc = $this->normalizeEmailList($options['cc'] ?? []);
        $bcc = $this->normalizeEmailList($options['bcc'] ?? []);
        $replyTo = trim((string)($options['reply_to'] ?? ''));
        $messageId = trim((string)($options['message_id'] ?? ''));
        $fromEmail = trim((string)($options['from_email'] ?? $this->fromEmail));
        $fromName = trim((string)($options['from_name'] ?? $this->fromName));
        $extraHeaders = is_array($options['headers'] ?? null) ? $options['headers'] : [];

        $boundary = '=_cf_' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . $this->formatAddress($fromEmail, $fromName),
            'To: ' . $toEmail,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if ($cc) {
            $headers[] = 'Cc: ' . implode(', ', $cc);
        }
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        if ($messageId !== '') {
            $headers[] = 'Message-ID: <' . trim($messageId, '<>') . '>';
        }
        foreach ($extraHeaders as $header) {
            $header = trim((string)$header);
            if ($header !== '') $headers[] = $header;
        }

        if ($textBody === '') {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        }

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $textBody . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n" . $htmlBody . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $socketContext = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed'=> false,
            ],
        ]);

        $remote = 'ssl://' . $this->host . ':' . $this->port;
        $fp = stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $socketContext);
        if (!$fp) {
            throw new \RuntimeException('SMTP connect failed: ' . $errstr);
        }

        $connectResponse = $this->expect($fp, 220);
        $this->write($fp, 'EHLO colorfix');
        $this->expect($fp, 250);
        $this->write($fp, 'AUTH LOGIN');
        $this->expect($fp, 334);
        $this->write($fp, base64_encode($this->username));
        $this->expect($fp, 334);
        $this->write($fp, base64_encode($this->password));
        $this->expect($fp, 235);
        $this->write($fp, 'MAIL FROM: <' . $fromEmail . '>');
        $this->expect($fp, 250);
        $this->write($fp, 'RCPT TO: <' . $toEmail . '>');
        $recipientResponses = [trim($this->expect($fp, [250, 251]))];
        foreach (array_merge($cc, $bcc) as $email) {
            $this->write($fp, 'RCPT TO: <' . $email . '>');
            $recipientResponses[] = trim($this->expect($fp, [250, 251]));
        }
        $this->write($fp, 'DATA');
        $this->expect($fp, 354);
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        $this->write($fp, $message);
        $acceptedResponse = $this->expect($fp, 250);
        $this->write($fp, 'QUIT');
        fclose($fp);

        $this->lastTransaction = [
            'transport' => 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'to_email' => $toEmail,
            'cc' => $cc,
            'bcc' => $bcc,
            'from_email' => $fromEmail,
            'message_id' => $messageId,
            'subject' => $subject,
            'connected_response' => trim($connectResponse),
            'recipient_responses' => $recipientResponses,
            'accepted_response' => trim($acceptedResponse),
            'accepted_at_utc' => gmdate('c'),
        ];

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastTransaction(): array
    {
        return $this->lastTransaction;
    }

    private function write($fp, string $line): void
    {
        fwrite($fp, $line . "\r\n");
    }

    private function expect($fp, $expected): string
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^(\d{3})(?:\s|$)/', $line, $matches)) {
                $code = (int)$matches[1];
                if (is_array($expected)) {
                    if (!in_array($code, $expected, true)) {
                        throw new \RuntimeException('Unexpected SMTP response: ' . trim($response));
                    }
                } elseif ($code !== $expected) {
                    throw new \RuntimeException('Unexpected SMTP response: ' . trim($response));
                }
                if ($line[3] === ' ') {
                    break;
                }
            }
        }
        return $response;
    }

    private function formatAddress(string $email, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return $email;
        }
        return sprintf('"%s" <%s>', addslashes($name), $email);
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
}
