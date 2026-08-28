<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Auth;

/**
 * CHANNEL AUTH CONTRACT
 *
 * Dispatch-owned authorization interface for external couriers.
 *
 * Auth gets a Shipper through provider security. It does not ship packages.
 */
interface ChannelAuthContract
{
    public function channelKey(): string;

    /** Safe connection state only. Never return credentials. */
    public function status(): array;

    /** Build the provider OAuth authorization URL. */
    public function authorizationUrl(string $state): string;

    /** Exchange a provider callback code and persist encrypted credentials. */
    public function handleCallback(string $code): array;

    /** Perform a real authenticated provider check. */
    public function testConnection(): array;

    /** Remove locally stored authorization. */
    public function disconnect(): void;
}
