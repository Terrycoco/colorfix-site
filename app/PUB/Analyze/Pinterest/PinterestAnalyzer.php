<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Pinterest;

/**
 * PINTEREST ANALYZE ORCHESTRATOR
 *
 * Coordinates all registered Pinterest format analyzers.
 *
 * It does NOT contain format-specific analysis rules.
 *
 * Current format analyzers:
 *   - CompositeAnalyzer
 *   - IdeaAnalyzer
 *   - PaletteAnalyzer
 *   - BeforeAfterVideoAnalyzer
 *   - YoutubeTeaserAnalyzer
 *
 * Its job is simply:
 *   Destination Object
 *       -> run applicable format analyzers
 *       -> combine AnalysisProposal results
 */
final class PinterestAnalyzer
{
}