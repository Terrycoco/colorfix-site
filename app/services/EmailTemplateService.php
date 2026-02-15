<?php
declare(strict_types=1);

namespace App\Services;

class EmailTemplateService
{
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
        $messageHtml = '<p style="margin:0 0 16px;font-size:15px;color:#0f172a;">' . nl2br($escapedMessage) . '</p>';

        $logoUrl = 'https://colorfix.terrymarr.com/logo.png';
        $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Helvetica Neue',Arial,sans-serif;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f1f5f9;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="background:#ffffff;border-radius:16px;box-shadow:0 6px 18px rgba(15,23,42,0.08);padding:32px;">
          <tr>
            <td>
              <p style="margin:0 0 16px;font-size:15px;color:#0f172a;">Hi —</p>
              $messageHtml
              <p style="margin:24px 0;text-align:center;">
                <a href="$linkEsc" style="display:inline-block;background:#ef6d00;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:999px;font-size:15px;font-weight:600;">View on ColorFix</a>
              </p>
              <p style="margin:0;font-size:13px;color:#475569;">If the button doesn’t work, copy/paste this link:<br><span style="color:#2563eb;">$linkEsc</span></p>
            </td>
          </tr>
          <tr>
            <td style="text-align:center;padding-top:32px;">
              <img src="$logoUrl" alt="ColorFix" width="100" style="display:block;margin:0 auto 8px;">
              <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.2em;color:#94a3b8;">COLORFIX</div>
            </td>
          </tr>
        </table>
        <div style="font-size:12px;color:#94a3b8;margin-top:16px;">© ColorFix</div>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

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
        $nickname = trim((string)($palette['nickname'] ?? 'Saved Palette'));
        $clientName = trim((string)($palette['client_name'] ?? ''));
        $subject = trim((string)$subjectOverride) !== ''
            ? trim((string)$subjectOverride)
            : sprintf('Your ColorFix palette – %s', $nickname);

        $logoUrl = 'https://colorfix.terrymarr.com/logo.png';

        $clientGreeting = $clientName !== ''
            ? sprintf('<p style="margin:0 0 16px;font-size:15px;color:#0f172a;">Hi %s —</p>', htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'))
            : '<p style="margin:0 0 16px;font-size:15px;color:#0f172a;">Hi —</p>';

        $messageText = trim((string)$customMessage);
        if ($messageText === '') {
            $messageText = "I wanted to share this color palette with you.\n\nThe link shows the colors together so you can get a feel for the overall look.\n\nWhat do you think?";
        }
        $escapedMessage = htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8');
        $escapedMessage = str_replace('  ', '&nbsp;&nbsp;', $escapedMessage);
        $messageHtml = '<p style="margin:0 0 16px;font-size:15px;color:#0f172a;">' . nl2br($escapedMessage) . '</p>';

        $renderBlock = '';
        if ($renderImageUrl) {
            $renderEsc = htmlspecialchars($renderImageUrl, ENT_QUOTES, 'UTF-8');
            $renderBlock = '<div style="margin:0 0 20px;"><img src="' . $renderEsc . '" alt="ColorFix rendering" style="width:100%;border-radius:12px;display:block;"></div>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Helvetica Neue',Arial,sans-serif;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f1f5f9;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="background:#ffffff;border-radius:16px;box-shadow:0 6px 18px rgba(15,23,42,0.08);padding:32px;">
          <tr>
            <td>
              $clientGreeting
              $messageHtml
              $renderBlock
              <p style="margin:24px 0;text-align:center;">
                <a href="$link" style="display:inline-block;background:#ef6d00;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:999px;font-size:15px;font-weight:600;">View on ColorFix</a>
              </p>
              <p style="margin:0;font-size:13px;color:#475569;">If the button doesn’t work, copy/paste this link:<br><span style="color:#2563eb;">$link</span></p>
            </td>
          </tr>
          <tr>
            <td style="text-align:center;padding-top:32px;">
              <img src="$logoUrl" alt="ColorFix" width="100" style="display:block;margin:0 auto 8px;">
              <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.2em;color:#94a3b8;">COLORFIX</div>
            </td>
          </tr>
        </table>
        <div style="font-size:12px;color:#94a3b8;margin-top:16px;">© ColorFix</div>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

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
        $subject = trim($subject) !== '' ? trim($subject) : 'ColorFix link';
        $logoUrl = 'https://colorfix.terrymarr.com/logo.png';
        $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        $trimmedHtml = trim($htmlBody);
        $isFullHtml = stripos($trimmedHtml, '<html') !== false || stripos($trimmedHtml, '<body') !== false;

        if ($isFullHtml) {
            $html = $trimmedHtml;
        } else {
            $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Helvetica Neue',Arial,sans-serif;">
  <style>
    .cf-email-content p { margin: 0 0 12px; font-size: 15px; line-height: 1.5; color: #0f172a; }
    .cf-email-content a { color: #2563eb; text-decoration: underline; text-decoration-thickness: 2px; text-underline-offset: 2px; font-weight: 600; }
    .cf-email-content h1,
    .cf-email-content h2,
    .cf-email-content h3 { margin: 0 0 12px; color: #0f172a; line-height: 1.2; }
    .cf-email-content ul,
    .cf-email-content ol { margin: 0 0 12px; padding-left: 20px; color: #0f172a; }
    .cf-email-content li { margin-bottom: 6px; }
  </style>
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f1f5f9;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" width="560" style="background:#ffffff;border-radius:16px;box-shadow:0 6px 18px rgba(15,23,42,0.08);padding:32px;">
          <tr>
            <td>
              <div class="cf-email-content">
                $trimmedHtml
              </div>
            </td>
          </tr>
          <tr>
            <td style="text-align:center;padding-top:32px;">
              <img src="$logoUrl" alt="ColorFix" width="100" style="display:block;margin:0 auto 8px;">
              <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.2em;color:#94a3b8;">COLORFIX</div>
            </td>
          </tr>
        </table>
        <div style="font-size:12px;color:#94a3b8;margin-top:16px;">© ColorFix</div>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
        }

        $plain = trim((string)$textBody);
        if ($plain === '') {
            $plain = "Hi —\n\nView on ColorFix: {$link}";
        }
        $plain = str_replace('{{link}}', $link, $plain);

        return [$subject, $html, $plain];
    }
}
