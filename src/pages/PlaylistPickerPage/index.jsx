import { useEffect, useMemo, useState } from "react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { useAppState } from "@context/AppStateContext";
import { photoThumbUrl } from "@helpers/imageThumb";
import { toFastPlayerPath } from "@helpers/playerUrls";
import { applySourceToParams, withSourceParam } from "@helpers/sourceParam";
import "@pages/PlaylistThumbsPage/playlist-thumbs.css";
import "./playlist-picker.css";

const SET_URL = "/api/v2/playlist-instance-sets/get.php";

export default function PlaylistPickerPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { adminExitPath, clearAdminExitPath } = useAppState();
  const setId = Number(searchParams.get("psi") || 0);
  const ctaAudience = searchParams.get("aud") ?? "";
  const addCtaGroup = searchParams.get("add_cta_group") ?? "";
  const demoParam = searchParams.get("demo") ?? "";
  const includePrivateParam = searchParams.get("include_private") ?? "";
  const closeParam = searchParams.get("close") ?? "";
  const setVersionParam = searchParams.get("set_v") ?? searchParams.get("v") ?? "";
  const sourceParam = searchParams.get("src") ?? "";

  const [title, setTitle] = useState("");
  const [subtitle, setSubtitle] = useState("");
  const [items, setItems] = useState([]);
  const [currentSetVersion, setCurrentSetVersion] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!setId) {
      setError("Missing playlist set id");
      setLoading(false);
      return;
    }
    setLoading(true);
    setError("");
    const params = new URLSearchParams({
      id: String(setId),
    });
    if (setVersionParam) {
      params.set("v", setVersionParam);
    } else {
      params.set("_", String(Date.now()));
    }
    if (ctaAudience) params.set("aud", ctaAudience);
    if (adminExitPath || includePrivateParam === "1") params.set("include_private", "1");
    applySourceToParams(params, sourceParam);
    fetchPlaylistSet(`${SET_URL}?${params.toString()}`)
      .then((payload) => {
        if (!payload?.ok || !payload?.set) {
          throw new Error(payload?.error || "Failed to load playlist set");
        }
        setTitle(formatTitle(payload.set.title || "Choose a playlist"));
        setSubtitle(formatTitle(payload.set.subtitle || ""));
        setItems(Array.isArray(payload.set.items) ? payload.set.items : []);
        setCurrentSetVersion(payload.set.version ? String(payload.set.version) : "");
      })
      .catch((err) => {
        setError(err?.message || "Failed to load playlist set");
      })
      .finally(() => setLoading(false));
  }, [setId, ctaAudience, adminExitPath, includePrivateParam, setVersionParam, sourceParam]);



  const tiles = useMemo(() => {
    return (items || []).map((item) => ({
      id: item.id,
      playlist_instance_id: item.playlist_instance_id,
      player_url: item.player_url || "",
      rex_url: item.rex_url || "",
      item_type: item.item_type || "instance",
      target_set_id: item.target_set_id,
      target_set_version: item.target_set_version || "",
      title: formatTitle(item.title || ""),
      subtitle: formatTitle(item.subtitle || ""),
      photo_url: item.photo_url || "",
      photo_library_id: item.photo_library_id || null,
    }));
  }, [items]);

  const buildPlaylistUrl = (tile) => {
    const playlistPath = tile?.rex_url || tile?.player_url || `/playlist/${tile?.playlist_instance_id || ""}`;
    const params = new URLSearchParams();
    const effectiveSetVersion = setVersionParam || currentSetVersion;
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    if (ctaAudience !== "") params.set("aud", ctaAudience);
    if (demoParam !== "") params.set("demo", demoParam);
    if (includePrivateParam === "1") params.set("include_private", "1");
    if (effectiveSetVersion !== "") params.set("set_v", effectiveSetVersion);
    applySourceToParams(params, sourceParam);
    if (closeParam === "1") params.set("close", "1");
    if (setId) params.set("psi", String(setId));
    const returnTo = withSourceParam(buildReturnTo(location, searchParams), sourceParam);
    if (returnTo) params.set("return_to", returnTo);
    const qs = params.toString();
    const targetUrl = `${playlistPath}${qs ? `?${qs}` : ""}`;

    return withSourceParam(
      tile?.rex_url ? targetUrl : toFastPlayerPath(targetUrl),
      sourceParam
    );
  };

  const buildSetUrl = (tile) => {
    const targetSetId = tile?.target_set_id || "";
    if (!targetSetId) return "#";
    const params = new URLSearchParams();
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    if (ctaAudience !== "") params.set("aud", ctaAudience);
    if (demoParam !== "") params.set("demo", demoParam);
    if (includePrivateParam === "1") params.set("include_private", "1");
    applySourceToParams(params, sourceParam);
    if (tile?.target_set_version) params.set("set_v", tile.target_set_version);
    const returnTo = withSourceParam(buildReturnTo(location, searchParams), sourceParam);
    if (returnTo) params.set("return_to", returnTo);
    params.set("psi", String(targetSetId));
    const qs = params.toString();
    return withSourceParam(`/picker${qs ? `?${qs}` : ""}`, sourceParam);
  };

  const buildColorSearchUrl = () => {
    const params = new URLSearchParams();
    if (ctaAudience !== "") params.set("aud", ctaAudience);
    if (demoParam !== "") params.set("demo", demoParam);
    if (includePrivateParam === "1") params.set("include_private", "1");
    applySourceToParams(params, sourceParam);
    if (setId) params.set("psi", String(setId));
    const returnTo = withSourceParam(buildReturnTo(location, searchParams), sourceParam);
    if (returnTo) params.set("return_to", returnTo);
    const qs = params.toString();
    return withSourceParam(`/playlist-color-search${qs ? `?${qs}` : ""}`, sourceParam);
  };

  const handleBackToPrevious = () => {
    const safeReturn = resolveReturnTo(searchParams.get("return_to") ?? "");
    if (safeReturn) {
      navigate(withSourceParam(safeReturn, sourceParam));
      return;
    }
    if (window.history.length > 1) {
      navigate(-1);
      return;
    }
    navigate(withSourceParam("/", sourceParam));
  };

  const handleExit = () => {
    if (adminExitPath) {
      const target = adminExitPath;
      clearAdminExitPath();
      window.location.href = withSourceParam(target, sourceParam);
      return;
    }
    navigate(withSourceParam("/", sourceParam));
  };

  if (loading) return <div className="playlist-thumbs__status">Loading…</div>;
  if (error) return <div className="playlist-thumbs__status error">{error}</div>;
  const safeReturn = resolveReturnTo(searchParams.get("return_to") ?? "");
  const showBackButton = Boolean(safeReturn);
  const backLabel = safeReturn.startsWith("/picker") ? "Back to previous set" : "Back to playlist";

  return (
    <div className="playlist-thumbs playlist-thumbs--end playlist-picker">
      <button
        type="button"
        className="playlist-thumbs__exit"
        onClick={handleExit}
        aria-label="Exit picker"
      >
        ×
      </button>
      <div className="playlist-thumbs__panel">
        <header className="playlist-thumbs__header">
          <div className="playlist-thumbs__header-row">
            <div>
              <h1>{title}</h1>
              {subtitle && <p>{subtitle}</p>}
            </div>
            <Link className="playlist-picker__search-link" to={buildColorSearchUrl()}>
              Search by Color Family
            </Link>
          </div>
        </header>
        {!tiles.length && (
          <div className="playlist-thumbs__status">No models found in this set.</div>
        )}
        <div className="playlist-thumbs__grid-wrap">
          <div className="playlist-thumbs__grid">
            {tiles.map((tile) => (
              <Link
                key={tile.id ?? `${tile.item_type}-${tile.playlist_instance_id || tile.target_set_id}`}
                className="playlist-thumbs__card"
                to={
                  tile.item_type === "set"
                    ? buildSetUrl(tile)
                    : buildPlaylistUrl(tile)
                }
              >
                <div className="playlist-thumbs__image">
                  {(photoThumbUrl(tile.photo_library_id, 520, 72, tile.photo_url) || tile.photo_url) ? (
                    <img
                      src={photoThumbUrl(tile.photo_library_id, 520, 72, tile.photo_url) || tile.photo_url}
                      alt={tile.title}
                      loading="lazy"
                      decoding="async"
                    />
                  ) : (
                    <div className="playlist-thumbs__placeholder">No Image</div>
                  )}
                </div>
                <div className="playlist-thumbs__title">{tile.title}</div>
                {tile.subtitle && (
                  <div className="playlist-picker__card-subtitle">{tile.subtitle}</div>
                )}
              </Link>
            ))}
          </div>
        </div>
        {showBackButton && (
          <div className="playlist-thumbs__footer">
            <button
              type="button"
              className="playlist-thumbs__back"
              onClick={handleBackToPrevious}
            >
              {backLabel}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

function formatTitle(value) {
  return String(value || "").replace(/\s*--\s*/g, " — ").trim();
}

function buildReturnTo(location, searchParams) {
  const params = new URLSearchParams(searchParams);
  params.delete("return_to");
  const qs = params.toString();
  return `${location.pathname}${qs ? `?${qs}` : ""}`;
}

function resolveReturnTo(value) {
  if (!value) return "";
  const trimmed = String(value).trim();
  if (!trimmed.startsWith("/")) return "";
  if (trimmed.startsWith("//")) return "";
  return trimmed;
}

async function fetchPlaylistSet(url) {
  try {
    return await fetchJsonWithRetry(url);
  } catch (err) {
    if (!isRetryableLoadError(err)) throw err;
    return fetchJsonWithRetry(url, { cache: "reload" });
  }
}

async function fetchJsonWithRetry(url, overrides = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 10000);
  try {
    const response = await fetch(url, {
      credentials: "include",
      headers: { Accept: "application/json" },
      ...overrides,
      signal: controller.signal,
    });
    const text = await response.text();
    let payload = null;
    try {
      payload = text ? JSON.parse(text) : null;
    } catch {
      throw new Error("Playlist set returned a server page instead of data. Please try again.");
    }
    if (!response.ok) {
      throw new Error(payload?.error || `Playlist set request failed (${response.status})`);
    }
    return payload;
  } catch (err) {
    if (err?.name === "AbortError") {
      throw new Error("Playlist set request timed out.");
    }
    throw err;
  } finally {
    clearTimeout(timer);
  }
}

function isRetryableLoadError(err) {
  const message = String(err?.message || "").toLowerCase();
  return message.includes("server page")
    || message.includes("timed out")
    || message.includes("failed (5")
    || message.includes("network");
}
