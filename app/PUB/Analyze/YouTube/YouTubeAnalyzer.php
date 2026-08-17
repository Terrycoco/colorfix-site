<?php
declare(strict_types=1);

namespace App\PUB\Analyze\YouTube;

/**
 * YOUTUBE ANALYZE ORCHESTRATOR
 *
 * Coordinates all registered YouTube format analyzers.
 *
 * It does NOT contain format-specific analysis rules.
 *
 * Current format analyzers:
 *   - PlaylistVideoAnalyzer
 *
 * Additional YouTube formats can be added later
 * without changing the surrounding PUB pipeline.
 */
final class YouTubeAnalyzer
{
}