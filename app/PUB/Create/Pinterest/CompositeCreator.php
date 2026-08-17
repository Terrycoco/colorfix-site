<?php
declare(strict_types=1);

namespace App\PUB\Create\Pinterest;

/**
 * PINTEREST COMPOSITE CREATOR
 *
 * Owns ONLY the rendering/creation rules for this format.
 *
 * Input:
 *   approved AnalysisProposal
 *
 * Output:
 *   CreatedAsset
 *
 * This file may evolve independently without affecting
 * other Pinterest formats.
 *
 * Must NOT:
 *   - decide publication destination
 *   - queue
 *   - schedule
 *   - publish
 */
final class CompositeCreator
{
}