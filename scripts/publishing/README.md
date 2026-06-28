# ColorFix Publishing Scripts

This folder is the script side of the publishing system.

The publishing architecture source of truth is
`docs/publishing-arm-architecture.md`.

The PHP app owns publishing records through services and repositories:

- `publishing_jobs`
- `publishing_assets`
- `publications`
- scheduler queue rows
- channel status, destinations, auth metadata
- stable URLs, tracked URLs, live URLs, and timestamps

Scripts may assist with asset creation or channel-specific rendering work. Each
script should be small, testable, and replaceable. Scripts must not own
database persistence; app services and repositories do.

## Structure

```text
scripts/publishing/
  registry.mjs
  list.mjs
  shared/
    assetContract.mjs
  channels/
    pinterest/
      channel.mjs
      asset-creators/
        before-after-pin/
    youtube/
      channel.mjs
      asset-creators/
        video/
        pptx-draft/
```

## Rules

- Only repository classes may access the database.
- Scripts and workers trigger services; they must not contain SQL or lifecycle
  rules.
- Core publishing records stay generic.
- Channel folders own channel-specific specs.
- Each asset creator gets its own folder.
- Each asset creator must be runnable and testable without posting anything.
- Re-rendering an asset should not force a new tracking URL.
- Posting scripts report the external URL and live timestamp back to the app.
- Pinterest asset creation, queue records, manual export, and setup do not
  require `board_id`. Use the known board name, URL, and slug until Pinterest
  API access is approved:
  - `board_name`: `ColorFix Makeovers`
  - `board_url`: `https://www.pinterest.com/terrymarr/colorfix-makeovers/`
  - `board_slug`: `terrymarr/colorfix-makeovers`
  - `board_id`: `null`
- Once Pinterest API access is approved, add a board sync step before API
  publishing: list boards, find `ColorFix Makeovers`, store the returned
  `board_id`, then allow queued pins to publish.
- Core publisher records stay platform-neutral. Shared fields live in normal
  columns on `publishing_channels`, `publishing_jobs`, `publishing_assets`,
  and `publications`; platform-specific API settings live in `metadata_json`.
- Publisher services own publishing workflow.
- Channel adapters own API request shaping and external API calls:
  - `PinterestPublisherAdapter`
  - future `YouTubePublisherAdapter`
- Channel adapters must not access the database or update local records.

## Standard Asset Result

Every asset creator should return:

```js
{
  ok: true,
  channelKey: "pinterest",
  assetType: "before_after_pin",
  assetPath: "exports/publishing/pinterest/...",
  version: 1,
  title: "Pin title",
  trackingUrl: "https://colorfix.terrymarr.com/...",
  metadata: {}
}
```

This keeps Pinterest, YouTube, and future channels aligned even when their
rendering rules are completely different.
