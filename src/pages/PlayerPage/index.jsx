import { useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate, useParams, useSearchParams } from "react-router-dom";
import Player from "@components/Player";
import CTALayout from "@components/cta/CTALayout";
import PlayerEndScreen from "@components/Player/PlayerEndScreen";
import { SHARE_FOLDER } from "@helpers/config";
import { buildCtaHandlers, getCtaKey } from "@helpers/ctaActions";
import './playerpage.css';

export default function PlayerPage() {
  const { playlistId, start } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const addCtaGroup = searchParams.get("add_cta_group") ?? "";
  const ctaAudience = searchParams.get("aud") ?? "";
  const psiParam = searchParams.get("psi") ?? "";
  const thumbParam = searchParams.get("thumb") ?? "";
  const demoParam = searchParams.get("demo") ?? "";
  const endParam = (searchParams.get("end") ?? "") === "1";
  const returnToParam = searchParams.get("return_to") ?? "";
  const returnTo = resolveReturnTo(returnToParam);


  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [likedCount, setLikedCount] = useState(0);
  const [playbackEnded, setPlaybackEnded] = useState(false);
  const [watchNextCta, setWatchNextCta] = useState(null);
  const playerRef = useRef(null);
  const thumbsEnabled =
    thumbParam === "1" || thumbParam.toLowerCase() === "true" || Boolean(data?.thumbs_enabled);
  const demoEnabled =
    demoParam === "1" || demoParam.toLowerCase() === "true" || Boolean(data?.demo_enabled);

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
      setError("Missing playlist instance id");
      setLoading(false);
      return;
    }
    const startParam = start ?? searchParams.get("start") ?? "";
    const params = new URLSearchParams();
    params.set("playlist_instance_id", playlistId);
    if (startParam !== "") params.set("start", startParam);
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    if (ctaAudience !== "") params.set("aud", ctaAudience);
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
        setData(payload.data);
      })
      .catch((err) => {
        setError(err?.message || "Failed to load playlist");
      })
      .finally(() => setLoading(false));
  }, [playlistId, start, searchParams, addCtaGroup, ctaAudience]);



  useEffect(() => {
    setPlaybackEnded(endParam);
    setLikedCount(0);
  }, [data?.playlist_instance_id, endParam]);

  function handleExit() {
    navigate("/");
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

  const paletteItems = useMemo(() => {
    const items = data?.items || [];
    return items.filter((item) => {
      const type = (item?.type || "normal").toLowerCase();
      if (type === "intro" || type === "before" || type === "text") return false;
      if (item?.exclude_from_thumbs) return false;
      return Boolean(item?.ap_id) || Boolean(item?.palette_hash);
    });
  }, [data?.items]);

  const paletteCount = paletteItems.length;

  function isCtaVisible(cta) {
    if (!cta) return false;
    if (cta.enabled === false) return false;

    switch (cta.key) {
      case "replay_liked":
        return !data?.hide_stars && likedCount > 0 && paletteCount > 1;
      case "replay_filtered":
        if (cta?.params?.filter === "liked") {
          return !data?.hide_stars && likedCount > 0 && paletteCount > 1;
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
      } catch (err) {
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
      params: parsedParams,
    };
  });
}, [data?.ctas, psiParam, thumbsEnabled, demoEnabled, ctaAudience]);

function resolveVariant(raw, isBack = false) {
  if (!raw) return isBack ? "link" : undefined;
  const normalized = String(raw).toLowerCase();
  if (normalized === "anchor" || normalized === "link") return "link";
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
  if (requireAud && String(audParam || "").toLowerCase() !== String(requireAud).toLowerCase()) return false;
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
    if (!data || !visibleCTAs.length) {
      setWatchNextCta(null);
      return;
    }
    const baseCta = visibleCTAs.find((cta) => (cta?.key || "") === "watch_next");
    if (!baseCta) {
      setWatchNextCta(null);
      return;
    }

    const setId =
      Number(baseCta?.params?.playlist_instance_set_id || baseCta?.params?.set_id) ||
      Number((data?.playlist_instance_set_ids || [])[0]) ||
      3;
    if (!setId) {
      setWatchNextCta(null);
      return;
    }

    let cancelled = false;
    const load = async () => {
      try {
        const res = await fetch(`/api/v2/playlist-instance-sets/get.php?id=${setId}&_=${Date.now()}`, {
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
        const seen = readWatchNextSeen(setId);
        const nextItem = playlistItems.find((item) => {
          const pid = Number(item.playlist_instance_id);
          if (!pid || pid === currentId) return false;
          return !seen.includes(pid);
        });

        if (!nextItem) {
          setWatchNextCta(null);
          return;
        }

        const resolvedTitle = nextItem.title || baseCta?.params?.title || "Next Playlist";
        const resolvedSubtitle = baseCta?.params?.subtitle || baseCta?.params?.dek || "";
        const resolvedThumb = nextItem.photo_url || "";
        const resolved = {
          ...baseCta,
          key: "watch_next",
          params: {
            ...(baseCta?.params || {}),
            playlist_instance_id: Number(nextItem.playlist_instance_id),
            title: resolvedTitle,
            subtitle: resolvedSubtitle,
            thumbnail_url: resolvedThumb,
          },
          data: {
            ...(baseCta?.data || {}),
            playlist_instance_id: Number(nextItem.playlist_instance_id),
          },
        };
        setWatchNextCta(resolved);

        if (playbackEnded && currentId && setItemIds.includes(currentId)) {
          markWatchNextSeen(setId, currentId);
        }
      } catch (err) {
        if (!cancelled) {
          setWatchNextCta(null);
        }
      }
    };
    load();
    return () => {
      cancelled = true;
    };
  }, [data, visibleCTAs, playbackEnded]);

  if (loading) return null;
  if (error) return <div className="player-error">{error}</div>;

  return (
    <div className="player-page">
      <div className="player-frame">
        <Player
          ref={playerRef}
          slides={data?.items || []}
          startIndex={data?.start_index ?? 0}
          playlistInstanceId={playlistId}
          hideStars={Boolean(data?.hide_stars)}
          onAbort={handleExit}
          onLikeChange={({ likedCount: nextCount }) => setLikedCount(nextCount)}
          onPlaybackEnd={({ likedCount: nextCount }) => {
              setLikedCount(nextCount);
              setPlaybackEnded(true);
            }}
        />
        {playbackEnded && (
          <PlayerEndScreen onExit={handleExit}>
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
