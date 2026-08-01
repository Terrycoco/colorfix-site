# Player Experience Audit

Read-only audit of the current player experience flow and the decisions that may belong in a future `player_experiences` table.

## A. Current Flow

URL `/playlist/:id-or-slug` or `/p/:id-or-slug` lands in `src/pages/PlayerPage/index.jsx`. The page reads URL params including `aud`, `src`, `thumb`, `demo`, `psi`, `add_cta_group`, and `return_to`, then calls `/api/v2/player-playlist.php`.

The API endpoint resolves either `playlist_instance_id` or `playlist_slug`. Slug lookup requires the playlist instance to be active and share-enabled. Then it calls `App\Services\PlayerExperienceService::buildPlaybackPlanFromInstance()`.

`PlayerExperienceService` loads the playlist instance, then loads the playlist through `PdoPlaylistRepository->getById()` without passing audience or venue. Because the repository default is `site`, item filtering currently defaults to `site` slides in the main player flow.

The repository can filter playlist items by `site`, `yt`, `pin`, or `prospect`, but the main player service does not currently select one from the playlist instance audience or URL audience.

The service hydrates image and palette metadata, resolves CTAs from instance `cta_group_id`, optional URL `add_cta_group`, and `cta_overrides`, calculates `thumbs_enabled`, then returns the full player payload.

React then makes additional player-experience decisions after the API response: CTA visibility, replay behavior, watch-next selection, palette prompt behavior, Palette Viewer navigation, and unavailable-page fallback links.

## B. Decision Matrix

| Current input or condition | Decision made | Current location in code | Current output/behavior | Candidate `player_experiences` field | Should remain code? | Notes or risks |
|---|---|---|---|---|---|---|
| Playlist instance slug | Only active/share-enabled slug resolves | `app/repos/PdoPlaylistInstanceRepository.php:90` | 404 if inactive/unshared | none | yes | Access/security behavior. |
| Playlist instance id | `getById()` loads the row directly | `app/repos/PdoPlaylistInstanceRepository.php:15` | Direct id can be loaded by service | none | yes | Worth reviewing separately from experience config. |
| `playlist_instances.audience` | Returned to player and synced into URL `aud` if missing | `app/services/PlayerExperienceService.php:142`, `src/pages/PlayerPage/index.jsx:78` | Affects CTA/client URLs, not slide SQL filtering | `audience_key` or legacy mapping | mixed | Backend does not use it for slide flag today. |
| URL `aud` | Sent by React to the API | `src/pages/PlayerPage/index.jsx:106` | Backend ignores it for main playlist fetch | `experience_key` or `slide_flag` | no | Main gap found in audit. |
| Playlist item venue default | Uses `site` flag | `app/repos/PdoPlaylistRepository.php:17`, `app/repos/PdoPlaylistRepository.php:708` | SQL filters with `AND site = 1` | `slide_flag` | no | Future experience should select `site`, `yt`, `pin`, or `prospect`. |
| `yt`, `pin`, `prospect` item flags | Supported by repository | `app/repos/PdoPlaylistRepository.php:708` | Only used if caller passes venue | `slide_flag` | no | Infrastructure exists, service is not wired to it. |
| Missing flag column | Fallback selected values are true | `app/repos/PdoPlaylistRepository.php:781` | Old schemas keep showing items | none | yes | Migration compatibility. |
| `cta_group_id` | Loads base CTAs | `app/services/PlayerExperienceService.php:82` | End-screen CTA set | `end_cta_group_id` | no | Current per-instance field. |
| URL `add_cta_group` | Merges extra CTAs | `app/services/PlayerExperienceService.php:86` | Adds CTA group unless duplicate | `extra_cta_group_id`, `allow_url_add_cta_group` | mixed | Useful for campaigns but should be controlled. |
| `cta_overrides._cta_ids` | Explicit CTA IDs replace or extend CTAs | `app/services/PlayerExperienceService.php:76`, `app/services/PlayerExperienceService.php:739` | Instance-specific CTA list | per-instance override | no | Current behavior should remain available during migration. |
| `cta_overrides._cta_exclude_ids` | Removes CTAs | `app/services/PlayerExperienceService.php:760` | CTA omitted | per-instance override | no | Keep as override capability. |
| `cta_context_key` | Saved and returned | `app/services/PlayerExperienceService.php:141` | Not meaningfully used in service | possible legacy replacement by `experience_key` | no | Looks like an older abstraction. |
| `palette_viewer_cta_group_id` | Added as `add_cta_group` when opening Palette Viewer | `src/helpers/ctaActions.js:196` | Palette Viewer gets a different CTA group | `palette_viewer_cta_group_id` | no | Good candidate field. |
| Pinterest publishing destination | Adds `?src=pinterest` | `app/services/PinterestPublishingService.php:425` | Tracking source on generated URL | `default_source_key` maybe | mixed | Publishing source should stay explicit. |
| Admin copy Pinterest URL | Adds `aud=pinterest`, not `src=pinterest` | `src/pages/AdminPlaylistInstancesPage/index.jsx:481` | Share URL has audience | likely legacy only | no | Inconsistent with `src`. |
| URL `src` | Used for tracking | `src/pages/PlayerPage/index.jsx:29`, `src/helpers/userEvents.js:128` | Stored on user events | not experience | yes | Source means acquisition channel, not audience. |
| Multiple palette targets | Enables thumbs flow | `app/services/PlayerExperienceService.php:639` | `thumbs_enabled = true` | `palette_access_mode`, `thumbs_mode` | no | Currently hard-coded auto behavior. |
| One palette target | Shows direct palette CTA | `src/pages/PlayerPage/index.jsx:282` | `to_palette` visible | `palette_access_mode` | no | Could become hidden, concept, or normal. |
| Palette item type is intro/text/before/etc. | Excluded from palette access | `src/helpers/playerPaletteItems.js:1` | No See Colors access | partially configurable | mixed | Structural slide-type rules should mostly stay code. |
| `exclude_from_thumbs` | Excludes palette from thumbs/palette eligibility | `src/helpers/playerPaletteItems.js:9` | Hidden from palette access | existing slide-level field | no | Not experience-wide. |
| Saved palette has hash | Opens `/palette/{hash}/share` | `src/helpers/ctaActions.js:188` | Normal Palette Viewer | `palette_viewer_mode` | no | Concept viewer would need alternate mode. |
| Saved set without hash | Considered eligible, then cannot open | `src/helpers/ctaActions.js:194` | Silently no-op | none | yes | Implementation edge case, not config. |
| Palette Viewer API source | Requires `source=saved` | `api/v2/palette-viewer.php:28` | Applied palettes rejected | none | yes | Structural/data model rule. |
| Palette Viewer payload | Always returns swatches/specs | `app/services/PaletteViewerService.php:99` | No concept/no-spec mode | `palette_viewer_mode` | no | Needed for concept-only viewer. |
| Replay | Skips intro if instance flag true | `src/helpers/ctaActions.js:254` | Replay starts at first non-intro | `skip_intro_on_replay_default` | no | Already per-instance. |
| Share | Blocked by `share_enabled === false` for native share | `src/helpers/ctaActions.js:19` | Share unavailable | `share_enabled_default` | mixed | Copy link does not check this. |
| Hide stars | Hides liked replay CTAs | `src/pages/PlayerPage/index.jsx:274` | Liked replay hidden | `hide_stars_default`, `likes_enabled_default` | no | Star UI is currently also hard-disabled. |
| Star UI | Wrapped in `false &&` | `src/components/Player/index.jsx:690` | Users cannot star in current UI | `likes_enabled_default` | no | Current liked replay is mostly inactive. |
| Watch Next CTA exists | Falls back to set id `3` | `src/pages/PlayerPage/index.jsx:618` | Suggested next playlist set | `watch_next_set_id` | no | Hard-coded `3` should become config. |
| Watch Next audience | Set API chooses exact audience, then any | `api/v2/playlist-instance-sets/get.php:221` | Audience-specific instance chosen | possible `audience_priority` | mixed | Algorithm can stay code. |
| Private audience | Any non-public/any audience includes private set items | `api/v2/playlist-instance-sets/get.php:36` | Private playlists visible | none | yes | Security-sensitive. |
| End set exhausted | Defaults CTA to `Explore ColorFix` and `/` | `api/v2/playlist-instance-sets/get.php:316` | Final watch-next fallback | `watch_next_end_cta_*` | no | Currently set-level config with fallback. |
| Unavailable screen | Links `/picker?psi=11` and `/results/4` | `src/pages/PlayerPage/index.jsx:824` | Hard-coded fallback buttons | global setting, not per-experience | maybe | Not instance-specific. |

## C. Candidate Fields For Future `player_experiences`

- `experience_key`
- `name`
- `is_active`
- `sort_order`
- `slide_flag`
- `end_cta_group_id`
- `palette_viewer_cta_group_id`
- `palette_access_mode`
- `thumbs_mode`
- `palette_prompt_enabled`
- `palette_prompt_label`
- `palette_prompt_delay_ms`
- `share_enabled_default`
- `skip_intro_on_replay_default`
- `likes_enabled_default`
- `hide_stars_default`
- `watch_next_enabled`
- `watch_next_set_id`
- `watch_next_fallback_set_id`
- `default_audience_key`
- `default_source_key`
- `allow_url_add_cta_group`
- `allow_url_audience_override`

Recommended initial values for `slide_flag` would be normal strings, not a MySQL enum:

- `site`
- `yt`
- `pin`
- `prospect`

Recommended initial values for `palette_access_mode`:

- `normal`
- `concept`
- `hidden`

Recommended initial values for `thumbs_mode`:

- `auto`
- `always`
- `never`

I would not carry `cta_context_key` forward as the main new model. It is stored and returned today, but it does not currently drive the central player decisions.

## D. Behavior That Should Remain Application Code

- Slug and id lookup.
- Active/share-enabled access checks.
- Private playlist visibility rules.
- User-event source normalization.
- Bot/internal tracking exclusion.
- URL parsing.
- Safe `return_to` validation.
- CTA action implementations: native share, copy link, navigation, article link, playlist link.
- Photo hydration.
- Image cache-busting.
- Saved palette lookup.
- Saved palette set/photo attachment resolution.
- Removal of applied palette support.
- Image preloading.
- Player transitions.
- Hue-wheel rendering.
- Brand-bumper rendering.
- Watch-next repeat prevention.
- Schema.org gallery JSON generation.
- API cache headers.
- API retry behavior.

## E. Legacy Compatibility Plan

When the future `player_experiences` model is introduced, keep existing playlist instances working with no behavior change by making the experience reference nullable at first.

If `playlist_instances.player_experience_id` or `playlist_instances.player_experience_key` is null, use current behavior:

- Load items with `site`.
- Use instance `cta_group_id`.
- Use instance `palette_viewer_cta_group_id`.
- Use instance `cta_overrides`.
- Use instance `share_enabled`.
- Use instance `demo_enabled`.
- Use instance `skip_intro_on_replay`.
- Use instance `hide_stars`.
- Preserve URL `aud` and `src` as tracking/CTA context only.

For new instances, assign an explicit experience. The experience should choose the slide flag and default behavior, while instance fields can remain overrides during transition.

Existing audience values such as `pinterest`, `homeowner`, `hoa`, `contractor`, and `prospect` should be mapped cautiously because audience does not reliably mean slide filtering today.

## F. Main Finding

The repository already supports `site`, `yt`, `pin`, and `prospect` slide filtering, but the main Player Experience Service always loads the playlist with the default `site` filter.

That is the cleanest first responsibility for a future `player_experiences.slide_flag`.
