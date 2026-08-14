import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { photoThumbUrl } from "@helpers/imageThumb";
import { toFastPlayerPath } from "@helpers/playerUrls";
import { applySourceToParams, withSourceParam } from "@helpers/sourceParam";
import "@pages/PlaylistThumbsPage/playlist-thumbs.css";
import "./playlist-color-search.css";

const API_URL = "/api/v2/playlist-color-search.php";
const FAMILY_CHOICES = ["Reds", "Oranges", "Yellows", "Greens", "Blues", "Purples", "Whites", "Grays", "Blacks", "Browns"];

export default function PlaylistColorSearchPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const initialFamily = searchParams.get("family") || searchParams.get("q") || "";
  const sourceParam = searchParams.get("src") ?? "";
  const [activeFamily, setActiveFamily] = useState(initialFamily);
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState({ count: 0, limit: 80 });
  const [loading, setLoading] = useState(Boolean(initialFamily));
  const [error, setError] = useState("");

  useEffect(() => {
    const family = searchParams.get("family") || searchParams.get("q") || "";
    setActiveFamily(family);
  }, [searchParams]);

  useEffect(() => {
    if (!activeFamily.trim()) {
      setItems([]);
      setMeta({ count: 0, limit: 80 });
      setLoading(false);
      setError("");
      return;
    }

    const controller = new AbortController();
    const params = new URLSearchParams({
      family: activeFamily.trim(),
      limit: "120",
      _: String(Date.now()),
    });
    applySourceToParams(params, sourceParam);
    setLoading(true);
    setError("");
    fetch(`${API_URL}?${params.toString()}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
      signal: controller.signal,
    })
      .then(async (response) => {
        const text = await response.text();
        let payload = null;
        try {
          payload = text ? JSON.parse(text) : null;
        } catch {
          throw new Error("Search returned a server page instead of data.");
        }
        if (!response.ok || !payload?.ok) {
          throw new Error(payload?.error || `Search failed (${response.status})`);
        }
        setItems(Array.isArray(payload.items) ? payload.items : []);
        setMeta({
          count: Number(payload.meta?.count || 0),
          limit: Number(payload.meta?.limit || 120),
        });
      })
      .catch((err) => {
        if (err?.name === "AbortError") return;
        setItems([]);
        setMeta((prev) => ({ ...prev, count: 0 }));
        setError(err?.message || "Search failed");
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [activeFamily, sourceParam]);

  const resultTitle = useMemo(() => {
    const family = activeFamily.trim();
    if (!family) return "Search by Color Family";
    return `${formatTitle(family)} Playlist Palettes`;
  }, [activeFamily]);

  const handleChoice = (family) => {
    const params = new URLSearchParams(searchParams);
    params.set("family", family);
    params.delete("q");
    applySourceToParams(params, sourceParam);
    setSearchParams(params);
  };

  const handleExit = () => {
    const safeReturn = resolveReturnTo(searchParams.get("return_to") || "");
    if (safeReturn) {
      navigate(withSourceParam(safeReturn, sourceParam));
      return;
    }
    navigate(withSourceParam("/picker", sourceParam));
  };

  return (
    <div className="playlist-thumbs playlist-thumbs--end playlist-color-search">
      <button
        type="button"
        className="playlist-thumbs__exit"
        onClick={handleExit}
        aria-label="Exit color family search"
      >
        x
      </button>

      <div className="playlist-thumbs__panel">
        <header className="playlist-thumbs__header playlist-color-search__header">
          <div className="playlist-thumbs__header-row">
            <div>
              <h1>{resultTitle}</h1>
              <p>{activeFamily ? `${meta.count} palette${meta.count === 1 ? "" : "s"} found` : "Find playlist palettes by color family"}</p>
            </div>
          </div>

          <div className="playlist-color-search__families" aria-label="Color family shortcuts">
            {FAMILY_CHOICES.map((family) => (
              <button
                key={family}
                type="button"
                className={family.toLowerCase() === activeFamily.trim().toLowerCase() ? "active" : ""}
                onClick={() => handleChoice(family)}
              >
                {family}
              </button>
            ))}
          </div>
        </header>

        {loading && <div className="playlist-thumbs__status">Loading palettes...</div>}
        {error && <div className="playlist-thumbs__status error">{error}</div>}
        {!loading && !error && !activeFamily && (
          <div className="playlist-thumbs__status">Choose a color family to browse playlist palettes.</div>
        )}
        {!loading && !error && activeFamily && !items.length && (
          <div className="playlist-thumbs__status">No playlist palettes found for {formatTitle(activeFamily)}.</div>
        )}

        {!loading && !error && items.length > 0 && (
          <div className="playlist-thumbs__grid-wrap">
            <div className="playlist-thumbs__grid">
              {items.map((item) => {
                const imageUrl = photoThumbUrl(item.photo_library_id, 520, 72, item.photo_url) || item.photo_url || "";
                const href = withSourceParam(toFastPlayerPath(item.player_url || ""), sourceParam);
                return (
                  <Link
                    key={`${item.playlist_instance_id}-${item.playlist_item_id}`}
                    className="playlist-thumbs__card"
                    to={href}
                  >
                    <div className="playlist-thumbs__image">
                      {imageUrl ? (
                        <img src={imageUrl} alt={item.palette_title || "Playlist palette"} loading="lazy" decoding="async" />
                      ) : (
                        <div className="playlist-thumbs__placeholder">No Image</div>
                      )}
                    </div>
                    <div className="playlist-thumbs__title-row">
                      <div className="playlist-thumbs__title">{formatTitle(item.palette_title || item.slide_title || "ColorFix Palette")}</div>
                    </div>
                    <div className="playlist-color-search__playlist">{formatTitle(item.playlist_title || "Playlist")}</div>
                  </Link>
                );
              })}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function formatTitle(value) {
  return String(value || "").replace(/\s*--\s*/g, " - ").trim();
}

function resolveReturnTo(value) {
  const trimmed = String(value || "").trim();
  if (!trimmed.startsWith("/") || trimmed.startsWith("//")) return "";
  return trimmed;
}
