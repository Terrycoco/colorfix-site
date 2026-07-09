import { useEffect, useMemo, useState } from "react";
import { extractAssetId, fetchAssetUrl, isAssetRef, parsePhotoRef } from "@helpers/assetImage";
import { useLocation, useNavigate, useParams, useSearchParams } from "react-router-dom";
import "./playlist-thumbs.css";
import { getLastPlaylistInstanceId, recordLastPlaylistInstanceId } from "@helpers/playlistHistory";

export default function PlaylistThumbsPage() {
  const { playlistId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [items, setItems] = useState([]);
  const [title, setTitle] = useState("");
  const [paletteViewerCtaGroupId, setPaletteViewerCtaGroupId] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [likedSet, setLikedSet] = useState(new Set());
  const addCtaGroup = searchParams.get("add_cta_group") ?? "";
  const ctaAudience = searchParams.get("aud") ?? "";
  const startParam = searchParams.get("start") ?? "";
  const psiParam = searchParams.get("psi") ?? "";
  const thumbParam = searchParams.get("thumb") ?? "";
  const demoParam = searchParams.get("demo") ?? "";
  const isHoaView = ctaAudience.toLowerCase() === "hoa";
  const [lastPlaylistInstanceId, setLastPlaylistInstanceId] = useState(() => getLastPlaylistInstanceId());

  useEffect(() => {
    if (!playlistId) {
      setError("Missing playlist instance id");
      setLoading(false);
      return;
    }
    const params = new URLSearchParams();
    params.set("playlist_instance_id", playlistId);
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    params.set("_", String(Date.now()));
    setLoading(true);
    setError("");
    fetch(`/api/v2/player-playlist.php?${params.toString()}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((payload) => {
        if (!payload?.ok || !payload?.data) {
          throw new Error(payload?.error || "Failed to load playlist");
        }
        setTitle(formatTitle(payload.data?.title || payload.data?.display_title || "Playlist Palettes"));
        setItems(payload.data?.items || []);
        setPaletteViewerCtaGroupId(payload.data?.palette_viewer_cta_group_id ? String(payload.data.palette_viewer_cta_group_id) : "");
      })
      .catch((err) => {
        setError(err?.message || "Failed to load playlist");
      })
      .finally(() => setLoading(false));
  }, [playlistId, addCtaGroup]);

  useEffect(() => {
    if (!playlistId) return;
    recordLastPlaylistInstanceId(playlistId);
    setLastPlaylistInstanceId(String(playlistId));
  }, [playlistId]);

  useEffect(() => {
    if (!playlistId || typeof window === "undefined") {
      setLikedSet(new Set());
      return;
    }
    try {
      const key = `playlist-liked:${playlistId}`;
      const raw = window.localStorage.getItem(key);
      const data = raw ? JSON.parse(raw) : [];
      if (Array.isArray(data)) {
        setLikedSet(new Set(data.map((value) => String(value))));
      } else {
        setLikedSet(new Set());
      }
    } catch {
      setLikedSet(new Set());
    }
  }, [playlistId]);

  const palettes = useMemo(() => {
    const seen = new Set();
    const list = [];
    for (const item of items || []) {
      const type = (item?.type || "normal").toLowerCase();
      const attachedType = (item?.saved_palette_photo_type || "").toLowerCase();
      if (type === "intro" || type === "before" || type === "text" || type === "non-palette") continue;
      if (attachedType === "before") continue;
      if (item?.exclude_from_thumbs) continue;
      const apId = item?.ap_id ?? null;
      const paletteHash = item?.palette_hash ?? null;
      const savedPaletteSetId = Number(item?.saved_palette_set_id || 0) || null;
      if (!apId && !paletteHash && !savedPaletteSetId) continue;
      const key = paletteHash
        ? `saved:${paletteHash}:${savedPaletteSetId || "default"}`
        : savedPaletteSetId
          ? `saved-set:${savedPaletteSetId}`
          : `applied:${apId}`;
      if (seen.has(key)) continue;
      seen.add(key);
      const paletteTitle =
        item?.palette_title ||
        item?.saved_palette_title ||
        item?.palette_name ||
        (paletteHash ? "ColorFix Palette" : `Palette ${apId}`);
      list.push({
        ap_id: apId,
        palette_hash: paletteHash,
        saved_palette_set_id: savedPaletteSetId,
        title: formatTitle(paletteTitle),
        image_url: item?.image_url || "",
        is_liked: apId ? likedSet.has(String(apId)) : false,
      });
    }
    return list;
  }, [items, likedSet]);

  const [thumbUrlByKey, setThumbUrlByKey] = useState({});
  const shouldShowBackToPlaylist =
    Boolean(lastPlaylistInstanceId) && Boolean(playlistId) && String(lastPlaylistInstanceId) === String(playlistId);

  useEffect(() => {
    let cancelled = false;
    palettes.forEach((palette) => {
      const key = palette.palette_hash
        ? `saved:${palette.palette_hash}:${palette.saved_palette_set_id || "default"}`
        : `applied:${palette.ap_id}`;
      const value = palette.image_url || "";
      if (!isAssetRef(value)) return;
      const assetId = extractAssetId(value);
      if (!assetId) return;
      fetchAssetUrl(assetId).then((url) => {
        if (!cancelled && url) {
          setThumbUrlByKey((prev) => ({ ...prev, [key]: url }));
        }
      });
    });
    return () => { cancelled = true; };
  }, [palettes]);

  if (loading) return <div className="playlist-thumbs__status">Loading palettes…</div>;
  if (error) return <div className="playlist-thumbs__status error">{error}</div>;

  const handleBackToPlaylist = () => {
    const targetId = lastPlaylistInstanceId || playlistId;
    if (!targetId) return;
    navigate(`/p/${targetId}`);
  };
  const handleExit = () => {
    navigate("/");
  };

  return (
    <div className="playlist-thumbs playlist-thumbs--end">
      <button
        type="button"
        className="playlist-thumbs__exit"
        onClick={handleExit}
        aria-label="Exit playlist"
      >
        ×
      </button>
      <div className="playlist-thumbs__panel">
        <header className="playlist-thumbs__header">
          <div className="playlist-thumbs__header-row">
            <div>
              <h1>{title}</h1>
              <p>Palettes used in this playlist. <strong>Tap photo to view full colors</strong></p>
            </div>
          </div>
        </header>
        {!palettes.length && (
          <div className="playlist-thumbs__status">No palettes found in this playlist.</div>
        )}
        <div className="playlist-thumbs__grid-wrap">
          <div className="playlist-thumbs__grid">
            {palettes.map((palette) => {
              const cardKey = palette.palette_hash
                ? `saved:${palette.palette_hash}:${palette.saved_palette_set_id || "default"}`
                : `applied:${palette.ap_id}`;
              const parsed = parsePhotoRef(palette.image_url);
              const resolvedUrl = parsed.url
                ? parsed.url
                : isAssetRef(palette.image_url)
                ? thumbUrlByKey[cardKey] || ""
                : palette.image_url;
              const params = new URLSearchParams();
              if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
              else if (paletteViewerCtaGroupId !== "") params.set("add_cta_group", paletteViewerCtaGroupId);
              if (ctaAudience !== "") params.set("aud", ctaAudience);
              if (psiParam !== "") params.set("psi", psiParam);
              if (thumbParam !== "") params.set("thumb", thumbParam);
              if (demoParam !== "") params.set("demo", demoParam);
              const returnTo = buildReturnTo(location.pathname, location.search);
              if (returnTo) params.set("return_to", returnTo);
              if (resolvedUrl) {
                params.set("photo_url", resolvedUrl);
              }
              if (palette.saved_palette_set_id) {
                params.set("set_id", String(palette.saved_palette_set_id));
              }
              const qs = params.toString();
              const href = palette.palette_hash
                ? `/palette/${palette.palette_hash}/share${qs ? `?${qs}` : ""}`
                : `/view/${palette.ap_id}${qs ? `?${qs}` : ""}`;
              return (
                <a
                  key={cardKey}
                  className="playlist-thumbs__card"
                  href={href}
                >
                  <div className="playlist-thumbs__image">
                    {resolvedUrl ? (
                      <img src={resolvedUrl} alt={palette.title} loading="lazy" />
                    ) : (
                      <div className="playlist-thumbs__placeholder">No Image</div>
                    )}
                  </div>
                  <div className="playlist-thumbs__title-row">
                    <div className="playlist-thumbs__title">{palette.title}</div>
                    {palette.is_liked && (
                      <span className="playlist-thumbs__liked" aria-label="Starred">
                        <svg
                          className="playlist-thumbs__liked-icon"
                          viewBox="0 0 24 24"
                          aria-hidden="true"
                          focusable="false"
                        >
                          <path d="M12 2.5l2.9 5.88 6.5.95-4.7 4.58 1.1 6.49L12 17.9l-5.8 3.05 1.1-6.49-4.7-4.58 6.5-.95L12 2.5z" />
                        </svg>
                      </span>
                    )}
                  </div>
                </a>
              );
            })}
          </div>
        </div>
        <div className="playlist-thumbs__footer">
          {shouldShowBackToPlaylist && (
            <button
              type="button"
              className="playlist-thumbs__back"
              onClick={handleBackToPlaylist}
            >
              Back to playlist
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

function formatTitle(value) {
  return String(value || "").replace(/\s*--\s*/g, " — ").trim();
}

function buildReturnTo(pathname, search) {
  const params = new URLSearchParams(search || "");
  params.delete("return_to");
  const qs = params.toString();
  return `${pathname}${qs ? `?${qs}` : ""}`;
}
