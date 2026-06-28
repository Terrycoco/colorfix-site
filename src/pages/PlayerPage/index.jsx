import { useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate, useParams, useSearchParams } from "react-router-dom";
import Player from "@components/Player";
import CTALayout from "@components/cta/CTALayout";
import PlayerEndScreen from "@components/Player/PlayerEndScreen";
import { useAppState } from "@context/AppStateContext";
import { SHARE_FOLDER } from "@helpers/config";
import { buildCtaHandlers, getCtaKey } from "@helpers/ctaActions";
import { getPaletteTargets } from "@helpers/playerPaletteItems";
import { recordLastPlaylistInstanceId } from "@helpers/playlistHistory";
import { isHireTerryCta, trackCtaOnclickEvent, trackUserEvent } from "@helpers/userEvents";
import colorfixLogoUrl from "../../assets/brand/colorfix_lightbg.png";
import './playerpage.css';

const PLAYER_CLOSE_ON_EXIT_KEY = "cf.player.close_on_exit.v1";

export default function PlayerPage() {
  const { playlistId, start } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { adminExitPath, clearAdminExitPath } = useAppState();
  const isFastPlayerShell = location.pathname === "/p" || location.pathname.startsWith("/p/");
  const addCtaGroup = searchParams.get("add_cta_group") ?? "";
  const ctaAudience = searchParams.get("aud") ?? "";
  const psiParam = searchParams.get("psi") ?? "";
  const thumbParam = searchParams.get("thumb") ?? "";
  const demoParam = searchParams.get("demo") ?? "";
  const sourceParam = searchParams.get("src") ?? "";
  const includePrivateParam = searchParams.get("include_private") ?? "";
  const endParam = (searchParams.get("end") ?? "") === "1";
  const closeOnExitParam = (searchParams.get("close") ?? "") === "1";
  const freshParam = searchParams.get("fresh") ?? "";
  const reloadParam = searchParams.get("_") ?? "";
  const returnToParam = searchParams.get("return_to") ?? "";
  const debugTimingParam = searchParams.get("debug_timing") ?? "";
  const returnTo = resolveReturnTo(returnToParam);
  const startParamValue = start ?? searchParams.get("start") ?? "";
  const shouldCloseOnExit = closeOnExitParam || readPlayerCloseOnExit();


  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [errorCode, setErrorCode] = useState("");
  const [likedCount, setLikedCount] = useState(0);
  const [playbackEnded, setPlaybackEnded] = useState(false);
  const [watchNextCta, setWatchNextCta] = useState(null);
  const playerRef = useRef(null);
  const trackedOpenRef = useRef("");
  const trackedVisibleRef = useRef("");
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
    if (data?.thumbs_enabled && !thumbParam) {
      params.set("thumb", "1");
      changed = true;
    }
    if (data?.demo_enabled && !demoParam) {
      params.set("demo", "1");
      changed = true;
    }
    if (data?.audience && !ctaAudience) {
      params.set("aud", data.audience);
      changed = true;
    }
    if (!changed) return;
    const qs = params.toString();
    navigate(`${location.pathname}${qs ? `?${qs}` : ""}`, { replace: true });
  }, [data, demoParam, location.pathname, navigate, searchParams, thumbParam, ctaAudience]);

  useEffect(() => {
    if (!playlistId) {
      setError("Playlist unavailable");
      setErrorCode("playlist_unavailable");
      setLoading(false);
      return;
    }
    const params = new URLSearchParams();
    if (isNumericId(playlistId)) {
      params.set("playlist_instance_id", playlistId);
    } else {
      params.set("playlist_slug", playlistId);
    }
    if (startParamValue !== "") params.set("start", startParamValue);
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    if (ctaAudience !== "") params.set("aud", ctaAudience);
    if (debugTimingParam !== "") params.set("debug_timing", debugTimingParam);
    if (freshParam !== "") params.set("fresh", freshParam);
    if (reloadParam !== "") params.set("_", reloadParam);
    let cancelled = false;
    setLoading(true);
    setError("");
    setErrorCode("");
    fetchPlayerPlaylist(`/api/v2/player-playlist.php?${params.toString()}`)
      .then((payload) => {
        if (cancelled) return;
        if (!payload?.ok || !payload?.data) {
          throw new Error(payload?.error || "Failed to load playlist");
        }
        if (payload?.timing_ms && typeof window !== "undefined") {
          console.log("player-playlist timing_ms", payload.timing_ms);
        }
        setData(payload.data);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err?.message || "Failed to load playlist");
        setErrorCode(err?.code || "");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [playlistId, startParamValue, addCtaGroup, ctaAudience, debugTimingParam, freshParam, reloadParam]);

  useEffect(() => {
    if (!data?.playlist_instance_id) return;
    recordLastPlaylistInstanceId(data.playlist_instance_id);
  }, [data?.playlist_instance_id]);

  useEffect(() => {
    if (!data) return;
    const title = data?.page_h1 || data?.display_title || data?.title || "ColorFix Playlist";
    const description = data?.project_summary || data?.share_description || "";
    const image = String(data?.share_image_url || "").trim();
    const slugOrId = data?.slug || data?.playlist_instance_id || playlistId;
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
    const playlistInstanceId = Number(data?.playlist_instance_id || 0);
    if (!playlistInstanceId) return;
    const trackingKey = String(playlistInstanceId);
    if (trackedOpenRef.current === trackingKey) return;
    trackedOpenRef.current = trackingKey;

    trackUserEvent({
      event_type: "playlist_open",
      playlist_instance_id: playlistInstanceId,
      playlist_id: Number(data?.playlist_id || 0) || null,
    });
  }, [data?.playlist_id, data?.playlist_instance_id]);



  useEffect(() => {
    setPlaybackEnded(endParam);
    setLikedCount(0);
    setWatchNextCta(null);
  }, [data?.playlist_instance_id, endParam]);

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
      exitToPath(returnTo);
      return;
    }
    if (adminExitPath) {
      const target = adminExitPath;
      clearAdminExitPath();
      window.location.href = target;
      return;
    }
    exitToPath("/");
  }

  function exitToPath(path) {
    const target = resolveReturnTo(path) || "/";
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
  ]);

  const paletteTargets = useMemo(() => getPaletteTargets(data), [data]);

  const paletteCount = paletteTargets.length;

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
        return paletteCount > 1;
      case "to_palette":
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
    const emittedOnclick = trackCtaOnclickEvent({ cta, data });
    if (!emittedOnclick && isHireTerryCta(cta)) {
      trackUserEvent({
        event_type: "hire_terry_cta_click",
        playlist_instance_id: Number(data?.playlist_instance_id || 0),
        playlist_id: Number(data?.playlist_id || 0) || null,
        cta_id: Number(cta?.cta_id || 0) || null,
      });
    }
    if (!emittedOnclick && key === "watch_next") {
      trackUserEvent({
        event_type: "watch_next_click",
        playlist_instance_id: Number(data?.playlist_instance_id || 0),
        playlist_id: Number(data?.playlist_id || 0) || null,
        cta_id: Number(cta?.cta_id || 0) || null,
        source: "watch_next",
        allow_internal_tracking: true,
      });
    }
    if (!emittedOnclick && (key === "replay" || key === "replay_liked" || key === "replay_filtered")) {
      trackUserEvent({
        event_type: "replay_click",
        playlist_instance_id: Number(data?.playlist_instance_id || 0),
        playlist_id: Number(data?.playlist_id || 0) || null,
        cta_id: Number(cta?.cta_id || 0) || null,
        allow_internal_tracking: true,
      });
    }
    if (!emittedOnclick && (key === "share" || key === "share_playlist" || key === "copy_link")) {
      trackUserEvent({
        event_type: "share_click",
        playlist_instance_id: Number(data?.playlist_instance_id || 0),
        playlist_id: Number(data?.playlist_id || 0) || null,
        cta_id: Number(cta?.cta_id || 0) || null,
        allow_internal_tracking: true,
      });
    }
    ctaHandlers[key]?.(cta);
  }

const ctas = useMemo(() => {
  const raw = data?.ctas || [];
  return raw.map((cta, index) => {
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
}, [data?.ctas, psiParam, thumbsEnabled, demoEnabled, ctaAudience]);

useEffect(() => {
  const fromPicker = returnTo.startsWith("/picker");
  if (!fromPicker) return;
  if (sourceParam === "watch_next") return;

  const setIds = new Set();
  const pickerSetId = Number(psiParam || 0);
  if (pickerSetId > 0) setIds.add(pickerSetId);

  for (const setId of data?.playlist_instance_set_ids || []) {
    const normalized = Number(setId || 0);
    if (normalized > 0) setIds.add(normalized);
  }

  for (const cta of ctas || []) {
    if ((cta?.key || "") !== "watch_next") continue;
    const normalized = Number(
      cta?.params?.playlist_instance_set_id ||
      cta?.params?.set_id ||
      0
    );
    if (normalized > 0) setIds.add(normalized);
  }

  for (const setId of setIds) {
    clearWatchNextSeen(setId);
    clearWatchNextSeenPlaylists(setId);
  }
}, [ctas, data?.playlist_instance_id, data?.playlist_instance_set_ids, psiParam, returnTo, sourceParam]);

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

const visibleCTAs = useMemo(
  () => ctas.filter(isCtaVisible),
  [ctas, likedCount]
);

  const baseVisibleCTAs = useMemo(
    () => visibleCTAs.filter((cta) => (cta?.key || "") !== "watch_next"),
    [visibleCTAs]
  );

  const orderedCTAs = useMemo(() => {
    if (watchNextCta) return [...baseVisibleCTAs, watchNextCta];
    return baseVisibleCTAs;
  }, [baseVisibleCTAs, watchNextCta]);

  useEffect(() => {
    const playlistInstanceId = Number(data?.playlist_instance_id || 0);
    if (!playlistInstanceId || !playbackEnded) return;

    const hireTerryCta = orderedCTAs.find(isHireTerryCta);
    if (!hireTerryCta) return;

    const ctaKey = Number(hireTerryCta?.cta_id || 0) || "hire-terry";
    const trackingKey = `${playlistInstanceId}:${ctaKey}`;
    if (trackedVisibleRef.current === trackingKey) return;
    trackedVisibleRef.current = trackingKey;

    trackUserEvent({
      event_type: "hire_terry_cta_visible",
      playlist_instance_id: playlistInstanceId,
      playlist_id: Number(data?.playlist_id || 0) || null,
      cta_id: Number(hireTerryCta?.cta_id || 0) || null,
    });
  }, [data?.playlist_id, data?.playlist_instance_id, orderedCTAs, playbackEnded]);

  useEffect(() => {
    if (!data) {
      setWatchNextCta(null);
      return;
    }
    const baseCta = visibleCTAs.find((cta) => (cta?.key || "") === "watch_next");
    const ctaSetId = Number(baseCta?.params?.playlist_instance_set_id || baseCta?.params?.set_id || 0);
    const explicitSetId =
      ctaSetId ||
      Number(psiParam || 0);
    const storedJourneySetId = readActiveWatchNextSetId();
    const isWatchNextNavigation = sourceParam === "watch_next";

    const setId =
      (isWatchNextNavigation && storedJourneySetId ? storedJourneySetId : 0) ||
      explicitSetId ||
      (isWatchNextNavigation ? storedJourneySetId : 0) ||
      (baseCta ? 3 : 0);
    if (!setId) {
      setWatchNextCta(baseCta || null);
      return;
    }

    const playlistInstanceId = Number(data?.playlist_instance_id || 0);
    const journeyKey = `${playlistInstanceId}:${setId}:${isWatchNextNavigation ? "watch_next" : "entry"}`;
    if (initializedWatchNextJourneyRef.current !== journeyKey) {
      initializedWatchNextJourneyRef.current = journeyKey;
      writeActiveWatchNextSetId(setId);
      if (!isWatchNextNavigation) {
        clearWatchNextSeen(setId);
      }
    }

    let cancelled = false;
    const load = async () => {
      try {
        const setParams = new URLSearchParams({
          id: String(setId),
        });
        if (ctaAudience) {
          setParams.set("aud", ctaAudience);
        }
        if (adminExitPath || includePrivateParam === "1") {
          setParams.set("include_private", "1");
        }
        const res = await fetch(`/api/v2/playlist-instance-sets/get.php?${setParams.toString()}`, {
          credentials: "include",
          headers: { Accept: "application/json" },
        });
        const payload = await res.json().catch(() => ({}));
        if (!res.ok || !payload?.ok) {
          throw new Error(payload?.error || "Failed to load playlist set");
        }
        if (cancelled) return;
        const items = Array.isArray(payload?.set?.items) ? payload.set.items : [];
        const playlistItems = items
          .filter((item) => (item?.item_type || "instance") !== "set")
          .filter((item) => item?.playlist_instance_id);

        const setItemIds = playlistItems.map((item) => Number(item.playlist_instance_id));
        const currentId = Number(data?.playlist_instance_id);
        const currentPlaylistId = Number(data?.playlist_id);
        const seen = readWatchNextSeen(setId);
        const globalSeen = readGlobalWatchNextSeen();
        const globalSeenPlaylistIds = readGlobalWatchNextSeenPlaylists();

        const isEligibleNextItem = (item, { ignoreSeen = false } = {}) => {
          const pid = Number(item?.playlist_instance_id);
          const playlistItemId = Number(item?.playlist_id);
          if (!pid || pid === currentId) return false;
          if (ignoreSeen) return true;
          if (playlistItemId && currentPlaylistId && playlistItemId === currentPlaylistId) return false;
          if (playlistItemId && globalSeenPlaylistIds.includes(playlistItemId)) return false;
          if (globalSeen.includes(pid)) return false;
          return !seen.includes(pid);
        };

        const nextItem = playlistItems.find((item) => isEligibleNextItem(item))
          || playlistItems.find((item) => isEligibleNextItem(item, { ignoreSeen: true }));

        if (!nextItem) {
          if (playbackEnded && currentId) {
            if (setItemIds.includes(currentId)) {
              markWatchNextSeen(setId, currentId);
            }
            markGlobalWatchNextSeen(currentId);
            if (currentPlaylistId) {
              markGlobalWatchNextSeenPlaylist(currentPlaylistId);
            }
          }
          const endSetCta = buildEndSetCta(payload?.set?.end_cta, setId);
          clearActiveWatchNextSetId();
          setWatchNextCta(endSetCta);
          return;
        }

        const resolvedTitle = nextItem.title || baseCta?.params?.title || "Suggested Playlists";
        const resolvedSubtitle = baseCta?.params?.subtitle || baseCta?.params?.dek || "";
        const resolvedThumb = nextItem.photo_url || "";
        const resolved = {
          ...baseCta,
          key: "watch_next",
          params: {
            ...(baseCta?.params || {}),
            playlist_instance_id: Number(nextItem.playlist_instance_id),
            url: nextItem.player_url ? appendUrlParams(normalizePlayerUrlForShell(nextItem.player_url, isFastPlayerShell), {
              psi: setId,
              include_private: includePrivateParam === "1" ? "1" : undefined,
              close: shouldCloseOnExit ? "1" : undefined,
              return_to: returnTo || undefined,
            }) : undefined,
            audience: ctaAudience || data?.audience || undefined,
            title: resolvedTitle,
            subtitle: resolvedSubtitle,
            thumbnail_url: resolvedThumb,
            photo_library_id: nextItem.photo_library_id || undefined,
          },
          data: {
            ...(baseCta?.data || {}),
            playlist_instance_id: Number(nextItem.playlist_instance_id),
            playlist_id: Number(nextItem.playlist_id) || undefined,
            playlist_instance_slug: nextItem.playlist_slug || undefined,
            slug: nextItem.playlist_slug || undefined,
            player_url: nextItem.player_url || undefined,
          },
        };
        setWatchNextCta(resolved);

        if (playbackEnded && currentId) {
          if (setItemIds.includes(currentId)) {
            markWatchNextSeen(setId, currentId);
          }
          markGlobalWatchNextSeen(currentId);
          if (currentPlaylistId) {
            markGlobalWatchNextSeenPlaylist(currentPlaylistId);
          }
        }
      } catch {
        if (!cancelled) {
          setWatchNextCta(baseCta || null);
        }
      }
    };
    load();
    return () => {
      cancelled = true;
    };
  }, [adminExitPath, ctaAudience, data, includePrivateParam, shouldCloseOnExit, visibleCTAs, playbackEnded, psiParam, sourceParam]);

  if (loading) {
    return (
      <div className="player-page">
        <PlayerLoadingIndicator label="Loading playlist" />
      </div>
    );
  }
  if (error) {
    if (errorCode === "playlist_unavailable") {
      return <PlaylistUnavailable />;
    }
    return (
      <div className="player-error" role="alert">
        <div className="player-error__panel">
          <div className="player-error__title">Playlist could not load</div>
          <div className="player-error__message">{error}</div>
          <button type="button" onClick={() => window.location.reload()}>
            Try again
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="player-page">
      <div className="player-frame">
        <Player
          ref={playerRef}
          slides={data?.items || []}
          startIndex={data?.start_index ?? 0}
          playlistInstanceId={data?.playlist_instance_id || playlistId}
          hideStars={Boolean(data?.hide_stars)}
          galleryName={data?.page_h1 || data?.display_title || data?.title || ""}
          galleryDescription={data?.project_summary || data?.share_description || ""}
          onAbort={handleExit}
          onLikeChange={({ likedCount: nextCount }) => setLikedCount(nextCount)}
          onPlaybackEnd={({ likedCount: nextCount }) => {
              setLikedCount(nextCount);
              setPlaybackEnded(true);
            }}
        />
        {playbackEnded && (
          <PlayerEndScreen scrollable={Boolean(watchNextCta)}>
            {orderedCTAs.length > 0 && (
              <CTALayout
                layout="stacked"
                ctas={orderedCTAs}
                onCtaClick={handleCta}
              />
            )}
          </PlayerEndScreen>
        )}
      </div>

    </div>
  );
}

function PlaylistUnavailable() {
  return (
    <main className="playlist-unavailable" role="alert">
      <section className="playlist-unavailable__panel" aria-labelledby="playlist-unavailable-title">
        <img className="playlist-unavailable__logo" src={colorfixLogoUrl} alt="ColorFix" />
        <h1 id="playlist-unavailable-title">This playlist isn&rsquo;t available.</h1>
        <p>It may have been moved or taken offline.</p>
        <div className="playlist-unavailable__actions">
          <a href="/picker">Browse Playlists</a>
          <a href="/">Go to ColorFix Home</a>
        </div>
      </section>
    </main>
  );
}

function PlayerLoadingIndicator({ label = "Loading" }) {
  return (
    <div className="player-page-loading" role="status" aria-live="polite" aria-label={label}>
      <span className="player-page-loading__dot" />
      <span className="player-page-loading__dot" />
      <span className="player-page-loading__dot" />
    </div>
  );
}

async function fetchPlayerPlaylist(url) {
  const options = {
    credentials: "include",
    headers: { Accept: "application/json" },
  };
  try {
    return await fetchJsonWithTimeout(url, options, 8000);
  } catch (err) {
    if (!isRetryablePlaylistError(err)) throw err;
    return fetchJsonWithTimeout(url, { ...options, cache: "reload" }, 8000);
  }
}

async function fetchJsonWithTimeout(url, options, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(url, { ...options, signal: controller.signal });
    const text = await response.text();
    let payload = null;
    try {
      payload = text ? JSON.parse(text) : null;
    } catch {
      throw new Error("Playlist returned a server page instead of data. Please try again.");
    }
    if (!response.ok) {
      const error = new Error(payload?.error || `Playlist request failed (${response.status})`);
      error.status = response.status;
      error.code = payload?.code || "";
      throw error;
    }
    return payload;
  } finally {
    clearTimeout(timer);
  }
}

function isTimeoutError(err) {
  return err?.name === "AbortError";
}

function isRetryablePlaylistError(err) {
  if (isTimeoutError(err)) return true;
  const message = String(err?.message || "").toLowerCase();
  return message.includes("server page")
    || message.includes("failed (5")
    || message.includes("network");
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

function markWatchNextSeen(setId, playlistInstanceId) {
  if (typeof sessionStorage === "undefined") return;
  const current = readWatchNextSeen(setId);
  if (current.includes(playlistInstanceId)) return;
  const next = [...current, playlistInstanceId];
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

function markGlobalWatchNextSeen(playlistInstanceId) {
  if (typeof sessionStorage === "undefined" || !playlistInstanceId) return;
  const current = readGlobalWatchNextSeen();
  if (current.includes(playlistInstanceId)) return;
  try {
    sessionStorage.setItem("cf_watch_next_seen_global", JSON.stringify([...current, playlistInstanceId]));
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

function clearWatchNextSeenPlaylists(setId) {
  if (typeof sessionStorage === "undefined" || !setId) return;
  try {
    sessionStorage.removeItem(`cf_watch_next_seen_playlist_${setId}`);
  } catch {
    // ignore
  }
}
