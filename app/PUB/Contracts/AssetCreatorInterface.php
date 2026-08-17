<?php
declare(strict_types=1);

namespace App\PUB\Contracts;

/**
 * CREATE STAGE CONTRACT
 *
 * Converts one approved AnalysisProposal into an actual asset.
 *
 * Input:
 *   AnalysisProposal
 *
 * Output:
 *   CreatedAsset
 *
 * Format-specific creators implement this independently.
 *
 * Must NOT:
 *   - decide destination URLs
 *   - queue publication
 *   - talk to external publishing APIs
 */
interface AssetCreatorInterface
{
}