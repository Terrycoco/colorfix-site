<?php
declare(strict_types=1);

namespace App\PUB\Dispatch;

use App\PUB\Dispatch\Auth\ChannelAuthContract;

/**
 * Compatibility name for existing connection endpoints.
 * New provider auth implementations live under Dispatch/Auth.
 */
interface ChannelConnectionContract extends ChannelAuthContract
{
}
