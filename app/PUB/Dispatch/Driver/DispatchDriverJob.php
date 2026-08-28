<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Driver;

use RuntimeException;

/**
 * ONE DRIVER'S COMPLETE ASSIGNMENT TICKET.
 *
 * The ticket deliberately does NOT contain the sealed package.
 * pub_asset_id is the durable reference to the box already sitting at
 * pipeline_stage=shipping. The driver reloads that exact box from PUB.
 */
final class DispatchDriverJob
{
    private const MIN_TIMEOUT_SECONDS = 30;
    private const MAX_TIMEOUT_SECONDS = 1800;


    public function __construct(
        private int $pubAssetId,
        private string $routeClass,
        private int $timeoutSeconds = 300,
    ) {
        if ($this->pubAssetId <= 0) {
            throw new RuntimeException(
                'Dispatch driver job requires a valid pub_asset_id.'
            );
        }


        $this->routeClass =
            trim(
                $this->routeClass
            );


        if ($this->routeClass === '') {
            throw new RuntimeException(
                'Dispatch driver job requires a route class.'
            );
        }


        if (
            $this->timeoutSeconds < self::MIN_TIMEOUT_SECONDS
            || $this->timeoutSeconds > self::MAX_TIMEOUT_SECONDS
        ) {
            throw new RuntimeException(
                'Dispatch driver timeout must be between '
                . self::MIN_TIMEOUT_SECONDS
                . ' and '
                . self::MAX_TIMEOUT_SECONDS
                . ' seconds.'
            );
        }
    }


    public function pubAssetId(): int
    {
        return $this->pubAssetId;
    }


    public function routeClass(): string
    {
        return $this->routeClass;
    }


    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }


    public function toArray(): array
    {
        return [
            'pub_asset_id' =>
                $this->pubAssetId,

            'route_class' =>
                $this->routeClass,

            'timeout_seconds' =>
                $this->timeoutSeconds,
        ];
    }


    public function encode(): string
    {
        $json =
            json_encode(
                $this->toArray(),
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );


        return rtrim(
            strtr(
                base64_encode(
                    $json
                ),
                '+/',
                '-_'
            ),
            '='
        );
    }


    public static function decode(
        string $encoded
    ): self {
        $encoded =
            trim(
                $encoded
            );


        if ($encoded === '') {
            throw new RuntimeException(
                'Dispatch driver job ticket is empty.'
            );
        }


        $base64 =
            strtr(
                $encoded,
                '-_',
                '+/'
            );


        $padding =
            strlen(
                $base64
            ) % 4;


        if ($padding !== 0) {
            $base64 .=
                str_repeat(
                    '=',
                    4 - $padding
                );
        }


        $json =
            base64_decode(
                $base64,
                true
            );


        if ($json === false) {
            throw new RuntimeException(
                'Dispatch driver job ticket is not valid base64.'
            );
        }


        $data =
            json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );


        if (!is_array($data)) {
            throw new RuntimeException(
                'Dispatch driver job ticket is invalid.'
            );
        }


        return new self(
            (int)(
                $data[
                    'pub_asset_id'
                ]
                ?? 0
            ),
            (string)(
                $data[
                    'route_class'
                ]
                ?? ''
            ),
            (int)(
                $data[
                    'timeout_seconds'
                ]
                ?? 300
            )
        );
    }
}
