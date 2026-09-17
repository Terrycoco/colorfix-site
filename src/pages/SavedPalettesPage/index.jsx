import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./saved-palettes-page.css";

const LIST_URL = `${API_FOLDER}/v2/saved-palettes/list.php`;

const FAMILY_OPTIONS = [
  { value: "", label: "All Colors" },
  { value: "white", label: "Whites" },
  { value: "beige", label: "Beiges" },
  { value: "greige", label: "Greiges" },
  { value: "gray", label: "Grays" },
  { value: "brown", label: "Browns" },
  { value: "black", label: "Blacks" },
  { value: "red", label: "Reds" },
  { value: "orange", label: "Oranges" },
  { value: "yellow", label: "Yellows" },
  { value: "green", label: "Greens" },
  { value: "blue", label: "Blues" },
  { value: "purple", label: "Purples" },
  { value: "pink", label: "Pinks" },
];

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

function resolveTitle(palette) {
  return (
    cleanText(palette?.display_title)
    || cleanText(palette?.nickname)
    || cleanText(palette?.title)
    || "ColorFix Palette"
  );
}

function resolveMembers(palette) {
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

      return hex ? { hex } : null;
    })
    .filter(Boolean);
}

function resolvePhotoUrl(photo) {
  return cleanText(
    photo?.url
    ?? photo?.photo_url
    ?? photo?.image_url
    ?? photo?.rel_path
  );
}

function resolveThumbnail(palette) {
  const direct = cleanText(
    palette?.photo_url
    ?? palette?.image_url
    ?? palette?.thumbnail_url
  );

  if (direct) {
    return direct;
  }

  const photos = Array.isArray(palette?.photos)
    ? palette.photos
    : [];

  const full =
    photos.find(
      (photo) =>
        cleanText(photo?.photo_type).toLowerCase() === "full"
    )
    ?? photos[0]
    ?? null;

  return full ? resolvePhotoUrl(full) : "";
}

function resolveViewerUrl(palette) {
  const direct = cleanText(
    palette?.rex_url
    ?? palette?.palette_viewer_url
    ?? palette?.viewer_url
    ?? palette?.pv_url
  );

  if (direct) {
    return direct;
  }

  const token = cleanText(
    palette?.rex_token
    ?? palette?.viewer_token
  );

  if (token) {
    return `/t/${encodeURIComponent(token)}`;
  }

  const hash = cleanText(
    palette?.palette_hash
    ?? palette?.hash
  );

  if (hash) {
    const params = new URLSearchParams({
      return_to: "/saved-palettes",
    });

    return `/palette/${encodeURIComponent(hash)}/share?${params.toString()}`;
  }

  return "";
}

function normalizePalette(row) {
  return {
    id: Number(row?.id ?? row?.saved_palette_id ?? 0),
    title: resolveTitle(row),
    thumbnail: resolveThumbnail(row),
    members: resolveMembers(row),
    viewerUrl: resolveViewerUrl(row),
  };
}

export default function SavedPalettesPage() {
  const navigate = useNavigate();

  const [family, setFamily] = useState("");
  const [palettes, setPalettes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;

    async function loadPalettes() {
      setLoading(true);
      setError("");

      try {
        const params = new URLSearchParams({
          limit: "200",
          _: String(Date.now()),
        });

        if (family) {
          params.set("color_family", family);
        }

        const response = await fetch(
          `${LIST_URL}?${params.toString()}`
        );

        const data = await response
          .json()
          .catch(() => ({}));

        if (
          !response.ok
          || !data?.ok
        ) {
          throw new Error(
            data?.error
            || `Failed to load saved palettes (${response.status}).`
          );
        }

        if (!active) {
          return;
        }

        const rows = Array.isArray(data.items)
          ? data.items
          : [];

        setPalettes(
          rows
            .map(normalizePalette)
            .filter(
              (palette) =>
                palette.id > 0
                && palette.viewerUrl
            )
        );

      } catch (err) {
        if (!active) {
          return;
        }

        setPalettes([]);
        setError(
          err?.message
          || "Failed to load saved palettes."
        );

      } finally {
        if (active) {
          setLoading(false);
        }
      }
    }

    loadPalettes();

    return () => {
      active = false;
    };
  }, [family]);

  const familyLabel = useMemo(() => {
    return (
      FAMILY_OPTIONS.find(
        (option) =>
          option.value === family
      )?.label
      || "All Colors"
    );
  }, [family]);

  function openPalette(viewerUrl) {
    if (!viewerUrl) {
      return;
    }

    if (
      viewerUrl.startsWith("/")
      && !viewerUrl.startsWith("//")
    ) {
      navigate(viewerUrl);
      return;
    }

    window.location.assign(viewerUrl);
  }

  return (
    <main className="saved-palettes-page">
      <header className="saved-palettes-page__header">
        <h1>Saved Palettes</h1>
      </header>

      <div className="saved-palettes-page__filter">
        <label htmlFor="saved-palette-family">
          Color Family
        </label>

        <div className="saved-palettes-page__select-wrap">
          <select
            id="saved-palette-family"
            value={family}
            onChange={(event) => {
              setFamily(event.target.value);
            }}
            aria-label={`Color family: ${familyLabel}`}
          >
            {FAMILY_OPTIONS.map((option) => (
              <option
                key={option.value || "all"}
                value={option.value}
              >
                {option.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      {loading ? (
        <div
          className="saved-palettes-page__status"
          role="status"
          aria-live="polite"
        >
          Loading palettes…
        </div>
      ) : null}

      {!loading && error ? (
        <div
          className="saved-palettes-page__status saved-palettes-page__status--error"
          role="alert"
        >
          {error}
        </div>
      ) : null}

      {!loading && !error && palettes.length === 0 ? (
        <div className="saved-palettes-page__status">
          No saved palettes in this color family.
        </div>
      ) : null}

      {!loading && !error && palettes.length > 0 ? (
        <div className="saved-palettes-page__list">
          {palettes.map((palette) => (
            <button
              key={palette.id}
              type="button"
              className="saved-palette-row"
              onClick={() => {
                openPalette(
                  palette.viewerUrl
                );
              }}
              aria-label={`Open ${palette.title}`}
            >
              <div className="saved-palette-row__thumb">
                {palette.thumbnail ? (
                  <img
                    src={palette.thumbnail}
                    alt=""
                    loading="lazy"
                  />
                ) : (
                  <div
                    className="saved-palette-row__thumb-placeholder"
                    aria-hidden="true"
                  />
                )}
              </div>

              <div className="saved-palette-row__content">
                <div className="saved-palette-row__title">
                  {palette.title}
                </div>

                <div
                  className="saved-palette-row__swatches"
                  aria-label={`${palette.members.length} palette colors`}
                >
                  {palette.members.length > 0 ? (
                    palette.members.map(
                      (member, index) => (
                        <span
                          key={`${member.hex}-${index}`}
                          className="saved-palette-row__swatch"
                          style={{
                            backgroundColor:
                              member.hex,
                          }}
                          aria-hidden="true"
                        />
                      )
                    )
                  ) : (
                    <span className="saved-palette-row__no-swatches">
                      Palette colors
                    </span>
                  )}
                </div>
              </div>

              <span
                className="saved-palette-row__chevron"
                aria-hidden="true"
              >
                ›
              </span>
            </button>
          ))}
        </div>
      ) : null}
    </main>
  );
}
