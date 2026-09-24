<?php
declare(strict_types=1);

namespace App\PUB\Errors;

use App\Lib\SmtpMailer;
use RuntimeException;

/**
 * PUB CRITICAL ERROR NOTIFIER
 *
 * Uses the same SMTP transport and mail configuration already used by
 * Dispatch's PublishNotifier.
 *
 * This class only owns the critical-error message.
 */
final class CriticalErrorNotifier
{
    public function __construct(
        private string $projectRoot
    ) {
        $this->projectRoot =
            rtrim(
                trim(
                    $this->projectRoot
                ),
                DIRECTORY_SEPARATOR
            );


        if ($this->projectRoot === '') {
            throw new RuntimeException(
                'Critical Error Notifier requires project root.'
            );
        }
    }


    /**
     * @param array<string, mixed> $failure
     * @param array<string, mixed> $context
     */
    public function send(
        array $failure,
        array $context = []
    ): void {
        $mailConfigPath =
            $this->projectRoot
            . '/config/mail.php';


        if (!is_file($mailConfigPath)) {
            throw new RuntimeException(
                'Missing mail.php config at '
                . $mailConfigPath
            );
        }


        $mailConfig =
            require $mailConfigPath;


        if (!is_array($mailConfig)) {
            throw new RuntimeException(
                'PUB notification mail configuration is invalid.'
            );
        }


        $toEmail =
            trim(
                (string)(
                    $mailConfig[
                        'notification_email'
                    ]
                    ?? $mailConfig[
                        'from_email'
                    ]
                    ?? $mailConfig[
                        'username'
                    ]
                    ?? ''
                )
            );


        if (
            $toEmail === ''
            || !filter_var(
                $toEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'PUB notification recipient email is not configured.'
            );
        }


        $code =
            trim(
                (string)(
                    $failure[
                        'code'
                    ]
                    ?? PubErrorCode::PUB_FAILURE
                )
            );

        $stage =
            trim(
                (string)(
                    $failure[
                        'stage'
                    ]
                    ?? ''
                )
            );

        $channel =
            trim(
                (string)(
                    $context[
                        'channel'
                    ]
                    ?? ''
                )
            );

        $pubAssetId =
            (int)(
                $failure[
                    'pub_asset_id'
                ]
                ?? 0
            );

        $message =
            trim(
                (string)(
                    $failure[
                        'error'
                    ]
                    ?? 'PUB encountered a critical error.'
                )
            );


        $subject =
            '[ColorFix PUB] Critical error: '
            . (
                $code !== ''
                    ? $code
                    : PubErrorCode::PUB_FAILURE
            );


        $lines = [
            'ColorFix PUB needs attention.',
            '',
            'Code: '
                . (
                    $code !== ''
                        ? $code
                        : PubErrorCode::PUB_FAILURE
                ),
        ];


        if ($stage !== '') {
            $lines[] =
                'Stage: '
                . $stage;
        }

        if ($channel !== '') {
            $lines[] =
                'Channel: '
                . $channel;
        }

        if ($pubAssetId > 0) {
            $lines[] =
                'Asset: #'
                . $pubAssetId;
        }


        $lines[] = '';
        $lines[] =
            'Message: '
            . $message;


        $text =
            implode(
                PHP_EOL,
                $lines
            );


        $html =
            '<p><strong>ColorFix PUB needs attention.</strong></p>'
            . '<p>'
            . nl2br(
                htmlspecialchars(
                    implode(
                        PHP_EOL,
                        array_slice(
                            $lines,
                            2
                        )
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                )
            )
            . '</p>';


        $mailer =
            new SmtpMailer(
                $mailConfig
            );


        $mailer->send(
            $toEmail,
            $subject,
            $html,
            $text
        );
    }
}
