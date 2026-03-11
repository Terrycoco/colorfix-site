Regenerate baked wheel SVGs after hue category changes:

1. `npm run regen-wheel`
2. Commit `public/wheels/wheel-300-labels.svg`
3. Commit `public/wheels/wheel-300-labels-degrees.svg`

If you need to use a local categories JSON file:
`npm run regen-wheel -- --categories=path/to/categories.json`
