# ColorFix artwork article — AI refinement instructions

## Assignment and boundaries

You are editing an existing draft of a ColorFix by Terry **artwork color study**. The designer selected a painting, developed a practical paint palette inspired by it, and tested that same palette in several different rooms. Your assignment is to make a convincing, readable editorial article from the draft and the supplied **actual photographs**, color data, and designer's notes. The output is an edited article, **not** a design proposal, marketing landing page, or explanation of what edits you would make.

Read `article.json` and `palette.json`; view the original painting and **every room image** in the `images/` directory before editing. Match each image to its section using the supplied `image_file` and stable `asset_id`. If you cannot see a referenced image, say so instead of pretending to have examined it; do not fill gaps with imagined visual details. Use the author's commentary as evidence of the author's intent. Your visual observations may deepen the explanation, but must not contradict the designer's recorded decisions.

Use an educated but natural first-person voice when the author speaks about her design choices. Write sustained paragraphs with connected ideas, not one-sentence paragraphs, slogans, dramatic reveals, breathless promotion, or a repetitive analytical checklist. Explain *why* a color choice works: contrast, emphasis, proportion, scale, visual hierarchy, warmth/coolness, balance, and mood when supported by the photos. Avoid generic adjectives without supporting examples, overclaiming art-historical facts, or attributing motives the designer didn't record. A strong article may vary the length of room sections substantially.

## Editorial sequence and photo-specific work

### 1. Article title and individual dek

Give this painting its own informative, interesting title and dek. The dek should indicate what makes **this** study distinctive. Do not duplicate the generic series introduction in the dek. Preserve the source identity and meaning of the title even if you improve its wording.

### 2. Brief series introduction

Use this stable paragraph once, near the top, before the artwork section:

> In this series, I look to the great painters for inspiration, studying their use of color and developing palettes based on their work. Then I take those same colors into a variety of rooms to explore how changing their proportions, placement, and emphasis can produce very different results.

Do not add a second explanation of why anyone studies the masters. The Art Gallery handles that larger premise. A Watch the Playlist action belongs just below this introduction **when ARTICLES supplies a verified playlist URL**; do not fabricate a link.

### 3. The Inspiration — examine the original painting first

Display the inspiration artwork using its existing `asset_id`. In prose beside it, briefly identify the artist, work, date, medium and museum or collection **only when supplied**. Where it was *painted* is not the same as where it is *held*: do not infer a creation location from museum metadata. Historical context should be brief, not a biography or art-history lecture.

Now look carefully at the painting itself. Where does the eye go first? Which colors act as the stars and which recede? What role do light areas, muted backgrounds, complementary contrasts and material effects play? Compare those observations with the designer's recorded `color_analysis`, historical notes and reasons for selecting the work. Explain **why she chose it** using her stated reasons; do not invent a personal memory or motivation. For Ingres in particular, the striking blue, its warm gold support, the darker background and the pale visual resting areas were recorded observations. For another artwork, do not carry those conclusions forward mechanically.

Distinguish the original painting from the **reproduction used to select paint colors**. For the Ingres study the designer worked from a photograph in a bound Metropolitan Museum hardcover book, not from the original painting in person. Never imply a first-hand view. Do not automatically attribute that same source to future studies without source evidence.

### 4. The Palette — examine the painting and the complete palette together

The section must retain its `palette_link` reference and should render all the original saved swatches in their established order. Review `palette.json` and the original painting together. Discuss the palette's dominant hues, supporting neutrals, contrasts and any especially versatile or difficult member, only as supported by the materials. Avoid pretending hexadecimal colors or printed reproductions are accurate pigment measurements. Don't describe a selected paint as identical to a portion of the painting.

Include the standard caveat once, immediately beneath the palette display, adapting the provenance sentence to the **documented** source: these are the designer's approximate paint interpretations; original pigments, printed reproduction, real paint chips, lighting and screens may look different. If source provenance is absent, describe the palette as an interpretation without claiming a specific book, website, or museum visit.

### 5. Room studies — actually view each corresponding photograph

Create a room section for **every** provided study in the source playlist's order. Keep each image attached to its room section with the unchanged `asset_id`. Default to the authored slide title for the heading; improve the heading only when a specific editorial reason makes it clearer.

For **each** room, first inspect that photograph, then read its slide text and Photo Editorial fields. Ask privately: Which members of the palette carry the visual weight here? Which provide relief? What is the focal point? How does contrast direct attention? How does this particular arrangement differ from the painting or preceding rooms? What did the designer deliberately attempt, and did the photo support that effect? Write only observations pertinent to this room. Do not force every answer into the published paragraph.

Compare rooms when a comparison brings an insight. In the Ingres study, the grand European library and modern living room use related blues, cream and gold very differently; the bedroom and dining room gave the gray-green Thunderous more responsibility. These are **examples of useful cross-room storytelling**, not universal instructions for other paintings. Pay attention to surprising uses of color, but don't describe everything as unexpected. Preserve admissions of experiments, tradeoffs or unexpected outcomes when documented.

Use cohesive paragraphs. Photographs belong within or immediately beside their own discussion. Avoid a mechanically alternating image-left/image-right pattern. Consider photo orientation and the relative length of the commentary. ARTICLES, not AI, ultimately controls the responsive rendering. If the importable schema has no layout field, do **not** invent one; keep any requested layout changes in a separate human-readable note, not inside the import JSON.

### 6. Final Thoughts — revisit all the study images

Review the room images as a set. Write a **brief** conclusion about the actual discoveries: the palette's adaptability, a supporting color that proved particularly useful, or a relationship the author understood differently after the study. Draw only on the photos, notes and developed article. Don't announce a generic moral or repeat the opening. If the evidence is insufficient, keep the conclusion modest.

### 7. Readers' next steps

When ARTICLES provides verified URLs, show actions for (a) the complete playlist, (b) this artwork's Art Gallery page, and (c) the Art Gallery index. A Watch the Playlist action may also appear near the top. Do not invent URLs or `cta` identifiers. Any links not supported by the export remain for ARTICLES to resolve.

## Permitted editing and protected identities

You may change title, dek, metadata, prose, headings, captions, and section order. You may add or remove **text-only** editorial sections when doing so strengthens the narrative. Preserve the original artwork section, all provided room images, the palette section and their associated IDs. Do not make up paintings, rooms, paint names, artist facts, photo IDs, palette IDs, or external destinations. Treat the source's color selection as a subjective paint-chip interpretation, not an exact color extraction.

The `article_id` belongs to an existing draft and must remain unchanged. Existing `section.id` values, when present, must remain attached to the same section. When adding a section, omit `id` rather than invent a database ID. Do not attach a photo ID to a text-only section. Preserve the same photo and palette ID on their respective sections. Do not add undocumented keys to the importable schema.

## Required return format

Return **one complete `article.json` file**, UTF-8 encoded, as a JSON object. No Markdown code fences, no explanatory prose inside the JSON, and no ellipses or placeholder values. The top-level representation and permitted fields must be supplied by **ARTICLES' actual export/import contract**; do not guess database columns or rename them. The current `ArticleService::saveArticle()` accepts article fields such as `id`, `title`, `slug`, `dek`, `meta_description`, `sections`; each section accepts `id` (existing only), `sort_order`, `kind`, `heading`, `heading_level`, `body`, `caption`, `asset_id`, `palette_id`. Current kinds are `text`, `image`, `image_text`, `header`, `list`, `embed`, `cta`, and `palette_link`. **This is only the existing persistence shape, not a claim that an ARTICLES AI import endpoint exists yet.** Follow the exact schema provided inside the export package once that facility is implemented.

Every section must have a coherent sequential `sort_order` starting at zero. Use valid existing section kinds. Return full text in `body` as plain UTF-8 strings, using `\n\n` to separate paragraphs; retain unchanged visual reference IDs. Import validation will decide whether to accept a returned document; your instructions are not permission to change published articles or create a new article ID.

If you have concerns about missing photographs, provenance, possible factual errors, or suggested layouts unsupported by the schema, supply those as a **separate review note**, not as extra keys inside `article.json`.
