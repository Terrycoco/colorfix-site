<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\PUB\Analyze\Pinterest\PaletteAnalyzer;
use App\PUB\Analyze\Pinterest\PlaylistPinterestAnalyzer;
use App\PUB\Analyze\Pinterest\Support\PlaylistPaletteResolver;

use App\Repos\PdoPlaylistRepository;

use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;

use App\Services\ViewerService;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond([
            'ok' => false,
            'error' => 'GET only',
        ], 405);
    }

    $playlistId =
        (int)($_GET['playlist_id'] ?? 0);

    if ($playlistId <= 0) {
        throw new InvalidArgumentException(
            'Valid playlist ID required.'
        );
    }

    /*
     * Shared REX infrastructure
     */
    $rexRepo =
        new PdoRexReservationRepository(
            $pdo
        );

    $relationships =
        new RexReservationRelationships(
            $rexRepo
        );

    $reserver =
        new RexReserver(
            $rexRepo,
            new RexTokenGenerator()
        );

    /*
     * Idea + Palette source resolver
     *
     * Playlist
     * → Public Playlist REX
     * → Viewer children
     * → resolved Viewer
     */
    $playlistPaletteResolver =
        new PlaylistPaletteResolver(
            $rexRepo,
            $relationships,
            $reserver,
            new ViewerService(
                $pdo
            )
        );

    $paletteAnalyzer =
        new PaletteAnalyzer(
            $playlistPaletteResolver
        );

    /*
     * Pinterest playlist coordinator
     */
    $analyzer =
        new PlaylistPinterestAnalyzer(
            new PdoPlaylistRepository(
                $pdo
            ),
            $paletteAnalyzer
        );

    $result =
        $analyzer->analyze(
            $playlistId
        );

    workflow_respond([
        'ok' => true,
        'analysis' => $result,
    ]);

} catch (
    InvalidArgumentException |
    RuntimeException $e
) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);

} catch (Throwable $e) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}