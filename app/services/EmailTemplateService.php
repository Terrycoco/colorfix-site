<?php
declare(strict_types=1);

namespace App\Services;

class EmailTemplateService
{
    private function brandConfig(): array
    {
        static $config = null;
        if (is_array($config)) return $config;

        $path = dirname(__DIR__, 2) . '/config/brand.php';
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $config = $loaded;
                return $config;
            }
        }

        $config = [];
        return $config;
    }

    private function brandName(): string
    {
        $config = $this->brandConfig();
        return trim((string)($config['name'] ?? 'ColorFix')) ?: 'ColorFix';
    }

    private function logoUrl(): string
    {
        $config = $this->brandConfig();
        return trim((string)($config['logo_url'] ?? 'https://colorfix.terrymarr.com/logo.png'))
            ?: 'https://colorfix.terrymarr.com/logo.png';
    }

    private function homeUrl(): string
    {
        $config = $this->brandConfig();
        return trim((string)($config['home_url'] ?? 'https://colorfix.terrymarr.com/'))
            ?: 'https://colorfix.terrymarr.com/';
    }

    private function wrapBrandedHtml(string $bodyHtml): string
    {
        $logoUrl = htmlspecialchars($this->logoUrl(), ENT_QUOTES, 'UTF-8');
        $homeUrl = htmlspecialchars($this->homeUrl(), ENT_QUOTES, 'UTF-8');
        $brandName = htmlspecialchars($this->brandName(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Helvetica Neue',Arial,sans-serif;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f1f5f9;padding:24px 0;">
    <tr>
      <td align="left">
        <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="background:#ffffff;border-radius:16px;box-shadow:0 6px 18px rgba(15,23,42,0.08);padding:32px;margin:0;">
          <tr>
            <td>
              $bodyHtml
            </td>
          </tr>
          <tr>
            <td style="text-align:center;padding-top:56px;">
              <a href="$homeUrl" target="_blank" rel="noopener noreferrer" style="display:inline-block;">
                <img src="$logoUrl" alt="$brandName" width="176" style="display:block;margin:0 auto;border:0;">
              </a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private function normalizeHtmlBody(string $htmlBody): string
    {
        $trimmed = trim($htmlBody);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $trimmed, $matches)) {
            $trimmed = trim((string)($matches[1] ?? ''));
        }

        $trimmed = preg_replace('/<img[^>]+src="[^"]*(logo\.png|white-brush|full_lightbg)[^"]*"[^>]*>/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/<div[^>]*>\s*COLORFIX\s*<\/div>/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/<div[^>]*>\s*&copy;\s*ColorFix\s*<\/div>/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/<div[^>]*>\s*©\s*ColorFix\s*<\/div>/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/<p[^>]*>\s*&copy;\s*ColorFix\s*<\/p>/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/<p[^>]*>\s*©\s*ColorFix\s*<\/p>/i', '', $trimmed) ?? $trimmed;

        return trim($trimmed);
    }

    public function renderBrandedHtmlEmail(
        string $subject,
        string $htmlBody,
        ?string $textBody = null
    ): array
    {
        $subject = trim($subject) !== '' ? trim($subject) : 'ColorFix email';
        $trimmedHtml = $this->normalizeHtmlBody($htmlBody);
        $html = $this->wrapBrandedHtml(<<<HTML
  <style>
    .cf-email-content p { margin: 0 0 12px; font-size: 16px; line-height: 1.55; color: #0f172a; }
    .cf-email-content a { color: #2563eb; text-decoration: underline; text-decoration-thickness: 2px; text-underline-offset: 2px; font-weight: 600; }
    .cf-email-content h1,
    .cf-email-content h2,
    .cf-email-content h3 { margin: 0 0 12px; color: #0f172a; line-height: 1.2; }
    .cf-email-content ul,
    .cf-email-content ol { margin: 0 0 12px; padding-left: 20px; color: #0f172a; }
    .cf-email-content li { margin-bottom: 6px; }
  </style>
  <div class="cf-email-content">
    $trimmedHtml
  </div>
HTML);

        $plain = trim((string)$textBody);
        if ($plain === '') {
            $plain = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $trimmedHtml)));
        }

        return [$subject, $html, $plain];
    }

    public function renderShareEmail(
        string $subject,
        string $message,
        string $link
    ): array
    {
        $subject = trim($subject) !== '' ? trim($subject) : 'ColorFix link';
        $messageText = trim($message) !== ''
            ? trim($message)
            : "I wanted to share this ColorFix link with you.\n\nWhat do you think?";

        $escapedMessage = htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8');
        $escapedMessage = str_replace('  ', '&nbsp;&nbsp;', $escapedMessage);
        $messageHtml = '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#0f172a;">' . nl2br($escapedMessage) . '</p>';

        $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $html = $this->wrapBrandedHtml(<<<HTML
  <p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#0f172a;">Hi —</p>
  $messageHtml
  <p style="margin:24px 0;text-align:center;">
    <a href="$linkEsc" style="display:inline-block;background:#ef6d00;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:999px;font-size:15px;font-weight:600;">View on ColorFix</a>
  </p>
  <p style="margin:0;font-size:14px;line-height:1.5;color:#475569;">If the button doesn’t work, copy/paste this link:<br><span style="color:#2563eb;">$linkEsc</span></p>
HTML);

        $text = "Hi —\n\n" . $messageText . "\n\nView on ColorFix: $link";

        return [$subject, $html, $text];
    }

    public function renderPaletteEmail(
        array $palette,
        array $members,
        string $link,
        ?string $customMessage = null,
        ?string $subjectOverride = null,
        ?string $renderImageUrl = null
    ): array
    {
        $paletteTitle = trim((string)($palette['display_title'] ?? ''));
        if ($paletteTitle === '') {
            $paletteTitle = 'ColorFix Palette';
        }
        $clientName = trim((string)($palette['client_name'] ?? ''));
        $subject = trim((string)$subjectOverride) !== ''
            ? trim((string)$subjectOverride)
            : sprintf('Your ColorFix palette - %s', $paletteTitle);

        $clientGreeting = $clientName !== ''
            ? sprintf('<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#0f172a;">Hi %s —</p>', htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'))
            : '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#0f172a;">Hi —</p>';

        $messageText = trim((string)$customMessage);
        if ($messageText === '') {
            $messageText = "I wanted to share this color palette with you.\n\nThe link shows the colors together so you can get a feel for the overall look.\n\nWhat do you think?";
        }
        $escapedMessage = htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8');
        $escapedMessage = str_replace('  ', '&nbsp;&nbsp;', $escapedMessage);
        $messageHtml = '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#0f172a;">' . nl2br($escapedMessage) . '</p>';

        $renderBlock = '';
        if ($renderImageUrl) {
            $renderEsc = htmlspecialchars($renderImageUrl, ENT_QUOTES, 'UTF-8');
            $renderBlock = '<div style="margin:0 0 20px;"><img src="' . $renderEsc . '" alt="ColorFix rendering" style="width:100%;border-radius:12px;display:block;"></div>';
        }

        $html = $this->wrapBrandedHtml(<<<HTML
  $clientGreeting
  $messageHtml
  $renderBlock
  <p style="margin:24px 0;text-align:center;">
    <a href="$link" style="display:inline-block;background:#ef6d00;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:999px;font-size:15px;font-weight:600;">View on ColorFix</a>
  </p>
  <p style="margin:0;font-size:14px;line-height:1.5;color:#475569;">If the button doesn’t work, copy/paste this link:<br><span style="color:#2563eb;">$link</span></p>
HTML);

        $text = "Hi —\n\n" . $messageText . "\n\nView on ColorFix: $link";

        return [$subject, $html, $text];
    }

    public function renderShareEmailHtml(
        string $subject,
        string $htmlBody,
        ?string $textBody,
        string $link
    ): array
    {
        [$subject, $html, $plain] = $this->renderBrandedHtmlEmail($subject, $htmlBody, $textBody);
        if (trim($plain) === '') {
            $plain = "Hi —\n\nView on ColorFix: {$link}";
        }
        $plain = str_replace('{{link}}', $link, $plain);

        return [$subject, $html, $plain];
    }

    public function renderPermissionRequestEmail(
        string $recipientName,
        string $subject,
        string $message,
        string $siteUrl
    ): array
    {
        $recipientName = trim($recipientName);
        $subject = trim($subject) !== '' ? trim($subject) : 'Permission request from ColorFix';
        $messageText = trim($message) !== ''
            ? trim($message)
            : "I'd love permission to feature your photos on ColorFix.\n\nPlease reply to this email and let me know if that's okay.";

        $greeting = $recipientName !== ''
            ? 'Hi ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ' —'
            : 'Hi —';

        $escapedMessage = htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8');
        $escapedMessage = str_replace('  ', '&nbsp;&nbsp;', $escapedMessage);
        $messageHtml = '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#0f172a;">' . nl2br($escapedMessage) . '</p>';
        $siteEsc = htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8');
        $html = $this->wrapBrandedHtml(<<<HTML
  <p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#0f172a;">$greeting</p>
  $messageHtml
  <p style="margin:0;font-size:14px;line-height:1.5;color:#475569;">ColorFix site: <span style="color:#2563eb;">$siteEsc</span></p>
HTML);

        $text = ($recipientName !== '' ? "Hi {$recipientName} —" : 'Hi —') . "\n\n" . $messageText . "\n\nColorFix site: {$siteUrl}";

        return [$subject, $html, $text];
    }
}
