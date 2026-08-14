import { useEffect, useMemo, useState } from "react";
import { extractAssetId, fetchAssetUrl, isAssetRef, parsePhotoRef } from "@helpers/assetImage";
import { useLocation, useNavigate, useParams, useSearchParams } from "react-router-dom";
import "./playlist-thumbs.css";
import { getLastPlaylistInstanceId, recordLastPlaylistInstanceId } from "@helpers/playlistHistory";
import { applySourceToParams, withSourceParam } from "@helpers/sourceParam";

export default function PlaylistThumbsPage() {
  const { playlistId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [items, setItems] = useState([]);
  const [projectColorPlans, setProjectColorPlans] = useState([]);
  const [isProjectExperience, setIsProjectExperience] = useState(false);
  const [viewerRexUrls, setViewerRexUrls] = useState([]);
  const [viewerRexTargets, setViewerRexTargets] = useState([]);
  const [viewerRexUrlByPaletteHash, setViewerRexUrlByPaletteHash] = useState({});
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
  const viewerParam = (searchParams.get("viewer") ?? "").toLowerCase();
  const reservationToken = searchParams.get("reservation_token") ?? searchParams.get("token") ?? "";
  const returnToParam = searchParams.get("return_to") ?? "";
  const originPlaylistParam = searchParams.get("origin_playlist") ?? "";
  const rexPlaylistUrl = reservationToken
    ? withSourceParam(`/t/${encodeURIComponent(String(reservationToken))}`)
    : "";
  const originPlaylistUrl = withSourceParam(normalizeInternalRexPath(originPlaylistParam) || rexPlaylistUrl);
  const isHoaView = ctaAudience.toLowerCase() === "hoa";
  const [lastPlaylistInstanceId, setLastPlaylistInstanceId] = useState(() => getLastPlaylistInstanceId());

  useEffect(() => {
    if (!playlistId && !reservationToken) {
      setError("Missing playlist");
      setLoading(false);
      return;
    }
    const params = new URLSearchParams();
    if (reservationToken) {
      params.set("reservation_token", reservationToken);
    } else {
      params.set("playlist_instance_id", playlistId);
    }
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    applySourceToParams(params);
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
        const projectTitle = payload.data?.display_title || payload.data?.title || "Project";
        const experienceKey = String(payload.data?.experience_key || "").toLowerCase();

        const suffix =
          experienceKey === "concept"
            ? "Concept"
            : experienceKey === "client"
              ? "Final Plan"
              : "";

        setTitle(formatTitle(`${projectTitle}${suffix ? ` — ${suffix}` : ""}`));
        setItems(payload.data?.items || []);
        setViewerRexUrls(Array.isArray(payload.data?.viewer_rex_urls) ? payload.data.viewer_rex_urls : []);
        setViewerRexTargets(Array.isArray(payload.data?.viewer_rex_targets) ? payload.data.viewer_rex_targets : []);
        setViewerRexUrlByPaletteHash(
          payload.data?.viewer_rex_url_by_palette_hash &&
            typeof payload.data.viewer_rex_url_by_palette_hash === "object"
            ? payload.data.viewer_rex_url_by_palette_hash
            : {}
        );
        setIsProjectExperience(String(payload.data?.experience_source || "") === "project_reservation");
        setProjectColorPlans(Array.isArray(payload.data?.color_plans) ? payload.data.color_plans : []);
        setPaletteViewerCtaGroupId(payload.data?.palette_viewer_cta_group_id ? String(payload.data.palette_viewer_cta_group_id) : "");
      })
      .catch((err) => {
        setError(err?.message || "Failed to load playlist");
      })
      .finally(() => setLoading(false));
  }, [playlistId, addCtaGroup, reservationToken]);

  useEffect(() => {
    if (!playlistId || reservationToken) return;
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
    if (reservationToken) {
      return (Array.isArray(viewerRexTargets) ? viewerRexTargets : [])
        .map((target, index) => {
          const viewerUrl = normalizeInternalRexPath(target?.palette_viewer_url || target?.url || "");
          if (!viewerUrl) return null;
          const paletteHash = target?.palette_hash ? String(target.palette_hash) : "";
          const paletteViewerId = Number(target?.palette_viewer_id || 0) || null;
          const savedPaletteId = Number(target?.saved_palette_id || 0) || null;
          return {
            palette_viewer_id: paletteViewerId,
            saved_palette_id: savedPaletteId,
            palette_hash: paletteHash,
            saved_palette_set_id: null,
            palette_viewer_url: viewerUrl,
            painter_palette_viewer_url: "",
            title: formatTitle(target?.title || `Viewer ${index + 1}`),
            image_url: target?.image_url || "",
            is_liked: false,
          };
        })
        .filter(Boolean);
    }

    const seen = new Set();
    const list = [];
    const colorPlanById = new Map(
      (Array.isArray(projectColorPlans) ? projectColorPlans : []).map((plan) => [Number(plan.id), plan])
    );

    for (const item of items || []) {
      const type = (item?.type || "normal").toLowerCase();
      const attachedType = (item?.saved_palette_photo_type || "").toLowerCase();
      if (type === "intro" || type === "before" || type === "text" || type === "non-palette") continue;
      if (attachedType === "before") continue;
      if (item?.exclude_from_thumbs) continue;

      const apId = item?.ap_id ?? null;
      const paletteHash = item?.palette_hash ?? null;
      const savedPaletteSetId = Number(item?.saved_palette_set_id || 0) || null;
      const colorPlanId = Number(item?.color_plan_id || 0) || null;

      if (isProjectExperience) {
        if (!colorPlanId) continue;
        const key = `color-plan:${colorPlanId}`;
        if (seen.has(key)) continue;
        seen.add(key);

        const plan = colorPlanById.get(colorPlanId) || null;
        const planTitle =
          plan?.scheme_title ||
          plan?.area_name ||
          plan?.nickname ||
          `Color Plan ${colorPlanId}`;

        list.push({
          color_plan_id: colorPlanId,
          ap_id: apId,
          palette_hash: paletteHash,
          saved_palette_set_id: savedPaletteSetId,
          palette_viewer_url: item?.palette_viewer_url || "",
          painter_palette_viewer_url: item?.painter_palette_viewer_url || "",
          title: formatTitle(planTitle),
          image_url: item?.image_url || "",
          is_liked: apId ? likedSet.has(String(apId)) : false,
        });
        continue;
      }

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
        palette_viewer_url: item?.palette_viewer_url || "",
        painter_palette_viewer_url: item?.painter_palette_viewer_url || "",
        title: formatTitle(paletteTitle),
        image_url: item?.image_url || "",
        is_liked: apId ? likedSet.has(String(apId)) : false,
      });
    }

    return list;
  }, [items, likedSet, isProjectExperience, projectColorPlans, reservationToken, viewerRexTargets]);

  const [thumbUrlByKey, setThumbUrlByKey] = useState({});
  const shouldShowBackToPlaylist = reservationToken
    ? Boolean(rexPlaylistUrl)
    : Boolean(lastPlaylistInstanceId) && Boolean(playlistId) && String(lastPlaylistInstanceId) === String(playlistId);

  useEffect(() => {
    let cancelled = false;
    palettes.forEach((palette) => {
      const key = palette.color_plan_id
        ? `color-plan:${palette.color_plan_id}`
        : palette.palette_hash
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
    if (reservationToken && rexPlaylistUrl) {
      navigate(withSourceParam(rexPlaylistUrl));
      return;
    }
    const targetId = lastPlaylistInstanceId || playlistId;
    if (!targetId) return;
    navigate(withSourceParam(`/p/${targetId}`));
  };
  const handleExit = () => {
    navigate(withSourceParam("/"));
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
            {palettes.map((palette, index) => {
              const cardKey = palette.color_plan_id
                ? `color-plan:${palette.color_plan_id}`
                : palette.palette_viewer_id
                  ? `palette-viewer:${palette.palette_viewer_id}`
                : palette.palette_hash
                  ? `saved:${palette.palette_hash}:${palette.saved_palette_set_id || "default"}`
                  : palette.saved_palette_id
                    ? `saved-palette:${palette.saved_palette_id}`
                  : `applied:${palette.ap_id}`;
              const parsed = parsePhotoRef(palette.image_url);
              const resolvedUrl = parsed.url
                ? parsed.url
                : isAssetRef(palette.image_url)
                ? thumbUrlByKey[cardKey] || ""
                : palette.image_url;
              const params = new URLSearchParams();
              if (reservationToken) {
                const returnTo = withSourceParam(buildReturnTo(location.pathname, location.search));
                if (returnTo) params.set("return_to", returnTo);
                if (originPlaylistUrl) params.set("origin_playlist", withSourceParam(originPlaylistUrl));
              } else {
                if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
                else if (paletteViewerCtaGroupId !== "") params.set("add_cta_group", paletteViewerCtaGroupId);
                if (ctaAudience !== "") params.set("aud", ctaAudience);
                if (psiParam !== "") params.set("psi", psiParam);
                if (thumbParam !== "") params.set("thumb", thumbParam);
                if (demoParam !== "") params.set("demo", demoParam);
                const returnTo = withSourceParam(buildReturnTo(location.pathname, location.search));
                if (returnTo) params.set("return_to", returnTo);
                if (resolvedUrl) {
                  params.set("photo_url", resolvedUrl);
                }
                if (palette.saved_palette_set_id) {
                  params.set("set_id", String(palette.saved_palette_set_id));
                }
              }
              const qs = params.toString();
              const explicitViewerUrl = viewerParam === "painter" && palette.painter_palette_viewer_url
                ? palette.painter_palette_viewer_url
                : palette.palette_viewer_url;
              const rexHashViewerUrl = reservationToken && palette.palette_hash
                ? normalizeInternalRexPath(viewerRexUrlByPaletteHash[palette.palette_hash]) || ""
                : "";
              const selectedViewerUrl = reservationToken
                ? normalizeInternalRexPath(explicitViewerUrl) || rexHashViewerUrl || normalizeInternalRexPath(viewerRexUrls[index]) || ""
                : explicitViewerUrl;
              const href = selectedViewerUrl
                  ? withSourceParam(appendParams(selectedViewerUrl, Object.fromEntries(params.entries())))
                  : !reservationToken && palette.palette_hash
                  ? withSourceParam(`/palette/${palette.palette_hash}/share${qs ? `?${qs}` : ""}`)
                  : "";
              const cardContent = (
                <>
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
                </>
              );
              if (!href) {
                return (
                  <div
                    key={cardKey}
                    className="playlist-thumbs__card playlist-thumbs__card--disabled"
                    aria-disabled="true"
                    title="No REX viewer is linked to this palette yet"
                  >
                    {cardContent}
                  </div>
                );
              }
              return (
                <a
                  key={cardKey}
                  className="playlist-thumbs__card"
                  href={href}
                >
                  {cardContent}
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

function appendParams(url, params) {
  const entries = Object.entries(params || {}).filter(([, value]) => value !== undefined && value !== null && value !== "");
  if (!entries.length) return url;
  try {
    const base = `${window.location.origin}${url.startsWith("/") ? "" : "/"}${url}`;
    const next = new URL(base);
    entries.forEach(([key, value]) => {
      if (!next.searchParams.has(key)) next.searchParams.set(key, String(value));
    });
    return `${next.pathname}${next.search}${next.hash}`;
  } catch {
    const sep = url.includes("?") ? "&" : "?";
    const qs = new URLSearchParams(entries).toString();
    return qs ? `${url}${sep}${qs}` : url;
  }
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

function normalizeInternalRexPath(value) {
  const path = String(value || "").trim();
  if (!path || !path.startsWith("/t/") || path.startsWith("//")) return "";

  return path;
}
