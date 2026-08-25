<?php
declare(strict_types=1);

namespace App\PUB\PubCom;

/**
 * PUBCOM CHANNEL
 *
 * Company-wide communication line between a PUB worker
 * and the Manager responsible for that worker.
 *
 * Workers report WHAT happened by sending PubComSignal.
 *
 * The Channel immediately delivers that signal to
 * the Manager.
 *
 * The Manager decides WHAT TO DO and returns a
 * PubComDisposition.
 *
 * Flow:
 *
 *   Worker
 *      ↓
 *   PubComChannel::report()
 *      ↓
 *   Manager::handleSignal()
 *      ↓
 *   PubComDisposition
 *
 * The Channel does not make production decisions.
 *
 * It also retains reported signals so they can be
 * included in final job summaries or diagnostics.
 */
final class PubComChannel
{
    /**
     * Signals reported through this channel during
     * the current operation.
     *
     * @var array<int, PubComSignal>
     */
    private array $signals = [];


    /**
     * Manager dispositions returned for reported
     * signals during the current operation.
     *
     * @var array<int, PubComDisposition>
     */
    private array $dispositions = [];


    public function __construct(
        private PubComManagerContract $manager
    ) {}


    /**
     * Report a production condition to the Manager.
     *
     * The signal is:
     *
     *   1. retained by PubCom
     *   2. delivered immediately to the Manager
     *   3. triaged by the Manager
     *   4. returned as a PubComDisposition
     *
     * The worker may inspect the returned disposition
     * if its own execution needs to respond to the
     * Manager's decision.
     */
    public function report(
        PubComSignal $signal
    ): PubComDisposition {
        $this->signals[] =
            $signal;

        $disposition =
            $this->manager
                ->handleSignal(
                    $signal
                );

        $this->dispositions[] =
            $disposition;

        return $disposition;
    }


    /**
     * Return all signals reported through this channel.
     *
     * @return array<int, PubComSignal>
     */
    public function signals(): array
    {
        return $this->signals;
    }


    /**
     * Return all Manager dispositions produced through
     * this channel.
     *
     * @return array<int, PubComDisposition>
     */
    public function dispositions(): array
    {
        return $this->dispositions;
    }


    /**
     * Standard array representation of all reported
     * signals.
     *
     * Useful when returning PubCom information through
     * an API response or building a final job summary.
     *
     * @return array<int, array<string, mixed>>
     */
    public function signalsAsArray(): array
    {
        return array_map(
            static fn(
                PubComSignal $signal
            ): array =>
                $signal->toArray(),

            $this->signals
        );
    }


    /**
     * Standard array representation of all Manager
     * dispositions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dispositionsAsArray(): array
    {
        return array_map(
            static fn(
                PubComDisposition $disposition
            ): array =>
                $disposition->toArray(),

            $this->dispositions
        );
    }


    /**
     * Clear communication history for this Channel.
     *
     * This does not change production state.
     * It only resets the in-memory PubCom history.
     */
    public function reset(): void
    {
        $this->signals = [];
        $this->dispositions = [];
    }
}