import { useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate, useParams, useSearchParams } from "react-router-dom";
import Player from "@components/Player";
import CTALayout from "@components/cta/CTALayout";
import PlayerEndScreen from "@components/Player/PlayerEndScreen";
import { useAppState } from "@context/AppStateContext";
import { SHARE_FOLDER } from "@helpers/config";
import { buildCtaHandlers, getCtaKey } from "@helpers/ctaActions";
import { getPaletteTargets } from "@helpers/playerPaletteItems";
import { recordLastPlaylistId } from "@helpers/playlistHistory";
import { withSourceParam } from "@helpers/sourceParam";
import "@pages/PlayerPage/playerpage.css";

const PLAYER_CLOSE_ON_EXIT_KEY = "cf.player.close_on_exit.v1";

export default function PlayerExperience({ data }) {
  const { playlistId, token: routeToken } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { adminExitPath, clearAdminExitPath } = useAppState();
  const isFastPlayerShell = location.pathname === "/p" || location.pathname.startsWith("/p/");
  const ctaAudience = searchParams.get("aud") ?? "";
  const psiParam = searchParams.get("psi") ?? "";
  const thumbParam = searchParams.get("thumb") ?? "";
  const demoParam = searchParams.get("demo") ?? "";
  const sourceParam = searchParams.get("src") ?? "";
  const reservationTokenParam = location.pathname.startsWith("/t/") && routeToken
    ? routeToken
    : searchParams.get("reservation_token") ?? searchParams.get("token") ?? "";
  const includePrivateParam = searchParams.get("include_private") ?? "";
  const endParam = (searchParams.get("end") ?? "") === "1";
  const closeOnExitParam = (searchParams.get("close") ?? "") === "1";
  const returnToParam = searchParams.get("return_to") ?? "";
  const returnTo = resolveReturnTo(returnToParam);
  const shouldCloseOnExit = closeOnExitParam || readPlayerCloseOnExit();

  const [likedCount, setLikedCount] = useState(0);
  const [playbackEnded, setPlaybackEnded] = useState(false);
  const [watchNextCta, setWatchNextCta] = useState(null);
  const playerRef = useRef(null);
  const initializedWatchNextJourneyRef = useRef("");
  const thumbsEnabled =
    thumbParam === "1" || thumbParam.toLowerCase() === "true" || Boolean(data?.thumbs_enabled);
  const demoEnabled =
    demoParam === "1" || demoParam.toLowerCase() === "true" || Boolean(data?.demo_enabled);

  useEffect(() => {
    if (closeOnExitParam) writePlayerCloseOnExit(true);
  }, [closeOnExitParam]);

  useEffect(() => {
    if (!data) return;
    const params = new URLSearchParams(searchParams);
    let changed = false;
    // Legacy playlist-instance URLs used ?thumb=1 as navigation state.
    // REX playlist URLs must stay canonical: /t/<token>.
    if (data?.thumbs_enabled && !thumbParam && !location.pathname.startsWith("/t/")) {
      params.set("thumb", "1");
      changed = true;
    }
    if (data?.demo_enabled && !demoParam) {
      params.set("demo", "1");
      changed = true;
    }
    if (data?.audience && !ctaAudience && !location.pathname.startsWith("/t/")) {
      params.set("aud", data.audience);
      changed = true;
    }
    if (!changed) return;
    const qs = params.toString();
    navigate(`${location.pathname}${qs ? `?${qs}` : ""}`, { replace: true });
  }, [data, demoParam, location.pathname, navigate, searchParams, thumbParam, ctaAudience]);

  useEffect(() => {
    if (!data?.playlist_id) return;
    recordLastPlaylistId(data.playlist_id);
  }, [data?.playlist_id]);

  useEffect(() => {
    if (!data) return;
    const title = data?.page_h1 || data?.display_title || data?.title || "ColorFix Playlist";
    const description = data?.project_summary || data?.share_description || "";
    const image = String(data?.share_image_url || "").trim();
    const slugOrId = data?.slug || data?.playlist_id || playlistId;
    const canonicalPath = slugOrId ? `/playlist/${slugOrId}` : location.pathname;
    const canonicalUrl = `${window.location.origin}${canonicalPath}`;
    const absoluteImage = image.startsWith("http://") || image.startsWith("https://")
      ? image
      : image.startsWith("/")
        ? `${window.location.origin}${image}`
        : "";

    document.title = title;
    setMetaContent("description", description);
    setMetaProperty("og:title", title);
    setMetaProperty("og:url", canonicalUrl);
    if (description) {
      setMetaProperty("og:description", description);
    }
    setMetaProperty("og:image", absoluteImage);
    setCanonicalHref(canonicalUrl);
  }, [data, location.pathname, playlistId]);

  useEffect(() => {
    setPlaybackEnded(endParam);
    setLikedCount(0);
    setWatchNextCta(null);
  }, [data?.playlist_id, endParam]);

  function handleExit() {
    if (shouldCloseOnExit) {
      window.close();
      window.setTimeout(() => {
        writePlayerCloseOnExit(false);
        exitToPath(returnTo || adminExitPath || "/admin/");
      }, 150);
      return;
    }
    if (returnTo) {
      exitToPath(withSourceParam(returnTo, sourceParam));
      return;
    }
    if (adminExitPath) {
      const target = adminExitPath;
      clearAdminExitPath();
      window.location.href = withSourceParam(target, sourceParam);
      return;
    }
    exitToPath("/");
  }

  function exitToPath(path) {
    const target = withSourceParam(resolveReturnTo(path) || "/", sourceParam);
    if (isFastPlayerShell && !target.startsWith("/p/")) {
      window.location.href = target;
      return;
    }
    navigate(target);
  }

  const firstNonIntroIndex = useMemo(() => {
    const items = data?.items || [];
    const index = items.findIndex((item) => {
      const type = (item?.type || "normal").toLowerCase();
      return type !== "intro" && type !== "text";
    });
    return index >= 0 ? index : 0;
  }, [data?.items]);

  const lastReplayableIndex = useMemo(() => findLastReplayableIndex(data?.items || []), [data?.items]);

  function handleEndScreenBack() {
    setPlaybackEnded(false);
    playerRef.current?.replay?.({ likedOnly: false, startIndex: lastReplayableIndex });
  }

  const ctaHandlers = useMemo(() => (
    buildCtaHandlers({
      data,
      shareFolder: SHARE_FOLDER,
      playerRef,
      setPlaybackEnded,
      firstNonIntroIndex,
      handleExit,
      navigate,
      ctaAudience,
      psi: psiParam,
      thumb: thumbsEnabled,
      demo: demoEnabled,
      returnTo,
      reservationToken: reservationTokenParam,
    })
  ), [
    data,
    firstNonIntroIndex,
    handleExit,
    navigate,
    ctaAudience,
    psiParam,
    thumbParam,
    demoParam,
    data?.thumbs_enabled,
    data?.demo_enabled,
    returnTo,
    reservationTokenParam,
  ]);

  const paletteTargets = useMemo(() => getPaletteTargets(data), [data]);

  const paletteCount = paletteTargets.length;
  const isRexPlaylist = Boolean(reservationTokenParam);
  function isCtaVisible(cta) {
    if (!cta) return false;
    if (cta.enabled === false) return false;

    switch (cta.key) {
      case "replay_liked":
        return !data?.hide_stars && likedCount > 0;
      case "replay_filtered":
        if (cta?.params?.filter === "liked") {
          return !data?.hide_stars && likedCount > 0;
        }
        return true;
      case "to_thumbs":
        if (isRexPlaylist) return true;
        return paletteCount > 1;
      case "to_palette":
        if (isRexPlaylist) return true;
        return paletteCount === 1;

      // future examples (not active yet):
      // case "see_palettes":
      //   return (data?.palettes?.length ?? 0) > 0;

      default:
        return true;
    }
  }

  function handleCta(cta) {
    const key = getCtaKey(cta);
    if (!key) return;
    ctaHandlers[key]?.(cta);
  }

  function handlePalettePromptClick(item) {
    if (!item) return;
    ctaHandlers.to_palette?.({
      cta_id: "player-see-these-colors",
      key: "to_palette",
      label: "See These Colors",
      params: {
        target: "_self",
        return_to: buildSlideReturnTo({
          data,
          playlistId,
          item,
          location,
          searchParams,
          isFastPlayerShell,
        }),
      },
    });
  }

  const ctas = useMemo(() => {
    const raw = data?.ctas || [];
    const mapped = raw.map((cta, index) => {
      let parsedParams = {};
      if (typeof cta?.params === "string" && cta.params.trim() !== "") {
        try {
          const decoded = JSON.parse(cta.params);
          if (decoded && typeof decoded === "object") {
            parsedParams = decoded;
          }
        } catch {
          parsedParams = {};
        }
      } else if (cta?.params && typeof cta.params === "object") {
        parsedParams = cta.params;
      }

      const key = (cta?.key || cta?.action_key || cta?.action || "").toString().toLowerCase();

      let variant = resolveVariant(
        cta?.variant ?? parsedParams.variant ?? parsedParams.style,
        key.toLowerCase().includes("back")
      );
      if (!variant && key === "article_link") {
        variant = "link";
      }
      return {
        cta_id: cta?.cta_id ?? `${key || "cta"}-${index}`,
        label: cta?.label ?? "",
        key,
        enabled: resolveEnabled(cta?.enabled, parsedParams, psiParam, thumbsEnabled, demoEnabled, ctaAudience),
        variant,
        display_mode: cta?.display_mode ?? parsedParams.display_mode,
        icon: cta?.icon ?? parsedParams.icon,
        onclick: cta?.onclick || "",
        params: parsedParams,
      };
    });

    return mapped;
  }, [
    data?.ctas,
    psiParam,
    thumbsEnabled,
    demoEnabled,
    ctaAudience,
  ]);

  

  const visibleCTAs = useMemo(
    () => ctas.filter(isCtaVisible),
    [ctas, likedCount, isRexPlaylist, paletteCount, data?.hide_stars]
  );

  const baseVisibleCTAs = useMemo(
    () => visibleCTAs.filter((cta) => (cta?.key || "") !== "watch_next"),
    [visibleCTAs]
  );

  const orderedCTAs = useMemo(() => {
    if (watchNextCta) return [...baseVisibleCTAs, watchNextCta];
    return baseVisibleCTAs;
  }, [baseVisibleCTAs, watchNextCta]);

  

  return (
    <div className="player-page">
      <div className="player-frame">
        <Player
          ref={playerRef}
          slides={data?.items || []}
          startIndex={data?.start_index ?? 0}
          playlistId={data?.playlist_id || playlistId}
          hideStars={Boolean(data?.hide_stars)}
          showSlidePalettePrompt={data?.show_slide_palette_prompt !== false}
          galleryName={data?.page_h1 || data?.display_title || data?.title || ""}
          galleryDescription={data?.project_summary || data?.share_description || ""}
          onAbort={handleExit}
          onLikeChange={({ likedCount: nextCount }) => setLikedCount(nextCount)}
          onPlaybackEnd={({ likedCount: nextCount }) => {
              setLikedCount(nextCount);
              setPlaybackEnded(true);
            }}
          onPalettePromptClick={handlePalettePromptClick}
        />
        {playbackEnded && (
          <>
            <button
              className="player-back player-back--end-screen"
              type="button"
              onClick={handleEndScreenBack}
              aria-label="Go back one slide"
            >
              ←
            </button>
            <PlayerEndScreen scrollable={Boolean(watchNextCta)}>
              {orderedCTAs.length > 0 && (
                <CTALayout
                  layout="stacked"
                  ctas={orderedCTAs}
                  onCtaClick={handleCta}
                />
              )}
            </PlayerEndScreen>
          </>
        )}
      </div>

    </div>
  );
}

function resolveVariant(raw, isBack = false) {
  if (!raw) return isBack ? "link" : undefined;
  const normalized = String(raw).toLowerCase();
  if (normalized === "anchor" || normalized === "link") return "link";
  if (normalized === "button_logo" || normalized === "button-logo" || normalized === "logo_button") return "button-logo";
  if (normalized === "button") return isBack ? "link" : undefined;
  if (normalized === "primary" || normalized === "secondary" || normalized === "ghost") return normalized;
  return isBack ? "link" : undefined;
}

function resolveReturnTo(value) {
  if (!value) return "";
  const trimmed = String(value).trim();
  if (!trimmed.startsWith("/")) return "";
  if (trimmed.startsWith("//")) return "";
  return trimmed;
}

function buildSlideReturnTo({ data, playlistId, item, location, searchParams, isFastPlayerShell }) {
  const pathId = data?.slug || data?.playlist_id || playlistId;
  const fallbackPath = location?.pathname || "/";
  const basePath = pathId
    ? `${isFastPlayerShell ? "/p" : "/playlist"}/${encodeURIComponent(String(pathId))}`
    : fallbackPath;
  const params = new URLSearchParams(searchParams);
  params.delete("end");
  params.delete("start");
  params.delete("position");
  params.delete("pos");
  params.delete("playlist_item_id");
  params.delete("item_id");
  const slideId = Number(item?.playlist_item_id || item?.id || 0);
  if (slideId > 0) {
    params.set("slide_id", String(slideId));
  }
  const query = params.toString();
  return withSourceParam(`${basePath}${query ? `?${query}` : ""}`);
}

function readPlayerCloseOnExit() {
  if (typeof sessionStorage === "undefined") return false;
  try {
    return sessionStorage.getItem(PLAYER_CLOSE_ON_EXIT_KEY) === "1";
  } catch {
    return false;
  }
}

function writePlayerCloseOnExit(enabled) {
  if (typeof sessionStorage === "undefined") return;
  try {
    if (enabled) {
      sessionStorage.setItem(PLAYER_CLOSE_ON_EXIT_KEY, "1");
    } else {
      sessionStorage.removeItem(PLAYER_CLOSE_ON_EXIT_KEY);
    }
  } catch {
    // Ignore storage failures.
  }
}

function isNumericId(value) {
  return /^[0-9]+$/.test(String(value || "").trim());
}

function setMetaContent(name, content) {
  if (typeof document === "undefined") return;
  let el = document.querySelector(`meta[name="${name}"]`);
  if (!content) {
    if (el) el.remove();
    return;
  }
  if (!el) {
    el = document.createElement("meta");
    el.setAttribute("name", name);
    document.head.appendChild(el);
  }
  el.setAttribute("content", content);
}

function setMetaProperty(property, content) {
  if (typeof document === "undefined") return;
  let el = document.querySelector(`meta[property="${property}"]`);
  if (!content) {
    if (el) el.remove();
    return;
  }
  if (!el) {
    el = document.createElement("meta");
    el.setAttribute("property", property);
    document.head.appendChild(el);
  }
  el.setAttribute("content", content);
}

function setCanonicalHref(href) {
  if (typeof document === "undefined") return;
  let el = document.querySelector('link[rel="canonical"]');
  if (!el) {
    el = document.createElement("link");
    el.setAttribute("rel", "canonical");
    document.head.appendChild(el);
  }
  el.setAttribute("href", href);
}

function isTruthyFlag(value) {
  if (value === true) return true;
  if (value === false || value === null || value === undefined) return false;
  const normalized = String(value).toLowerCase().trim();
  return normalized === "1" || normalized === "true" || normalized === "yes";
}

function resolveEnabled(baseEnabled, params, psiParam, thumbParam, demoParam, audParam) {
  const enabled = baseEnabled ?? true;
  if (!enabled) return false;
  const requirePsi = Boolean(params?.require_psi || params?.requirePsi || params?.require_psi_id);
  if (requirePsi && !psiParam) return false;
  const requireThumb = Boolean(params?.require_thumb || params?.requireThumb);
  if (requireThumb && !isTruthyFlag(thumbParam)) return false;
  const requireDemo = Boolean(params?.require_demo || params?.requireDemo);
  if (requireDemo && !isTruthyFlag(demoParam)) return false;
  const requireAud = params?.require_aud || params?.requireAud;
  const normalizedAud = String(audParam || "").toLowerCase().trim();
  if (requireAud && normalizedAud && normalizedAud !== "any" && normalizedAud !== String(requireAud).toLowerCase()) {
    return false;
  }
  return true;
}

function readWatchNextSeen(setId) {
  if (typeof sessionStorage === "undefined") return [];
  try {
    const raw = sessionStorage.getItem(`cf_watch_next_seen_${setId}`);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.map(Number).filter(Boolean) : [];
  } catch {
    return [];
  }
}

function markWatchNextSeen(setId, playlistId) {
  if (typeof sessionStorage === "undefined") return;
  const current = readWatchNextSeen(setId);
  if (current.includes(playlistId)) return;
  const next = [...current, playlistId];
  try {
    sessionStorage.setItem(`cf_watch_next_seen_${setId}`, JSON.stringify(next));
  } catch {
    // ignore
  }
}

function readGlobalWatchNextSeen() {
  if (typeof sessionStorage === "undefined") return [];
  try {
    const raw = sessionStorage.getItem("cf_watch_next_seen_global");
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.map(Number).filter(Boolean) : [];
  } catch {
    return [];
  }
}

function markGlobalWatchNextSeen(playlistId) {
  if (typeof sessionStorage === "undefined" || !playlistId) return;
  const current = readGlobalWatchNextSeen();
  if (current.includes(playlistId)) return;
  try {
    sessionStorage.setItem("cf_watch_next_seen_global", JSON.stringify([...current, playlistId]));
  } catch {
    // ignore
  }
}

function readGlobalWatchNextSeenPlaylists() {
  if (typeof sessionStorage === "undefined") return [];
  try {
    const raw = sessionStorage.getItem("cf_watch_next_seen_playlist_global");
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.map(Number).filter(Boolean) : [];
  } catch {
    return [];
  }
}

function markGlobalWatchNextSeenPlaylist(playlistId) {
  if (typeof sessionStorage === "undefined" || !playlistId) return;
  const current = readGlobalWatchNextSeenPlaylists();
  if (current.includes(playlistId)) return;
  try {
    sessionStorage.setItem("cf_watch_next_seen_playlist_global", JSON.stringify([...current, playlistId]));
  } catch {
    // ignore
  }
}

function clearWatchNextSeen(setId) {
  if (typeof sessionStorage === "undefined" || !setId) return;
  try {
    sessionStorage.removeItem(`cf_watch_next_seen_${setId}`);
  } catch {
    // ignore
  }
}

function readActiveWatchNextSetId() {
  if (typeof sessionStorage === "undefined") return 0;
  try {
    return Number(sessionStorage.getItem("cf_watch_next_active_set_id") || 0) || 0;
  } catch {
    return 0;
  }
}

function writeActiveWatchNextSetId(setId) {
  if (typeof sessionStorage === "undefined" || !setId) return;
  try {
    sessionStorage.setItem("cf_watch_next_active_set_id", String(setId));
  } catch {
    // ignore
  }
}

function clearActiveWatchNextSetId() {
  if (typeof sessionStorage === "undefined") return;
  try {
    sessionStorage.removeItem("cf_watch_next_active_set_id");
  } catch {
    // ignore
  }
}

function appendUrlParams(url, params) {
  if (!url) return url;
  const entries = Object.entries(params || {}).filter(([, value]) => value !== undefined && value !== null && value !== "");
  if (!entries.length) return url;
  try {
    const base = url.startsWith("http://") || url.startsWith("https://")
      ? url
      : `${window.location.origin}${url.startsWith("/") ? "" : "/"}${url}`;
    const parsed = new URL(base);
    entries.forEach(([key, value]) => {
      parsed.searchParams.set(key, String(value));
    });
    if (url.startsWith("http://") || url.startsWith("https://")) return parsed.toString();
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    const sep = url.includes("?") ? "&" : "?";
    return `${url}${sep}${new URLSearchParams(entries).toString()}`;
  }
}

function normalizePlayerUrlForShell(url, useFastPlayerShell = false) {
  if (!useFastPlayerShell || !url) return url;
  try {
    const base = url.startsWith("http://") || url.startsWith("https://")
      ? url
      : `${window.location.origin}${url.startsWith("/") ? "" : "/"}${url}`;
    const parsed = new URL(base);
    if (parsed.pathname.startsWith("/playlist/")) {
      parsed.pathname = parsed.pathname.replace(/^\/playlist\//, "/p/");
    }
    if (url.startsWith("http://") || url.startsWith("https://")) return parsed.toString();
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return String(url).replace(/^\/playlist\//, "/p/");
  }
}

function buildEndSetCta(rawEndCta, setId) {
  const enabled = rawEndCta?.enabled !== false;
  if (!enabled) return null;
  return {
    cta_id: `end-set-${setId}`,
    label: rawEndCta?.label || "Explore ColorFix",
    key: "navigate",
    enabled: true,
    variant: "primary",
    params: {
      url: rawEndCta?.url || "/",
      target: "_self",
      brand: "colorfix",
    },
  };
}

function findLastReplayableIndex(items) {
  const list = Array.isArray(items) ? items : [];
  for (let index = list.length - 1; index >= 0; index -= 1) {
    const type = String(list[index]?.type || list[index]?.item_type || "normal").toLowerCase().trim();
    if (type !== "brand-bumper") return index;
  }
  return Math.max(0, list.length - 1);
}

function clearWatchNextSeenPlaylists(setId) {
  if (typeof sessionStorage === "undefined" || !setId) return;
  try {
    sessionStorage.removeItem(`cf_watch_next_seen_playlist_${setId}`);
  } catch {
    // ignore
  }
}
