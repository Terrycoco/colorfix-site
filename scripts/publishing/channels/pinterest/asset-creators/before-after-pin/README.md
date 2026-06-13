# Pinterest Before/After Pin

Status: planned.

This asset creator will take one before photo and one after photo, then produce
a Pinterest-ready image.

## Inputs

- `publish_job_id`
- `publish_output_id`
- before photo id/path
- after photo id/path
- title
- optional subtitle/description
- stable ColorFix tracking URL
- template choice

## Output

- PNG or JPG asset path
- version number
- metadata describing chosen photos and template

The generated asset can be recreated many times while keeping the same tracking
URL.
