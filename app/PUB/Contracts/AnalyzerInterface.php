<?php
declare(strict_types=1);

namespace App\PUB\Contracts;

/**
 * ANALYZE STAGE CONTRACT
 *
 * Examines a Destination Object and determines which publishable
 * assets could be produced for one channel/format.
 *
 * Input:
 *   Destination Object data
 *
 * Output:
 *   AnalysisProposal objects
 *
 * Must NOT:
 *   - create files
 *   - create REX reservations
 *   - package assets
 *   - queue anything
 *   - publish anything
 */
interface AnalyzerInterface
{
}