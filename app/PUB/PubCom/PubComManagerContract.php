<?php
declare(strict_types=1);

namespace App\PUB\PubCom;

/**
 * PUBCOM MANAGER CONTRACT
 *
 * Company-wide operating contract for every PUB
 * department Manager.
 *
 * The worker reports WHAT happened through PubComSignal.
 *
 * The Manager decides WHAT TO DO and returns a
 * PubComDisposition.
 *
 * Department-specific management rules remain inside
 * the department Manager.
 */
interface PubComManagerContract
{
    /**
     * Report whether this Manager and department
     * are currently operational.
     */
    public function readiness(): PubComSignal;


    /**
     * Triage a worker's PubCom signal.
     *
     * The Manager owns the resulting production
     * decision.
     */
    public function handleSignal(
        PubComSignal $signal
    ): PubComDisposition;
}