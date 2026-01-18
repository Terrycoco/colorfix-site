import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
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
  const modeParam = searchParams.get("mode") ?? "";
  const addCtaGroup = searchParams.get("add_cta_group") ?? "";
  const ctaAudience = searchParams.get("aud") ?? "";
  const endParam = (searchParams.get("end") ?? "") === "1";


  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [likedCount, setLikedCount] = useState(0);
  const [playbackEnded, setPlaybackEnded] = useState(false);
  const playerRef = useRef(null);

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
    if (modeParam !== "") params.set("mode", modeParam);
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
  }, [playlistId, start, searchParams, modeParam, addCtaGroup, ctaAudience]);



  useEffect(() => {
    setPlaybackEnded(endParam);
    setLikedCount(0);
  }, [data?.playlist_instance_id, endParam]);

  function handleExit() {
    if (window.history.length > 1) {
      navigate(-1);
    } else {
      navigate("/");
    }
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
    })
  ), [data, firstNonIntroIndex, handleExit, navigate, ctaAudience]);

  const paletteItems = useMemo(() => {
    const items = data?.items || [];
    return items.filter((item) => {
      const type = (item?.type || "normal").toLowerCase();
      if (type === "intro" || type === "before" || type === "text") return false;
      if (item?.exclude_from_thumbs) return false;
      return Boolean(item?.ap_id);
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

    const key = cta?.key || cta?.action_key || cta?.action || "";

    const variant = resolveVariant(
      cta?.variant ?? parsedParams.variant ?? parsedParams.style,
      key.toLowerCase().includes("back")
    );
    return {
      cta_id: cta?.cta_id ?? `${key || "cta"}-${index}`,
      label: cta?.label ?? "",
      key,
      enabled: cta?.enabled ?? true,
      variant,
      display_mode: cta?.display_mode ?? parsedParams.display_mode,
      icon: cta?.icon ?? parsedParams.icon,
      params: parsedParams,
    };
  });
}, [data?.ctas]);

function resolveVariant(raw, isBack = false) {
  if (!raw) return isBack ? "link" : undefined;
  const normalized = String(raw).toLowerCase();
  if (normalized === "anchor" || normalized === "link") return "link";
  if (normalized === "button") return isBack ? "link" : undefined;
  if (normalized === "primary" || normalized === "secondary" || normalized === "ghost") return normalized;
  return isBack ? "link" : undefined;
}

const visibleCTAs = useMemo(
  () => ctas.filter(isCtaVisible),
  [ctas, likedCount]
);

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
            {visibleCTAs.length > 0 && (
              <CTALayout
                layout="stacked"
                ctas={visibleCTAs}
                onCtaClick={handleCta}
              />
            )}
          </PlayerEndScreen>
        )}
      </div>

    </div>
  );
}
