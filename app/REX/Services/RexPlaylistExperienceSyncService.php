<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PLAYLISTS\Repos\PdoPlaylistItemPaletteRepository;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\Repos\PdoRexReservationRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Reconcile every permanent REX experience for one saved Playlist.
 *
 * Model:
 * - Playlist is the current source of truth.
 * - rex_reservations are durable identities and are never deleted here.
 * - rex_relationships are mutable topology and are rebuilt here.
 */
final class RexPlaylistExperienceSyncService
{
    /**
     * Public uses Public/site slides and Public PVs.
     * Concept uses Concept slides and Concept PVs.
     * Client owns Client + Painter Viewer children; Client PVs determine Thumbs.
     *
     * @var array<string, array{
     *     slide_flag:string,
     *     viewer_formats:array<int,string>,
     *     thumbs_format:string
     * }>
     */
    private const EXPERIENCE_PROFILES = [
        'public' => [
            'slide_flag' => 'site',
            'viewer_formats' => ['public'],
            'thumbs_format' => 'public',
        ],
        'concept' => [
            'slide_flag' => 'concept',
            'viewer_formats' => ['concept'],
            'thumbs_format' => 'concept',
        ],
        'client' => [
            'slide_flag' => 'client',
            'viewer_formats' => ['client', 'painter'],
            'thumbs_format' => 'client',
        ],
    ];

    private PdoPlaylistRepository $playlists;
    private PdoPlaylistItemPaletteRepository $playlistItemPalettes;
    private PdoPaletteViewerRepository $paletteViewers;
    private PdoSavedPaletteRepository $savedPalettes;
    private PdoRexReservationRepository $reservations;
    private RexReservationRelationships $relationships;
    private RexReserver $reserver;

    public function __construct(
        private PDO $pdo
    ) {
        $this->playlists = new PdoPlaylistRepository($pdo);
        $this->playlistItemPalettes = new PdoPlaylistItemPaletteRepository($pdo);
        $this->paletteViewers = new PdoPaletteViewerRepository($pdo);
        $this->savedPalettes = new PdoSavedPaletteRepository($pdo);
        $this->reservations = new PdoRexReservationRepository($pdo);
        $this->relationships = new RexReservationRelationships(
            $this->reservations
        );
        $this->reserver = new RexReserver(
            $this->reservations,
            new RexTokenGenerator()
        );
    }



    /**
     * Inspect the current saved Playlist against its permanent REX graph.
     *
     * Read-only. No reservations or relationships are created or changed.
     *
     * @return array<string,mixed>
     */
    public function inspectPlaylist(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new InvalidArgumentException(
                'Valid playlist ID is required.'
            );
        }

        $playlist = $this->playlists->getAdminRowById($playlistId);

        if (!$playlist) {
            throw new RuntimeException(
                "Playlist {$playlistId} was not found."
            );
        }

        $playlistTitle = trim((string)($playlist['title'] ?? ''));

        if ($playlistTitle === '') {
            $playlistTitle = "Playlist #{$playlistId}";
        }

        $items = $this->playlists->getAdminItemRows($playlistId);
        $referencesByItemId = $this->referencesByItemId(
            $this->savedPaletteReferencesForPlaylist(
                $playlistId,
                $items
            )
        );

        $experiences = [];
        $issueCount = 0;
        $warningCount = 0;

        foreach (
            self::EXPERIENCE_PROFILES
            as $experienceKey => $profile
        ) {
            $plan = $this->buildExperiencePlan(
                $playlistId,
                $playlistTitle,
                $experienceKey,
                $profile,
                $items,
                $referencesByItemId,
            );

            $inspection = $this->inspectionExperience($plan);
            $experiences[$experienceKey] = $inspection;
            $issueCount += count($inspection['issues'] ?? []);
            $warningCount += count($inspection['warnings'] ?? []);
        }

        return [
            'playlist_id' => $playlistId,
            'playlist_title' => $playlistTitle,
            'playlist_status' =>
                ((int)($playlist['is_active'] ?? 0) === 1)
                    ? 'active'
                    : 'inactive',
            'ok' => $issueCount === 0,
            'issue_count' => $issueCount,
            'warning_count' => $warningCount,
            'experiences' => $experiences,
        ];
    }

    /**
     * Reconcile Public, Concept, and Client for one saved Playlist.
     *
     * Source validation is completed before relationship writes begin.
     * A bad experience is left untouched; valid sibling experiences may sync.
     *
     * @return array<string,mixed>
     */
    public function syncPlaylist(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new InvalidArgumentException(
                'Valid playlist ID is required.'
            );
        }

        $playlist = $this->playlists->getAdminRowById($playlistId);

        if (!$playlist) {
            throw new RuntimeException(
                "Playlist {$playlistId} was not found."
            );
        }

        $playlistTitle = trim((string)($playlist['title'] ?? ''));

        if ($playlistTitle === '') {
            $playlistTitle = "Playlist #{$playlistId}";
        }

        $items = $this->playlists->getAdminItemRows($playlistId);

        $referencesByItemId = $this->referencesByItemId(
            $this->savedPaletteReferencesForPlaylist(
                $playlistId,
                $items
            )
        );

        $plans = [];

        foreach (
            self::EXPERIENCE_PROFILES
            as $experienceKey => $profile
        ) {
            $plans[$experienceKey] = $this->buildExperiencePlan(
                $playlistId,
                $playlistTitle,
                $experienceKey,
                $profile,
                $items,
                $referencesByItemId,
            );
        }

        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $results = [];

            foreach ($plans as $experienceKey => $plan) {
                if (($plan['blocked'] ?? false) === true) {
                    $results[$experienceKey] =
                        $this->blockedResult($plan);
                    continue;
                }

                $results[$experienceKey] =
                    $this->applyExperiencePlan($plan);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            $issueCount = 0;
            $warningCount = 0;

            foreach ($results as $result) {
                $issueCount += count($result['issues'] ?? []);
                $warningCount += count($result['warnings'] ?? []);
            }

            return [
                'playlist_id' => $playlistId,
                'playlist_title' => $playlistTitle,
                'ok' => $issueCount === 0,
                'issue_count' => $issueCount,
                'warning_count' => $warningCount,
                'experiences' => $results,
            ];
        } catch (Throwable $e) {
            if (
                $ownsTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Derive the complete desired graph for one experience without writing.
     *
     * @param array<string,mixed> $profile
     * @param array<int,array<string,mixed>> $items
     * @param array<int,array<string,mixed>> $referencesByItemId
     * @return array<string,mixed>
     */
    private function buildExperiencePlan(
        int $playlistId,
        string $playlistTitle,
        string $experienceKey,
        array $profile,
        array $items,
        array $referencesByItemId,
    ): array {
        $slideFlag = (string)$profile['slide_flag'];

        $experienceItems = array_values(array_filter(
            $items,
            fn(array $item): bool =>
                $this->truthy($item[$slideFlag] ?? false)
        ));

        /*
         * One logical palette requirement per unique Saved Palette.
         * Repeated slide references keep the earliest source sort order.
         */
        $paletteSources = [];

        foreach ($experienceItems as $item) {
            $playlistItemId = (int)(
                $item['playlist_item_id']
                ?? 0
            );

            if ($playlistItemId <= 0) {
                continue;
            }

            $reference = $referencesByItemId[$playlistItemId] ?? null;

            if (!is_array($reference)) {
                continue;
            }

            $savedPaletteId = (int)(
                $reference['saved_palette_id']
                ?? 0
            );

            if ($savedPaletteId <= 0) {
                continue;
            }

            $sortOrder = max(
                0,
                (int)($item['order_index'] ?? 0)
            );

            if (!isset($paletteSources[$savedPaletteId])) {
                $paletteSources[$savedPaletteId] = [
                    'saved_palette_id' => $savedPaletteId,
                    'sort_order' => $sortOrder,
                    'playlist_item_ids' => [],
                ];
            } else {
                $paletteSources[$savedPaletteId]['sort_order'] = min(
                    (int)$paletteSources[$savedPaletteId]['sort_order'],
                    $sortOrder
                );
            }

            $paletteSources[$savedPaletteId]['playlist_item_ids'][] =
                $playlistItemId;
        }

        uasort(
            $paletteSources,
            static fn(array $a, array $b): int =>
                ((int)$a['sort_order'])
                <=>
                ((int)$b['sort_order'])
        );

        $issues = [];
        $warnings = [];
        $desiredViewers = [];

        foreach (
            $paletteSources
            as $savedPaletteId => $source
        ) {
            $allForPalette =
                $this->paletteViewers->findBySavedPaletteId(
                    (int)$savedPaletteId
                );

            foreach ($profile['viewer_formats'] as $format) {
                $matching = array_values(array_filter(
                    $allForPalette,
                    fn(object $viewer): bool =>
                        (bool)($viewer->isActive ?? false)
                        && $this->normalizeFormat(
                            (string)($viewer->format ?? '')
                        ) === $this->normalizeFormat($format)
                ));

                if ($matching === []) {
                    $issues[] = [
                        'code' => 'missing_pv',
                        'saved_palette_id' => (int)$savedPaletteId,
                        'viewer_format' => $format,
                        'message' =>
                            ucfirst($format)
                            . " PV is missing for Saved Palette "
                            . "#{$savedPaletteId}.",
                    ];
                    continue;
                }

                if (count($matching) > 1) {
                    $issues[] = [
                        'code' => 'multiple_pvs',
                        'saved_palette_id' => (int)$savedPaletteId,
                        'viewer_format' => $format,
                        'pv_ids' => array_map(
                            static fn(object $viewer): int =>
                                (int)($viewer->paletteViewerId ?? 0),
                            $matching
                        ),
                        'message' =>
                            "Multiple active {$format} PVs exist for "
                            . "Saved Palette #{$savedPaletteId}.",
                    ];
                    continue;
                }

                $viewer = $matching[0];
                $pvId = (int)(
                    $viewer->paletteViewerId
                    ?? 0
                );

                if ($pvId <= 0) {
                    $issues[] = [
                        'code' => 'invalid_pv',
                        'saved_palette_id' => (int)$savedPaletteId,
                        'viewer_format' => $format,
                        'message' => 'PV has no valid ID.',
                    ];
                    continue;
                }

                /*
                 * PaletteViewerRepository is the authority for the PV row
                 * and already confirmed that this Viewer is active and uses
                 * the requested format.
                 *
                 * The PALETTES PdoPVRepository now returns arrays and no
                 * longer owns swatches. Validate color presence against the
                 * Saved Palette members instead.
                 */
                $members =
                    $this->savedPalettes
                        ->getMembersForPalette(
                            (int)$savedPaletteId
                        );

                if (count($members) < 1) {
                    $issues[] = [
                        'code' => 'empty_pv',
                        'saved_palette_id' => (int)$savedPaletteId,
                        'viewer_format' => $format,
                        'pv_id' => $pvId,
                        'message' =>
                            "PV #{$pvId} has no colors.",
                    ];
                    continue;
                }

                $title = trim(
                    (string)($viewer->title ?? '')
                );

                if ($title === '') {
                    $title =
                        ucfirst($format)
                        . " PV #{$pvId}";
                }

                $existingViewerRex =
                    $this->findPermanentReservation(
                        resolverKey: 'viewer',
                        resourceType: 'palette_viewer',
                        resourceId: $pvId,
                        experienceKey: $format,
                    );

                if (
                    $existingViewerRex instanceof RexReservation
                    && strtolower(trim(
                        $existingViewerRex->status
                    )) === 'revoked'
                ) {
                    $issues[] = [
                        'code' => 'viewer_rex_revoked',
                        'saved_palette_id' => (int)$savedPaletteId,
                        'viewer_format' => $format,
                        'pv_id' => $pvId,
                        'rex_id' => $existingViewerRex->id,
                        'message' =>
                            "Permanent Viewer REX "
                            . "#{$existingViewerRex->id} is revoked "
                            . 'and will not be replaced automatically.',
                    ];
                    continue;
                }

                $desiredViewers[] = [
                    'saved_palette_id' => (int)$savedPaletteId,
                    'playlist_item_ids' =>
                        $source['playlist_item_ids'],
                    'sort_order' =>
                        (int)$source['sort_order'],'viewer_format' => $format,
                    'pv_id' => $pvId,
                    'title' => $title,
                    'is_primary' =>
                        $this->normalizeFormat($format)
                        ===
                        $this->normalizeFormat(
                            (string)$profile['thumbs_format']
                        ),
                    'existing_rex' => $existingViewerRex,
                ];
            }
        }

        $parentRex = $this->findPermanentReservation(
            resolverKey: 'playlist_experience',
            resourceType: 'playlist',
            resourceId: $playlistId,
            experienceKey: $experienceKey,
        );

        if (
            $parentRex instanceof RexReservation
            && strtolower(trim($parentRex->status)) === 'revoked'
        ) {
            $issues[] = [
                'code' => 'playlist_rex_revoked',
                'rex_id' => $parentRex->id,
                'message' =>
                    "Permanent {$experienceKey} Playlist REX "
                    . "#{$parentRex->id} is revoked and will not "
                    . 'be replaced automatically.',
            ];
        }

        $primaryViewerCount = count(array_filter(
            $desiredViewers,
            static fn(array $viewer): bool =>
                ($viewer['is_primary'] ?? false) === true
        ));

        $thumbsRequired = $primaryViewerCount > 1;

        $thumbsRex = $this->findPermanentReservation(
            resolverKey: 'playlist_thumbs',
            resourceType: 'playlist',
            resourceId: $playlistId,
            experienceKey: $experienceKey,
        );

        if (
            $thumbsRequired
            && $thumbsRex instanceof RexReservation
            && strtolower(trim($thumbsRex->status)) === 'revoked'
        ) {
            $issues[] = [
                'code' => 'thumbs_rex_revoked',
                'rex_id' => $thumbsRex->id,
                'message' =>
                    "Permanent {$experienceKey} Thumbs REX "
                    . "#{$thumbsRex->id} is revoked and will not "
                    . 'be replaced automatically.',
            ];
        }

        $warnings = array_merge(
            $warnings,
            $this->duplicateReservationWarnings(
                'playlist_experience',
                'playlist',
                $playlistId,
                $experienceKey
            )
        );

        if ($thumbsRequired) {
            $warnings = array_merge(
                $warnings,
                $this->duplicateReservationWarnings(
                    'playlist_thumbs',
                    'playlist',
                    $playlistId,
                    $experienceKey
                )
            );
        }

        foreach ($desiredViewers as $viewer) {
            $warnings = array_merge(
                $warnings,
                $this->duplicateReservationWarnings(
                    'viewer',
                    'palette_viewer',
                    (int)$viewer['pv_id'],
                    (string)$viewer['viewer_format']
                )
            );
        }

        /*
         * Zero slides is a valid "empty experience" and normally clears its
         * managed relationships if a permanent parent REX already exists.
         * A revoked permanent identity is never mutated automatically.
         */
        $hasRevokedIdentity = array_reduce(
            $issues,
            static fn(bool $carry, array $issue): bool =>
                $carry
                || in_array(
                    (string)($issue['code'] ?? ''),
                    [
                        'playlist_rex_revoked',
                        'viewer_rex_revoked',
                        'thumbs_rex_revoked',
                    ],
                    true
                ),
            false
        );

        $blocked =
            $hasRevokedIdentity
            || ($experienceItems !== [] && $issues !== []);

        return [
            'playlist_id' => $playlistId,
            'playlist_title' => $playlistTitle,
            'experience_key' => $experienceKey,
            'slide_flag' => $slideFlag,
            'slide_count' => count($experienceItems),
            'palette_count' => count($paletteSources),
            'primary_viewer_count' => $primaryViewerCount,
            'thumbs_required' => $thumbsRequired,
            'desired_viewers' => $desiredViewers,
            'parent_rex' => $parentRex,
            'thumbs_rex' => $thumbsRex,
            'issues' => $issues,
            'warnings' => $warnings,
            'blocked' => $blocked,
        ];
    }



    /**
     * Convert one desired-state plan into a read-only admin inspection model.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function inspectionExperience(array $plan): array
    {
        $parentRex = $plan['parent_rex'] ?? null;
        $viewerLinksByRexId = [];
        $thumbsLinksByRexId = [];

        if ($parentRex instanceof RexReservation) {
            foreach (
                $this->reservations->findChildRelationships($parentRex->id)
                as $relationship
            ) {
                $child = $relationship->reservation;

                if (!($child instanceof RexReservation)) {
                    continue;
                }

                $key = strtolower(trim(
                    (string)$relationship->relationshipKey
                ));

                if ($key === 'viewer') {
                    $viewerLinksByRexId[$child->id] = [
                        'link_id' => $relationship->linkId,
                        'sort_order' => $relationship->sortOrder,
                    ];
                } elseif ($key === 'thumbs') {
                    $thumbsLinksByRexId[$child->id] = [
                        'link_id' => $relationship->linkId,
                        'sort_order' => $relationship->sortOrder,
                    ];
                }
            }
        }

        $children = [];

        foreach ($plan['desired_viewers'] as $viewer) {
            $rex = $viewer['existing_rex'] ?? null;
            $linked =
                $rex instanceof RexReservation
                && isset($viewerLinksByRexId[$rex->id]);

            $status = 'ready';

            if (!($rex instanceof RexReservation)) {
                $status = 'missing_rex';
            } elseif (
                strtolower(trim($rex->status)) === 'revoked'
            ) {
                $status = 'revoked';
            } elseif (!$linked) {
                $status = 'unlinked';
            }

            $children[] = [
                'row_key' =>
                    'viewer:'
                    . (string)$viewer['viewer_format']
                    . ':'
                    . (string)$viewer['pv_id'],
                'type' => 'viewer',
                'title' => (string)$viewer['title'],
                'saved_palette_id' =>
                    (int)$viewer['saved_palette_id'],
                'pv_id' => (int)$viewer['pv_id'],
                'viewer_format' =>
                    (string)$viewer['viewer_format'],
                'sort_order' => (int)$viewer['sort_order'],
                'is_primary' => (bool)$viewer['is_primary'],
                'required' => true,
                'status' => $status,
                'linked' => $linked,
                'link_id' =>
                    $linked
                        ? (int)$viewerLinksByRexId[$rex->id]['link_id']
                        : null,
                'rex' =>
                    $rex instanceof RexReservation
                        ? $this->reservationPayload($rex)
                        : null,
                'message' =>
                    $status === 'ready'
                        ? 'Ready'
                        : ($status === 'missing_rex'
                            ? 'Permanent Viewer REX is missing.'
                            : ($status === 'unlinked'
                                ? 'Viewer REX exists but is not linked to this experience.'
                                : 'Permanent Viewer REX is revoked.')),
            ];
        }

        foreach ($plan['issues'] as $index => $issue) {
            $code = (string)($issue['code'] ?? 'issue');

            if (
                !in_array(
                    $code,
                    [
                        'missing_pv',
                        'multiple_pvs',
                        'invalid_pv',
                        'inactive_pv',
                        'empty_pv',
                        'viewer_rex_revoked',
                    ],
                    true
                )
            ) {
                continue;
            }

            $children[] = [
                'row_key' =>
                    'issue:'
                    . $code
                    . ':'
                    . (string)($issue['saved_palette_id'] ?? 0)
                    . ':'
                    . (string)($issue['viewer_format'] ?? '')
                    . ':'
                    . (string)$index,
                'type' => 'viewer',
                'title' =>
                    isset($issue['saved_palette_id'])
                        ? 'Saved Palette #'
                            . (string)$issue['saved_palette_id']
                        : 'Viewer requirement',
                'saved_palette_id' =>
                    isset($issue['saved_palette_id'])
                        ? (int)$issue['saved_palette_id']
                        : null,
                'pv_id' =>
                    isset($issue['pv_id'])
                        ? (int)$issue['pv_id']
                        : null,
                'viewer_format' =>
                    (string)($issue['viewer_format'] ?? ''),
                'sort_order' => 999999,
                'is_primary' => false,
                'required' => true,
                'status' => $code,
                'linked' => false,
                'link_id' => null,
                'rex' => null,
                'message' => (string)($issue['message'] ?? $code),
            ];
        }

        $thumbsRex = $plan['thumbs_rex'] ?? null;
        $thumbsRequired = (bool)($plan['thumbs_required'] ?? false);

        if ($thumbsRequired || $thumbsRex instanceof RexReservation) {
            $thumbsLinked =
                $thumbsRex instanceof RexReservation
                && isset($thumbsLinksByRexId[$thumbsRex->id]);

            if (!$thumbsRequired) {
                $thumbsStatus = 'retained';
            } elseif (!($thumbsRex instanceof RexReservation)) {
                $thumbsStatus = 'missing_rex';
            } elseif (
                strtolower(trim($thumbsRex->status)) === 'revoked'
            ) {
                $thumbsStatus = 'revoked';
            } elseif (!$thumbsLinked) {
                $thumbsStatus = 'unlinked';
            } else {
                $thumbsStatus = 'ready';
            }

            $children[] = [
                'row_key' => 'thumbs:' . (string)$plan['experience_key'],
                'type' => 'thumbs',
                'title' => 'Colors Used',
                'saved_palette_id' => null,
                'pv_id' => null,
                'viewer_format' => '',
                'sort_order' => 1000000,
                'is_primary' => false,
                'required' => $thumbsRequired,
                'status' => $thumbsStatus,
                'linked' => $thumbsLinked,
                'link_id' =>
                    $thumbsLinked
                        ? (int)$thumbsLinksByRexId[$thumbsRex->id]['link_id']
                        : null,
                'rex' =>
                    $thumbsRex instanceof RexReservation
                        ? $this->reservationPayload($thumbsRex)
                        : null,
                'message' =>
                    $thumbsStatus === 'ready'
                        ? 'Ready'
                        : ($thumbsStatus === 'retained'
                            ? 'Permanent Thumbs REX retained; not currently required.'
                            : ($thumbsStatus === 'missing_rex'
                                ? 'Thumbs is required but its permanent REX is missing.'
                                : ($thumbsStatus === 'unlinked'
                                    ? 'Thumbs REX exists but is not linked to this experience.'
                                    : 'Permanent Thumbs REX is revoked.'))),
            ];
        }

        usort(
            $children,
            static function (array $a, array $b): int {
                $sort = ((int)$a['sort_order']) <=> ((int)$b['sort_order']);

                if ($sort !== 0) {
                    return $sort;
                }

                return strcmp(
                    (string)$a['row_key'],
                    (string)$b['row_key']
                );
            }
        );

        $status = 'ready';
        $slideCount = (int)($plan['slide_count'] ?? 0);

        if ($slideCount === 0 && !($parentRex instanceof RexReservation)) {
            $status = 'not_applicable';
        } elseif (($plan['blocked'] ?? false) === true) {
            $status = 'blocked';
        } elseif (!($parentRex instanceof RexReservation)) {
            $status = 'missing_rex';
        } else {
            foreach ($children as $child) {
                if (
                    !in_array(
                        (string)$child['status'],
                        ['ready', 'retained'],
                        true
                    )
                ) {
                    $status = 'incomplete';
                    break;
                }
            }
        }

        return [
            'experience_key' => (string)$plan['experience_key'],
            'status' => $status,
            'slide_count' => $slideCount,
            'palette_count' => (int)($plan['palette_count'] ?? 0),
            'primary_viewer_count' =>
                (int)($plan['primary_viewer_count'] ?? 0),
            'thumbs_required' => $thumbsRequired,
            'playlist_rex' =>
                $parentRex instanceof RexReservation
                    ? $this->reservationPayload($parentRex)
                    : null,
            'children' => $children,
            'issues' => $plan['issues'],
            'warnings' => $plan['warnings'],
        ];
    }

    /**
     * Apply one validated desired graph.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function applyExperiencePlan(array $plan): array
    {
        $playlistId = (int)$plan['playlist_id'];
        $playlistTitle = (string)$plan['playlist_title'];
        $experienceKey = (string)$plan['experience_key'];
        $slideCount = (int)$plan['slide_count'];

        $parentRex = $plan['parent_rex'] ?? null;

        $createdReservations = [];
        $createdLinks = [];
        $removedLinks = [];

        if (
            $slideCount === 0
            && !($parentRex instanceof RexReservation)
        ) {
            return [
                'experience_key' => $experienceKey,
                'status' => 'not_applicable',
                'slide_count' => 0,
                'palette_count' => 0,
                'viewer_count' => 0,
                'primary_viewer_count' => 0,
                'thumbs_required' => false,
                'playlist_rex' => null,
                'viewer_rexes' => [],
                'thumbs_rex' => null,
                'created_reservations' => [],
                'created_links' => [],
                'removed_links' => [],
                'issues' => $plan['issues'],
                'warnings' => $plan['warnings'],
            ];
        }

        if (!($parentRex instanceof RexReservation)) {
            $parentRex = $this->reserver->reserve(
                new RexCreateReservationRequest(
                    label:
                        $playlistTitle
                        . ' — '
                        . ucfirst($experienceKey),
                    resolverKey: 'playlist_experience',
                    resourceType: 'playlist',
                    resourceId: $playlistId,
                    adminNote:
                        'Auto-created by REX Playlist Experience Sync.',
                    context: [],
                    experienceKey: $experienceKey,
                )
            );

            $createdReservations[] =
                $this->reservationPayload($parentRex);
        }

        /*
         * Relationships are the disposable layer.
         * Only relationship types owned by this sync are rebuilt.
         */
        foreach (
            $this->reservations
                ->findChildRelationships($parentRex->id)
            as $relationship
        ) {
            $relationshipKey = strtolower(trim(
                (string)$relationship->relationshipKey
            ));

            if (
                !in_array(
                    $relationshipKey,
                    ['viewer', 'thumbs'],
                    true
                )
            ) {
                continue;
            }

            $child = $relationship->reservation;if (!($child instanceof RexReservation)) {
                continue;
            }

            $this->relationships->remove(
                $parentRex->id,
                $child->id,
                $relationshipKey,
            );

            $removedLinks[] = [
                'link_id' => $relationship->linkId,
                'parent_rex_id' => $parentRex->id,
                'child_rex_id' => $child->id,
                'relationship_key' => $relationshipKey,
            ];
        }

        $resolvedViewerRexes = [];

        foreach ($plan['desired_viewers'] as $viewerPlan) {
            $viewerRex = $viewerPlan['existing_rex'] ?? null;
            $format = (string)$viewerPlan['viewer_format'];
            $pvId = (int)$viewerPlan['pv_id'];

            if (!($viewerRex instanceof RexReservation)) {
                $viewerRex = $this->reserver->reserve(
                    new RexCreateReservationRequest(
                        label: (string)$viewerPlan['title'],
                        resolverKey: 'viewer',
                        resourceType: 'palette_viewer',
                        resourceId: $pvId,
                        adminNote:
                            'Auto-created by REX Playlist Experience Sync.',
                        context: [
                            /*
                             * ViewerService still consumes presentation format.
                             * REX identity itself uses first-class experience_key.
                             */
                            'format' => $format,
                        ],
                        experienceKey: $format,
                    )
                );

                $createdReservations[] =
                    $this->reservationPayload($viewerRex);
            }

            $sortOrder = max(
                0,
                (int)$viewerPlan['sort_order']
            );

            $link = $this->relationships->create(
                $parentRex->id,
                $viewerRex->id,
                'viewer',
                $sortOrder,
            );

            $createdLinks[] = [
                'link_id' => $link->id,
                'parent_rex_id' => $parentRex->id,
                'child_rex_id' => $viewerRex->id,
                'relationship_key' => 'viewer',
                'sort_order' => $sortOrder,
                'pv_id' => $pvId,
                'viewer_format' => $format,
            ];

            $resolvedViewerRexes[] = [
                'saved_palette_id' =>
                    (int)$viewerPlan['saved_palette_id'],
                'pv_id' => $pvId,
                'viewer_format' => $format,
                'rex_id' => $viewerRex->id,
                'rex_url' => '/t/' . $viewerRex->token,
                'is_primary' => (bool)$viewerPlan['is_primary'],
            ];
        }

        $thumbsRex = $plan['thumbs_rex'] ?? null;

        if (($plan['thumbs_required'] ?? false) === true) {
            if (!($thumbsRex instanceof RexReservation)) {
                $thumbsRex = $this->reserver->reserve(
                    new RexCreateReservationRequest(
                        label:
                            $playlistTitle
                            . ' — '
                            . ucfirst($experienceKey)
                            . ' Thumbs',
                        resolverKey: 'playlist_thumbs',
                        resourceType: 'playlist',
                        resourceId: $playlistId,
                        adminNote:
                            'Auto-created by REX Playlist Experience Sync.',
                        context: [],
                        experienceKey: $experienceKey,
                    )
                );

                $createdReservations[] =
                    $this->reservationPayload($thumbsRex);
            }

            $this->reservations->setFallbackRexId(
                $thumbsRex->id,
                $parentRex->id,
            );

            $link = $this->relationships->create(
                $parentRex->id,
                $thumbsRex->id,
                'thumbs',
                0,
            );

            $createdLinks[] = [
                'link_id' => $link->id,
                'parent_rex_id' => $parentRex->id,
                'child_rex_id' => $thumbsRex->id,
                'relationship_key' => 'thumbs',
                'sort_order' => 0,
            ];
        }

        return [
            'experience_key' => $experienceKey,
            'status' =>
                $slideCount > 0
                    ? 'synced'
                    : 'empty',
            'slide_count' => $slideCount,
            'palette_count' => (int)$plan['palette_count'],
            'viewer_count' => count($resolvedViewerRexes),
            'primary_viewer_count' =>
                (int)$plan['primary_viewer_count'],
            'thumbs_required' =>
                (bool)$plan['thumbs_required'],
            'playlist_rex' =>
                $this->reservationPayload($parentRex),
            'viewer_rexes' => $resolvedViewerRexes,
            'thumbs_rex' =>
                $thumbsRex instanceof RexReservation
                    ? $this->reservationPayload($thumbsRex)
                    : null,
            'created_reservations' => $createdReservations,
            'created_links' => $createdLinks,
            'removed_links' => $removedLinks,
            'issues' => $plan['issues'],
            'warnings' => $plan['warnings'],
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function blockedResult(array $plan): array
    {
        return [
            'experience_key' => (string)$plan['experience_key'],
            'status' => 'blocked',
            'slide_count' => (int)$plan['slide_count'],
            'palette_count' => (int)$plan['palette_count'],
            'viewer_count' => 0,
            'primary_viewer_count' =>
                (int)$plan['primary_viewer_count'],
            'thumbs_required' =>
                (bool)$plan['thumbs_required'],
            'playlist_rex' =>
                $plan['parent_rex'] instanceof RexReservation
                    ? $this->reservationPayload(
                        $plan['parent_rex']
                    )
                    : null,
            'viewer_rexes' => [],
            'thumbs_rex' =>
                $plan['thumbs_rex'] instanceof RexReservation
                    ? $this->reservationPayload(
                        $plan['thumbs_rex']
                    )
                    : null,
            'created_reservations' => [],
            'created_links' => [],
            'removed_links' => [],
            'issues' => $plan['issues'],
            'warnings' => $plan['warnings'],
        ];
    }

    /**
     * Return the durable canonical reservation for object + experience.
     *
     * New rows use first-class experience_key. Compatibility matching keeps
     * already-published legacy Viewer/Thumbs URLs stable during migration.
     */
    private function findPermanentReservation(
        string $resolverKey,
        string $resourceType,
        int $resourceId,
        string $experienceKey,
    ): ?RexReservation {
        $matches = $this->matchingReservations(
            $resolverKey,
            $resourceType,
            $resourceId,
            $experienceKey,
        );

        if ($matches === []) {
            return null;
        }

        $active = array_values(array_filter(
            $matches,
            static fn(RexReservation $reservation): bool =>
                strtolower(trim($reservation->status))
                === 'active'
        ));

        usort(
            $active,
            static fn(RexReservation $a, RexReservation $b): int =>
                $a->id <=> $b->id
        );

        if ($active !== []) {
            return $active[0];
        }

        usort(
            $matches,
            static fn(RexReservation $a, RexReservation $b): int =>
                $a->id <=> $b->id
        );

        return $matches[0] ?? null;
    }

    /**
     * @return RexReservation[]
     */
    private function matchingReservations(
        string $resolverKey,
        string $resourceType,
        int $resourceId,
        string $experienceKey,
    ): array {
        $experienceKey =
            $this->normalizeExperienceKey($experienceKey);

        $matches = [];

        foreach (
            $this->reservations->findByResource(
                $resourceType,
                $resourceId,
                500
            )
            as $reservation
        ) {
            if (!($reservation instanceof RexReservation)) {
                continue;
            }

            if (
                strtolower(trim($reservation->resolverKey))
                !== strtolower(trim($resolverKey))
            ) {
                continue;
            }

            $storedExperience =
                $this->normalizeExperienceKey(
                    (string)($reservation->experienceKey ?? '')
                );

            if ($storedExperience === $experienceKey) {
                $matches[] = $reservation;
                continue;
            }

            /*
             * Legacy Playlist Experience rows kept experience in context.
             */
            if (
                $storedExperience === ''
                && $resolverKey === 'playlist_experience'
                && $this->normalizeExperienceKey(
                    (string)(
                        $reservation->context['experience_key']
                        ?? ''
                    )
                ) === $experienceKey
            ) {
                $matches[] = $reservation;
                continue;
            }

            /*
             * Legacy Viewer rows identify presentation with context.format.
             */
            if (
                $storedExperience === ''
                && $resolverKey === 'viewer'
                && $this->normalizeFormat(
                    (string)(
                        $reservation->context['format']
                        ?? ''
                    )
                ) === $this->normalizeFormat($experienceKey)
            ) {
                $matches[] = $reservation;
                continue;
            }

            /*
             * Existing Thumbs rows predate experience_key and were Public-only.
             * Reuse that permanent Public Thumbs REX instead of minting another.
             */
            if (
                $storedExperience === ''
                && $resolverKey === 'playlist_thumbs'
                && $experienceKey === 'public'
            ) {
                $matches[] = $reservation;
            }
        }

        return $matches;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function duplicateReservationWarnings(
        string $resolverKey,
        string $resourceType,
        int $resourceId,
        string $experienceKey,
    ): array {
        $active = array_values(array_filter(
            $this->matchingReservations(
                $resolverKey,
                $resourceType,
                $resourceId,
                $experienceKey,
            ),
            static fn(RexReservation $reservation): bool =>
                strtolower(trim($reservation->status))
                === 'active'
        ));

        if (count($active) <= 1) {
            return [];
        }

        usort(
            $active,
            static fn(RexReservation $a, RexReservation $b): int =>
                $a->id <=> $b->id
        );

        return [[
            'code' => 'multiple_active_rex',
            'resolver_key' => $resolverKey,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'experience_key' => $experienceKey,
            'rex_ids' => array_map(
                static fn(RexReservation $reservation): int =>
                    $reservation->id,
                $active
            ),
            'message' =>
                'Multiple active REX reservations exist; '
                . 'the oldest active reservation was preserved '
                . 'as canonical.',
        ]];
    }

    /**
     * Resolve Saved Palette references for the Playlist.
     *
     * Current Project workflow stores the slide -> Saved Palette relationship
     * through PdoPlaylistItemPaletteRepository. Legacy public playlists may
     * still use saved_palette_set_id / palette_hash on playlist_items.
     *
     * The current Project relationship wins when both exist.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function savedPaletteReferencesForPlaylist(
        int $playlistId,
        array $items
    ): array {
        $byItemId = $this->referencesByItemId(
            $this->playlists->listSavedPaletteReferencesByPlaylist(
                $playlistId
            )
        );

        foreach ($items as $item) {
            $playlistItemId = (int)(
                $item['playlist_item_id']
                ?? 0
            );

            if ($playlistItemId <= 0) {
                continue;
            }

            $context =
                $this->playlistItemPalettes
                    ->getContext($playlistItemId);

            if (!is_array($context)) {
                continue;
            }

            $savedPaletteId = (int)(
                $context['saved_palette_id']
                ?? 0
            );

            if ($savedPaletteId <= 0) {
                continue;
            }

            $byItemId[$playlistItemId] = [
                'playlist_item_id' => $playlistItemId,
                'playlist_id' => (int)(
                    $context['playlist_id']
                    ?? $playlistId
                ),
                'order_index' => (int)(
                    $item['order_index']
                    ?? 0
                ),
                'saved_palette_set_id' => null,
                'saved_palette_id' => $savedPaletteId,
            ];
        }

        return array_values($byItemId);
    }


    /**
     * @param array<int,array<string,mixed>> $references
     * @return array<int,array<string,mixed>>
     */
    private function referencesByItemId(array $references): array
    {
        $indexed = [];

        foreach ($references as $reference) {
            $playlistItemId = (int)(
                $reference['playlist_item_id']
                ?? 0
            );

            if ($playlistItemId <= 0) {
                continue;
            }

            $indexed[$playlistItemId] = $reference;
        }

        return $indexed;
    }

    /**
     * @return array<string,mixed>
     */
    private function reservationPayload(
        RexReservation $reservation
    ): array {
        return [
            'id' => $reservation->id,
            'token' => $reservation->token,
            'url' => '/t/' . $reservation->token,
            'label' => $reservation->label,
            'resolver_key' => $reservation->resolverKey,
            'resource_type' => $reservation->resourceType,
            'resource_id' => $reservation->resourceId,
            'experience_key' => $reservation->experienceKey,
            'status' => $reservation->status,
            'fallback_rex_id' => $reservation->fallbackRexId,
        ];
    }

    private function truthy(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        $normalized = strtolower(trim((string)$value));

        return in_array(
            $normalized,
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    private function normalizeExperienceKey(
        string $value
    ): string {
        return strtolower(trim($value));
    }

    private function normalizeFormat(
        string $value
    ): string {
        $value = strtolower(trim($value));

        return $value === 'full_palette'
            ? 'public'
            : $value;
    }
}