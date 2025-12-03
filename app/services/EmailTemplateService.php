<?php
declare(strict_types=1);

namespace App\Services;

class EmailTemplateService
{
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

        $membersHtml = '';
        foreach ($members as $member) {
            $hex = strtoupper((string)($member['color_hex6'] ?? 'CCCCCC'));
            if ($hex === '') { $hex = 'CCCCCC'; }
            $hex = ltrim($hex, '#');
            $name = htmlspecialchars((string)($member['color_name'] ?? 'Color'), ENT_QUOTES, 'UTF-8');
            $code = htmlspecialchars((string)($member['color_code'] ?? ''), ENT_QUOTES, 'UTF-8');
            $membersHtml .= sprintf(
                '<tr><td width="48" height="48" style="background:#%1$s;border-radius:8px;"></td><td style="padding-left:12px;"><div style="font-size:15px;color:#0f172a;font-weight:600;line-height:1.2;">%2$s</div><div style="font-size:13px;color:#475569;">%3$s</div></td></tr><tr><td colspan="2" style="height:12px;"></td></tr>',
                $hex,
                $name,
                $code
            );
        }

        $clientGreeting = $clientName !== ''
            ? sprintf('<p style="margin:0 0 16px;font-size:15px;color:#0f172a;">Hi %s —</p>', htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'))
            : '<p style="margin:0 0 16px;font-size:15px;color:#0f172a;">Hi there —</p>';

        $messageText = trim((string)$customMessage);
        if ($messageText === '') {
            $messageText = sprintf(
                "Here’s the palette we worked on for your project.\n\nLet me know what you think!",
                $nickname
            );
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
              <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                $membersHtml
              </table>
              <p style="margin:24px 0;text-align:center;">
                <a href="$link" style="display:inline-block;background:#ef6d00;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:999px;font-size:15px;font-weight:600;">View Palette</a>
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

        $text = $messageText . "\n\nView your palette here: $link";

        return [$subject, $html, $text];
    }
}
