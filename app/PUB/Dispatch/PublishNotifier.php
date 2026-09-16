<?php
declare(strict_types=1);

namespace App\PUB\Dispatch;

use App\Lib\SmtpMailer;
use RuntimeException;

/**
 * PUB PUBLISH NOTIFIER
 *
 * Sends Terry a simple email after a successfully completed shipment
 * when Schedule handed Dispatch notify_on_publish = true.
 *
 * No channel-specific workflow knowledge belongs here.
 */
final class PublishNotifier
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
                'Publish Notifier requires project root.'
            );
        }
    }


    public function send(
        string $title,
        string $channel
    ): void {
        $title =
            trim(
                $title
            );

        $channel =
            strtolower(
                trim(
                    $channel
                )
            );


        if ($title === '') {
            $title =
                'ColorFix asset';
        }


        if ($channel === '') {
            throw new RuntimeException(
                'Publish Notifier requires channel.'
            );
        }


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


        $channelLabel =
            match ($channel) {
                'youtube' =>
                    'YouTube',

                default =>
                    ucfirst(
                        $channel
                    ),
            };


        $message =
            '"'
            . $title
            . '" has just been shipped to '
            . $channelLabel
            . '.';


        $mailer =
            new SmtpMailer(
                $mailConfig
            );


        $mailer->send(
            $toEmail,
            $message,
            '<p>'
                . htmlspecialchars(
                    $message,
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '</p>',
            $message
        );
    }
}
