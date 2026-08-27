<?php
declare(strict_types=1);

namespace App\PUB\Dispatch;

/**
 * CHANNEL CONNECTION CONTRACT
 *
 * Common authorization/connection interface for external shipping channels.
 *
 * PUB can talk to Pinterest, YouTube, or another future channel through
 * the same small set of connection operations without knowing each
 * provider's OAuth implementation.
 *
 * Implementations own all provider-specific details:
 *
 *   PinterestConnectionService
 *   YouTubeConnectionService
 *
 * This contract does NOT define publishing/shipping behavior.
 * Shippers remain separate specialists.
 */
interface ChannelConnectionContract
{
    /**
     * Stable PUB routing key for this connection.
     *
     * Examples:
     *   pinterest
     *   youtube
     */
    public function channelKey(): string;


    /**
     * Return safe connection status for the admin UI.
     *
     * Must never expose access tokens, refresh tokens,
     * client secrets, authorization headers, or other credentials.
     */
    public function status(): array;


    /**
     * Build the provider OAuth authorization URL.
     *
     * The caller owns creation/storage/validation of the OAuth state value.
     */
    public function authorizationUrl(
        string $state
    ): string;


    /**
     * Exchange a successful OAuth callback code and persist
     * the resulting connection credentials.
     *
     * OAuth state validation belongs to the public callback/endpoint layer
     * before this method is called.
     */
    public function handleCallback(
        string $code
    ): array;


    /**
     * Perform a real authenticated provider check.
     *
     * This is different from status(), which may only describe
     * the locally stored connection record.
     */
    public function testConnection(): array;


    /**
     * Remove/disable the locally stored authorization for this channel.
     */
    public function disconnect(): void;
}
