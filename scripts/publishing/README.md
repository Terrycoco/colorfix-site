# ColorFix Publishing Scripts

This folder is the script side of the publishing system.

The PHP app owns publishing records:

- `publish_jobs`
- `publish_outputs`
- channel status
- live URLs and timestamps

Scripts own asset creation and channel-specific work. Each script should be
small, testable, and replaceable.

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

- Core publishing records stay generic.
- Channel folders own channel-specific specs.
- Each asset creator gets its own folder.
- Each asset creator must be runnable and testable without posting anything.
- Re-rendering an asset should not force a new tracking URL.
- Posting scripts report the external URL and live timestamp back to the app.

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
