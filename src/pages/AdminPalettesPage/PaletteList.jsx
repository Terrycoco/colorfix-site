function cleanText(value) {
  return String(value ?? "").trim();
}

function normalizeHex(value) {
  const raw = cleanText(value).replace(/^#/, "");

  if (/^[0-9a-f]{6}$/i.test(raw)) {
    return `#${raw}`;
  }

  if (/^[0-9a-f]{3}$/i.test(raw)) {
    return `#${raw}`;
  }

  return "";
}

function resolveName(palette) {
  return (
    cleanText(palette?.nickname)
    || cleanText(palette?.display_title)
    || cleanText(palette?.title)
    || `Palette #${palette?.id ?? ""}`
  );
}

function resolveThumbnail(palette) {
  return cleanText(
    palette?.thumbnail_url
    ?? palette?.photo_url
    ?? palette?.image_url
    ?? palette?.main_photo_url
  );
}

function resolveSwatches(palette) {
  const members = Array.isArray(palette?.members)
    ? palette.members
    : Array.isArray(palette?.colors)
      ? palette.colors
      : [];

  return members
    .map((member) => {
      const hex = normalizeHex(
        member?.color_hex6
        ?? member?.hex6
        ?? member?.hex
        ?? member?.color?.hex6
        ?? member?.color?.hex
      );

      return hex || null;
    })
    .filter(Boolean);
}

export default function PaletteList({
  palettes = [],
  selectedId = null,
  onSelect,
  loading = false,
  emptyMessage = "No palettes found.",
}) {
  const sortedPalettes = [...palettes].sort((a, b) =>
    resolveName(a).localeCompare(
      resolveName(b),
      undefined,
      { sensitivity: "base" }
    )
  );

  if (loading) {
    return (
      <div className="admin-palette-list__status">
        Loading palettes…
      </div>
    );
  }

  if (sortedPalettes.length === 0) {
    return (
      <div className="admin-palette-list__status">
        {emptyMessage}
      </div>
    );
  }

  return (
    <div className="admin-palette-list" role="list">
      {sortedPalettes.map((palette) => {
        const id = Number(palette?.id ?? 0);
        const name = resolveName(palette);
        const thumbnail = resolveThumbnail(palette);
        const swatches = resolveSwatches(palette);
        const isSelected = id > 0 && id === Number(selectedId);
        const isPublic = Number(palette?.is_public ?? 0) === 1;

        return (
          <button
            key={id || name}
            type="button"
            className={[
              "admin-palette-list__row",
              isSelected ? "is-selected" : "",
            ].filter(Boolean).join(" ")}
            onClick={() => {
              if (id > 0) {
                onSelect?.(id);
              }
            }}
            aria-pressed={isSelected}
            data-admin-object-id={id}
          >
            <div className="admin-palette-list__thumb">
              {thumbnail ? (
                <img
                  src={thumbnail}
                  alt=""
                  loading="lazy"
                />
              ) : (
                <div
                  className="admin-palette-list__thumb-placeholder"
                  aria-hidden="true"
                />
              )}
            </div>

            <div className="admin-palette-list__content">
              <div className="admin-palette-list__title-row">
                <span className="admin-palette-list__title">
                  {name}
                </span>

                {!isPublic && (
                  <span className="admin-palette-list__private">
                    Private
                  </span>
                )}
              </div>

              <div
                className="admin-palette-list__swatches"
                aria-label={`${name} colors`}
              >
                {swatches.length > 0 ? (
                  swatches.map((hex, index) => (
                    <span
                      key={`${id}-${hex}-${index}`}
                      className="admin-palette-list__swatch"
                      style={{ backgroundColor: hex }}
                    />
                  ))
                ) : (
                  <span className="admin-palette-list__no-swatches">
                    No colors yet
                  </span>
                )}
              </div>
            </div>
          </button>
        );
      })}
    </div>
  );
}
