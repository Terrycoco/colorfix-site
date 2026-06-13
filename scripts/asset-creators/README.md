# Asset Creators

Asset creators build new files from existing ColorFix content and library assets.

They do not publish to external channels. Publishing is a separate domain.

## Contract

Each creator unit exports:

- `creatorKey`
- `label`
- `description`
- `output`
- `propose(context)`
- `run(context)`

The intended workflow is:

1. `propose`
   - Inspect source content.
   - Return proposed inputs/pairs.
   - Do not create files.

2. User edits/approves the recipe in admin UI.

3. `run`
   - Follow the approved recipe.
   - Generate files.
   - Register output files in `asset_library`.
   - Attach output assets to the creator job.

## Commands

List creators:

```bash
node scripts/asset-creators/run.mjs --list
```

Run a proposal:

```bash
node scripts/asset-creators/run.mjs \
  --creator=pinterest.before_after_composite \
  --phase=propose \
  --context=exports/creator-context-example.json
```

## Current Units

- `pinterest.before_after_composite`
  - Starter contract in place.
  - Pair detection and image generation are next.
