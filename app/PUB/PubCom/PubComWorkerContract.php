<?php
declare(strict_types=1);

namespace App\PUB\PubCom;

/**
 * PUBCOM WORKER CONTRACT
 *
 * Company-wide operating contract for every specialist
 * worker operating anywhere inside PUB.
 *
 * A PUB worker owns the specialized knowledge required
 * to perform its own job.
 *
 * Every worker must participate in PubCom so that its
 * Manager can verify readiness and receive useful
 * production information.
 *
 * Every PUB worker must:
 *
 *   1. Report whether the worker / workstation is
 *      currently operational.
 *
 *   2. Preflight the specific assignment before
 *      beginning work.
 *
 *   3. Report expected production conditions through
 *      PubCom rather than silently ignoring them.
 *
 * Unexpected system failures are NOT ordinary PubCom
 * signals. They should throw and be handled by PUB's
 * centralized error system.
 *
 * Workers report WHAT happened.
 *
 * Managers decide WHAT TO DO about it.
 *
 * Worker-specific rules remain inside the worker.
 *
 * Examples:
 *
 *   CompositeAnalyzer
 *     knows what makes a Composite source eligible.
 *
 *   CompositeCreator
 *     knows what its creation workstation requires.
 *
 *   A worker with no special preflight requirements
 *     may simply return PubComSignal::ready().
 */
interface PubComWorkerContract
{
    /**
     * Report whether this worker / workstation is
     * currently available to accept assignments.
     *
     * Typical responses:
     *
     *   PubComSignal::ready(...)
     *
     *   PubComSignal::unavailable(...)
     */
    public function readiness(): PubComSignal;


    /**
     * Inspect the specific assignment before beginning
     * actual production work.
     *
     * The worker applies its OWN specialized eligibility
     * rules here.
     *
     * Typical responses:
     *
     *   PubComSignal::ready(...)
     *
     *   PubComSignal::ineligible(...)
     * 
     *
     * @param array<string, mixed> $input
     */
    public function preflight(
        array $input
    ): PubComSignal;
}