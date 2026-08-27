<?php
declare(strict_types=1);

namespace App\PUB\PubCom;

/**
 * PUBCOM SIGNAL
 *
 * Standard company-wide message passed through PUB.
 *
 * A PubComSignal reports WHAT happened.
 *
 * It does NOT decide:
 *
 *   - whether production continues
 *   - whether a box is skipped
 *   - whether a job stops
 *   - whether Terry sees a toast
 *   - whether Terry sees a popup
 *
 * Those are Manager decisions.
 *
 * Expected production conditions use PubComSignal.
 * Unexpected system failures should throw an exception
 * and be handled by PUB's error-reporting system.
 */
final class PubComSignal
{
    public const READY =
        'ready';

    public const NOTICE =
        'notice';


    public const PENDING =
        'pending';

    public const INELIGIBLE =
        'ineligible';

    public const UNAVAILABLE =
        'unavailable';


    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private string $type,
        private string $code,
        private string $message,
        private array $context = [],
    ) {}


    /**
     * Worker / Manager is operational
     * and ready to proceed.
     */
    public static function ready(
        string $message = 'Ready.',
        array $context = []
    ): self {
        return new self(
            self::READY,
            'ready',
            $message,
            $context
        );
    }


    /**
     * Nonfatal information discovered
     * during normal production.
     *
     * Example:
     *
     *   "Optional photo was skipped."
     */
    public static function notice(
        string $code,
        string $message,
        array $context = []
    ): self {
        return new self(
            self::NOTICE,
            $code,
            $message,
            $context
        );
    }


    /**
     * The worker is operational, but the
     * supplied job / box / source cannot be
     * processed by that worker.
     *
     * Example:
     *
     *   "No Pinterest-eligible Before slides."
     */
    public static function ineligible(
        string $code,
        string $message,
        array $context = []
    ): self {
        return new self(
            self::INELIGIBLE,
            $code,
            $message,
            $context
        );
    }


    /**
     * The worker / Manager / workstation
     * itself is not currently able to work.
     *
     * This is different from INELIGIBLE:
     *
     *   INELIGIBLE
     *   = "I'm fine, but I can't process this."
     *
     *   UNAVAILABLE
     *   = "I cannot currently take work."
     */
    public static function unavailable(
        string $code,
        string $message,
        array $context = []
    ): self {
        return new self(
            self::UNAVAILABLE,
            $code,
            $message,
            $context
        );
    }

    /**
 * The worker is operational and the assignment
 * may be valid, but a required dependency does
 * not exist yet.
 *
 * This is not an error and not permanent
 * ineligibility.
 *
 * Example:
 *
 *   "Waiting for YouTube thumbnail."
 *   "Waiting for published YouTube URL."
 *
 * The Manager may leave the unit at its current
 * station and try it again on a later pass.
 */
public static function pending(
    string $code,
    string $message,
    array $context = []
): self {
    return new self(
        self::PENDING,
        $code,
        $message,
        $context
    );
}


    public function type(): string
    {
        return $this->type;
    }


    public function code(): string
    {
        return $this->code;
    }


    public function message(): string
    {
        return $this->message;
    }


    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }


    public function isReady(): bool
    {
        return
            $this->type ===
            self::READY;
    }


    public function isNotice(): bool
    {
        return
            $this->type ===
            self::NOTICE;
    }


    public function isIneligible(): bool
    {
        return
            $this->type ===
            self::INELIGIBLE;
    }


    public function isUnavailable(): bool
    {
        return
            $this->type ===
            self::UNAVAILABLE;
    }


    /**
     * Standard representation for passing
     * the signal farther up PubCom or out
     * through an API response.
     *
     * @return array{
     *   type: string,
     *   code: string,
     *   message: string,
     *   context: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'type' =>
                $this->type,

            'code' =>
                $this->code,

            'message' =>
                $this->message,

            'context' =>
                $this->context,
        ];
    }
}