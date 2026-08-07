# Project / Property Workflow Schema

Migration: `database/migrations/2026_08_05_001_project_property_workflow.sql`
Rollback: `database/rollbacks/2026_08_05_001_project_property_workflow_rollback.sql`

## Before

- `properties.address_id` was required.
- `clients` had no relationship to `addresses`.
- `projects.status` existed and defaulted to `prospect`; there was no dedicated project presentation selector.
- `project_photos` linked projects to `photo_library` with `project_id`, `photo_library_id`, `role`, and `sort_order`.
- `project_playlists` linked projects to playlists without revision/current metadata.
- `saved_palette_photos` stored `rel_path` directly.
- `player_experiences` used the legacy `prospect` experience name.

## After

- `properties.name` is required.
- `properties.address_id` is nullable and still references `addresses(id)`.
- `clients.mailing_address_id` is nullable and references `addresses(id)`.
- `projects.experience_key` is a required application value with default `concept`.
- `projects.slug` is nullable and unique for future stable project URLs.
- `project_photos` remains the project/photo relationship; no `project_id` was added to `photo_library`.
- `project_playlists` supports `is_current`, `is_locked`, `revision_number`, `label`, `locked_at`, and `superseded_at`.
- `saved_palette_photos.photo_library_id` is nullable, indexed, backfilled by exact `rel_path`, and references `photo_library(photo_library_id)`.
- `player_experiences.name` changes from `Prospect` to `Concept` where `experience_key = 'prospect'`.

## Added Indexes And Foreign Keys

- `clients.idx_clients_mailing_address_id (mailing_address_id)`
- `clients.fk_clients_mailing_address -> addresses(id) ON DELETE SET NULL`
- `projects.uq_projects_slug (slug)`
- `projects.idx_projects_experience_key (experience_key)`
- `project_playlists.idx_project_playlists_project_current (project_id, is_current)`
- `saved_palette_photos.idx_saved_palette_photos_photo_library_id (photo_library_id)`
- `saved_palette_photos.fk_saved_palette_photos_photo_library -> photo_library(photo_library_id) ON DELETE SET NULL`

Existing `project_photos` indexes remain:

- `uq_project_photos_project_photo (project_id, photo_library_id)`
- `idx_project_photos_project_sort (project_id, sort_order)`
- `idx_project_photos_photo_library_id (photo_library_id)`

## Experience Naming

The legacy database key remains `prospect` for now. Application meaning:

- `prospect` legacy key = Concept experience
- Final intended labels: Public, Concept, Client

Do not rename playlist item columns or API fields from `prospect` until frontend, backend, PES, analyzer, and creator references are migrated together.

## Unmatched Saved Palette Photo Paths

Run this after the migration to list `saved_palette_photos.rel_path` values that did not match `photo_library.rel_path`:

```sql
SELECT
    spp.id,
    spp.saved_palette_id,
    spp.rel_path
FROM saved_palette_photos spp
LEFT JOIN photo_library pl
  ON pl.photo_library_id = spp.photo_library_id
WHERE spp.photo_library_id IS NULL
ORDER BY spp.id ASC;
```

The migration does not create duplicate photo records. Review unmatched files before adding any missing `photo_library` rows.
