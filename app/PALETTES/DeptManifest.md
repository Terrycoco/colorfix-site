# PALETTES — Department Manifest

**UPDATED:** 2026-11-13 11:01:18 AM PST

**Department:** `PALETTES` · **Location:** `app/PALETTES/` · **Namespace:** `App\PALETTES`

## Mission and ownership

PALETTES owns saved palette identities, membership, member ordering, roles, notes, palette permissions, and palette-viewer workflows. COLORS owns individual color identities and the standard render-ready `App\COLORS\DTO\Swatch` contract. PALETTES does not own color measurements or the lifecycle of the swatch materialized snapshot.

## COLORS collaboration: fixed display palettes

`Services/PaletteService::getSwatches(int $paletteId): array` returns an ordered list of render-ready `Swatch::toArray()` payloads. `Repos/PdoSavedPaletteRepository::getDisplaySwatchRows()` performs **one** read-only JOIN from `saved_palette_members` to COLORS' `mv_swatch_view` with active-color filtering; PALETTES calls `Swatch::fromRow()` on each row in memory. This is an explicit permitted cross-department read boundary. No second swatch-hydration query is required.

Display swatches contain the standard COLORS DTO fields (ID, name, abbreviated brand/full brand, hex/RGB, rounded HCL, category, chip number, stain marker). The display-only contract does not attach palette-member roles, notes or headers; consumers that need those use existing detailed PALETTES interfaces. Missing/inactive MV rows are excluded from display but saved membership itself is not altered. Ordering follows `order_index`, then member ID. The snapshot remains as-of-last-explicit-refresh.

## Existing interfaces and compatibility

`Managers/SavedPaletteManager`, `Repos/PdoSavedPaletteRepository::getMembersForPalette()` and existing editor, public catalog and PV services retain their current response contracts. `PaletteService` is a focused read-only interface; its introduction does not migrate or retire other workflows. PV/REX, authorization/visibility, and palette ownership remain with their existing owners. An ARTWORK endpoint must apply its own public-artwork access checks before requesting its attached palette.

## Boundaries

COLORS controls `DTO/Swatch` and swatch snapshot publication; PALETTES controls membership/order; ARTWORK controls artwork-specific context. Read-only JOINs needed for a coherent fixed palette are allowed. No automatic MV refresh, SQL changes to source catalogs, or cross-domain writes are implied.

## Art-inspired photo PV experiences

`Services/PaletteViewerAccessService::openForArtInspiredPhoto(int $photoId): array` validates the public PHOTOS inspiration pair and ARTWORK metadata, obtains the **artwork photo's existing palette_id**, locks that saved-palette row under the caller/owned transaction, then calls REX `findReusable()`/`reserve()`. It returns `{public_url,reservation_id,token,photo_library_id,palette_id,reused}`. No palette or PV rows are copied/created. MySQL row locking serializes concurrent token creation for the same palette; SQLite fixtures use normal transactions.

Public GET `/api/v2/palette-viewer-photo.php?photo_library_id=...` delegates to `Endpoints/ArtInspiredPhotoPVEndpoint`, resolving before redirecting to the stable REX (302/no-store). It accepts only a public art-inspired room ID, not arbitrary reservation parameters; optional `src` attribution is forwarded. Invalid IDs return 400, unavailable relationships 404, unexpected failures 503. Gallery and color-search photo links use this narrow entry instead of the shared palette-level URL.

`Services/ArtInspiredPhotoService::context()` composes PHOTOS/ARTWORK interfaces. `apply(array $viewer, int $photoId, int $paletteId)` decorates the existing public saved-palette PV payload with the clicked room main image and `meta.art_inspiration={photo_library_id,url,alt_text,artwork_id,study_url}`; every original swatch remains intact. Generic palette inset photos are omitted for this experience to keep the source artwork visually secondary. The shared PV renders its cropped 120×96 artwork link under the room. Other PV contexts are unchanged. Reopening a REX rechecks public visibility and the artwork-palette association; unavailable/changed associations fail rather than showing a different experience.
