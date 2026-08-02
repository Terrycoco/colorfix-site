# ColorFix Share Card Templates

These SVG files are source templates for generated Open Graph share-card PNGs.

The share-card generator will load a template by `template_key`, XML-escape the
field values, replace placeholders, render a PNG, and store the generated image
path against the palette viewer content row.

## Current Templates

- `concept.svg` for design concept/reveal shares.
- `palette.svg` for palette-first shares.

## Required Placeholders

Keep these placeholder names intact when redesigning:

- `{{BRAND_LINE}}`
- `{{TITLE}}`
- `{{SUBTITLE}}`
- `{{CTA_LABEL}}`

Optional placeholders currently used by the starter SVGs:

- `{{EYEBROW}}`
- `{{FOOTER_NOTE}}`

## Output

Templates are designed at `1200 x 630`, the common Open Graph preview image
size used by Messages and social previews.
