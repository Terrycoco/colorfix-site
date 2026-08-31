<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\PV\Repos\PdoPVRepository;
use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\DTO\RexCreateReservationRequest;
use InvalidArgumentException;

final class RexPlaylistLinker
{
    public function __construct(
        private RexPlaylistAudit $audit,
        private PdoPVRepository $pvs,
        private RexReservationRepositoryInterface $reservations,
        private RexReservationRelationships $relationships,
        private RexReserver $reserver,
    ) {}

    public function sync(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new InvalidArgumentException('Valid playlist ID is required.');
        }

        $before = $this->audit->audit($playlistId);
        $playlistRex = $before['playlist_rex'] ?? null;

        if (!is_array($playlistRex) || (int)($playlistRex['id'] ?? 0) <= 0) {
            return [
                'playlist_id' => $playlistId,
                'ok_to_sync' => false,
                'message' => 'Playlist has no canonical active Public REX.',
                'created_viewer_rex' => [],
                'created_viewer_links' => [],
                'created_thumbs_rex' => null,
                'created_thumbs_link' => null,
                'updated_thumbs_fallback' => null,
                'existing' => [],
                'skipped' => $before['items'] ?? [],
                'before' => $before,
                'after' => $before,
            ];
        }

        $parentRexId = (int)$playlistRex['id'];

        $createdViewerRex = [];
        $createdViewerLinks = [];
        $existing = [];
        $skipped = [];

        foreach (($before['items'] ?? []) as $item) {
            $status = strtolower(trim((string)($item['status'] ?? '')));
            $pvId = (int)($item['pv_id'] ?? 0);
            $sortOrder = max(0, (int)($item['sort_order'] ?? 0));

            if ($status === 'ready') {
                $existing[] = [
                    'saved_palette_id' => (int)($item['saved_palette_id'] ?? 0),
                    'pv_id' => $pvId,
                    'viewer_rex_id' => (int)($item['viewer_rex_id'] ?? 0),
                    'viewer_link_id' => (int)($item['viewer_link_id'] ?? 0),
                    'status' => 'ready',
                ];
                continue;
            }

            if (!in_array($status, ['missing_viewer_rex', 'missing_viewer_link'], true)) {
                $skipped[] = $this->skipPayload(
                    $item,
                    (string)($item['message'] ?? 'Audit issue requires manual repair.')
                );
                continue;
            }

            if ($pvId <= 0) {
                $skipped[] = $this->skipPayload(
                    $item,
                    'Audit did not provide a valid Palette Viewer ID.'
                );
                continue;
            }

            $viewerRexId = (int)($item['viewer_rex_id'] ?? 0);

            if ($status === 'missing_viewer_rex') {
                $pv = $this->pvs->findById($pvId);

                if ($pv === null || !$pv->isActive) {
                    $skipped[] = $this->skipPayload(
                        $item,
                        'Palette Viewer is missing or inactive.'
                    );
                    continue;
                }

                if (count($pv->swatches) < 1) {
                    $skipped[] = $this->skipPayload(
                        $item,
                        'Palette Viewer has no colors.'
                    );
                    continue;
                }

                $label = trim((string)($pv->meta['title'] ?? ''));
                if ($label === '') {
                    $label = 'Palette Viewer #' . $pvId;
                }

                $format = strtolower(trim((string)($pv->meta['format'] ?? 'public')));
                if ($format === '') {
                    $format = 'public';
                }

                $viewerRex = $this->reserver->reserve(
                    new RexCreateReservationRequest(
                        label: $label,
                        resolverKey: 'viewer',
                        resourceType: 'palette_viewer',
                        resourceId: $pvId,
                        adminNote: 'Auto-created by REX Playlist Linker.',
                        context: ['format' => $format],
                    )
                );

                $viewerRexId = $viewerRex->id;

                $createdViewerRex[] = [
                    'saved_palette_id' => (int)($item['saved_palette_id'] ?? 0),
                    'pv_id' => $pvId,
                    'viewer_rex_id' => $viewerRex->id,
                    'viewer_rex_url' => '/t/' . $viewerRex->token,
                ];
            }

            if ($viewerRexId <= 0) {
                $skipped[] = $this->skipPayload(
                    $item,
                    'Viewer REX could not be resolved.'
                );
                continue;
            }

            try {
                $link = $this->relationships->create(
                    $parentRexId,
                    $viewerRexId,
                    'viewer',
                    $sortOrder,
                );

                $createdViewerLinks[] = [
                    'saved_palette_id' => (int)($item['saved_palette_id'] ?? 0),
                    'pv_id' => $pvId,
                    'parent_rex_id' => $parentRexId,
                    'viewer_rex_id' => $viewerRexId,
                    'viewer_link_id' => $link->id,
                    'sort_order' => $sortOrder,
                ];
            } catch (InvalidArgumentException $e) {
                if (stripos($e->getMessage(), 'already exists') !== false) {
                    $existing[] = [
                        'saved_palette_id' => (int)($item['saved_palette_id'] ?? 0),
                        'pv_id' => $pvId,
                        'viewer_rex_id' => $viewerRexId,
                        'status' => 'already_linked',
                    ];
                    continue;
                }

                $skipped[] = $this->skipPayload($item, $e->getMessage());
            }
        }

        /*
         * Re-audit after Viewer repairs. Thumbs is derived from the current
         * valid PV collection and current REX graph, not from stale input.
         */
        $afterViewerSync = $this->audit->audit($playlistId);

        $createdThumbsRex = null;
        $createdThumbsLink = null;
        $updatedThumbsFallback = null;

        $thumbs = is_array($afterViewerSync['thumbs'] ?? null)
            ? $afterViewerSync['thumbs']
            : [];

        $thumbsRequired = (bool)($thumbs['required'] ?? false);
        $thumbsStatus = strtolower(trim((string)($thumbs['status'] ?? '')));

        if ($thumbsRequired) {
            if ($thumbsStatus === 'missing_thumbs_rex') {
                $parentLabel = trim((string)($playlistRex['label'] ?? ''));
                $thumbsLabel = $parentLabel !== ''
                    ? $parentLabel . ' — Thumbs'
                    : 'Playlist #' . $playlistId . ' — Thumbs';

                $thumbsRex = $this->reserver->reserve(
                    new RexCreateReservationRequest(
                        label: $thumbsLabel,
                        resolverKey: 'playlist_thumbs',
                        resourceType: 'playlist',
                        resourceId: $playlistId,
                        adminNote: 'Auto-created by REX Playlist Linker.',
                        context: [],
                    )
                );

                $this->reservations->setFallbackRexId(
                    $thumbsRex->id,
                    $parentRexId,
                );

                $updatedThumbsFallback = [
                    'thumbs_rex_id' => $thumbsRex->id,
                    'fallback_rex_id' => $parentRexId,
                ];

                $createdThumbsRex = [
                    'thumbs_rex_id' => $thumbsRex->id,
                    'thumbs_rex_url' => '/t/' . $thumbsRex->token,
                    'resolver_key' => 'playlist_thumbs',
                    'resource_type' => 'playlist',
                    'resource_id' => $playlistId,
                    'fallback_rex_id' => $parentRexId,
                ];

                $createdThumbsLink = $this->createThumbsLink(
                    $parentRexId,
                    $thumbsRex->id,
                );
            } elseif ($thumbsStatus === 'missing_thumbs_link') {
                $thumbsRexId = (int)($thumbs['thumbs_rex_id'] ?? 0);

                if ($thumbsRexId > 0) {
                    $this->reservations->setFallbackRexId(
                        $thumbsRexId,
                        $parentRexId,
                    );

                    $updatedThumbsFallback = [
                        'thumbs_rex_id' => $thumbsRexId,
                        'fallback_rex_id' => $parentRexId,
                    ];

                    $createdThumbsLink = $this->createThumbsLink(
                        $parentRexId,
                        $thumbsRexId,
                    );
                }
            } elseif ($thumbsStatus === 'missing_thumbs_fallback') {
                $thumbsRexId = (int)($thumbs['thumbs_rex_id'] ?? 0);

                if ($thumbsRexId > 0) {
                    $this->reservations->setFallbackRexId(
                        $thumbsRexId,
                        $parentRexId,
                    );

                    $updatedThumbsFallback = [
                        'thumbs_rex_id' => $thumbsRexId,
                        'fallback_rex_id' => $parentRexId,
                    ];
                }
            }
        }

        $after = $this->audit->audit($playlistId);

        return [
            'playlist_id' => $playlistId,
            'ok_to_sync' => true,
            'message' => ($after['summary']['is_clean'] ?? false)
                ? 'Playlist REX links are clean.'
                : 'Playlist REX links still have audit issues.',
            'created_viewer_rex_count' => count($createdViewerRex),
            'created_viewer_link_count' => count($createdViewerLinks),
            'created_thumbs_rex_count' => $createdThumbsRex === null ? 0 : 1,
            'created_thumbs_link_count' => $createdThumbsLink === null ? 0 : 1,
            'updated_thumbs_fallback_count' => $updatedThumbsFallback === null ? 0 : 1,
            'existing_count' => count($existing),
            'skipped_count' => count($skipped),
            'created_viewer_rex' => $createdViewerRex,
            'created_viewer_links' => $createdViewerLinks,
            'created_thumbs_rex' => $createdThumbsRex,
            'created_thumbs_link' => $createdThumbsLink,
            'updated_thumbs_fallback' => $updatedThumbsFallback,
            'existing' => $existing,
            'skipped' => $skipped,
            'before' => $before,
            'after_viewer_sync' => $afterViewerSync,
            'after' => $after,
        ];
    }

    private function createThumbsLink(
        int $parentRexId,
        int $thumbsRexId,
    ): ?array {
        try {
            $link = $this->relationships->create(
                $parentRexId,
                $thumbsRexId,
                'thumbs',
                0,
            );

            return [
                'parent_rex_id' => $parentRexId,
                'thumbs_rex_id' => $thumbsRexId,
                'thumbs_link_id' => $link->id,
                'relationship_key' => 'thumbs',
                'sort_order' => 0,
            ];
        } catch (InvalidArgumentException $e) {
            if (stripos($e->getMessage(), 'already exists') !== false) {
                return null;
            }

            throw $e;
        }
    }

    private function skipPayload(array $item, string $reason): array
    {
        return [
            'saved_palette_id' => (int)($item['saved_palette_id'] ?? 0),
            'pv_id' => (int)($item['pv_id'] ?? 0),
            'status' => (string)($item['status'] ?? ''),
            'reason' => $reason,
        ];
    }
}
 