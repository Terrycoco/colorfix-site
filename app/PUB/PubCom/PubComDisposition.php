<?php
declare(strict_types=1);

namespace App\PUB\PubCom;

/**
 * PUBCOM DISPOSITION
 *
 * Standard company-wide Manager decision in response
 * to a PubComSignal.
 *
 * The worker reports WHAT happened.
 * The Manager decides WHAT TO DO about it.
 *
 * PubComDisposition carries that Manager decision
 * farther up the chain.
 */
final class PubComDisposition
{
    public const CONTINUE =
        'continue';

    public const SKIP_UNIT =
        'skip_unit';

    public const STOP_LINE =
        'stop_line';

    public const STOP_JOB =
        'stop_job';


    public const DISPLAY_NONE =
        'none';

    public const DISPLAY_TOAST =
        'toast';

    public const DISPLAY_POPUP =
        'popup';


    private function __construct(
        private PubComSignal $signal,
        private string $action,
        private string $display,
        private bool $retainForSummary,
    ) {}


    public static function make(
        PubComSignal $signal,
        string $action,
        string $display = self::DISPLAY_NONE,
        bool $retainForSummary = false
    ): self {
        return new self(
            $signal,
            $action,
            $display,
            $retainForSummary
        );
    }


    public function signal(): PubComSignal
    {
        return $this->signal;
    }


    public function action(): string
    {
        return $this->action;
    }


    public function display(): string
    {
        return $this->display;
    }


    public function retainForSummary(): bool
    {
        return $this->retainForSummary;
    }


    public function shouldContinue(): bool
    {
        return
            $this->action ===
            self::CONTINUE;
    }


    public function shouldSkipUnit(): bool
    {
        return
            $this->action ===
            self::SKIP_UNIT;
    }


    public function shouldStopLine(): bool
    {
        return
            $this->action ===
            self::STOP_LINE;
    }


    public function shouldStopJob(): bool
    {
        return
            $this->action ===
            self::STOP_JOB;
    }


    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'signal' =>
                $this->signal->toArray(),

            'action' =>
                $this->action,

            'display' =>
                $this->display,

            'retain_for_summary' =>
                $this->retainForSummary,
        ];
    }
}