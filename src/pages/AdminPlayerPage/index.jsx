import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import Player from "@components/Player";
import PlayerEndScreen from "@components/Player/PlayerEndScreen";
import CTALayout from "@components/cta/CTALayout";
import { SHARE_FOLDER } from "@helpers/config";
import { buildCtaHandlers, getCtaKey } from "@helpers/ctaActions";
import "./admin-player.css";

export default function AdminPlayerPage() {
  const { playlistId, start } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [likedCount, setLikedCount] = useState(0);
  const [playbackEnded, setPlaybackEnded] = useState(false);
  const playerRef = useRef(null);
  const firstNonIntroIndex = useMemo(() => {
    const items = data?.items || [];
    const index = items.findIndex((item) => {
      const type = (item?.type || "normal").toLowerCase();
      return type !== "intro" && type !== "text";
    });
    return index >= 0 ? index : 0;
  }, [data?.items]);

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

  const ctaHandlers = useMemo(() => (
    buildCtaHandlers({
      data,
      shareFolder: SHARE_FOLDER,
      playerRef,
      setPlaybackEnded,
      firstNonIntroIndex,
      navigate,
    })
  ), [data, firstNonIntroIndex, navigate]);

  useEffect(() => {
    if (!playlistId) {
      setError("Missing playlist instance id");
      setLoading(false);
      return;
    }
    const startParam = start ?? searchParams.get("start") ?? "";
    const modeParam = searchParams.get("mode") ?? "";
    const addCtaGroup = searchParams.get("add_cta_group") ?? "";
    const params = new URLSearchParams();
    params.set("playlist_instance_id", playlistId);
    if (startParam !== "") params.set("start", startParam);
    if (modeParam !== "") params.set("mode", modeParam);
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
        setData(payload.data);
      })
      .catch((err) => {
        setError(err?.message || "Failed to load playlist");
      })
      .finally(() => setLoading(false));
  }, [playlistId, start, searchParams]);

  useEffect(() => {
    setPlaybackEnded(false);
    setLikedCount(0);
  }, [data?.playlist_instance_id]);

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

      return {
        cta_id: cta?.cta_id ?? `${key || "cta"}-${index}`,
        label: cta?.label ?? "",
        key,
        enabled: cta?.enabled ?? true,
        variant: cta?.variant ?? parsedParams.variant,
        display_mode: cta?.display_mode ?? parsedParams.display_mode,
        icon: cta?.icon ?? parsedParams.icon,
        params: parsedParams,
      };
    });
  }, [data?.ctas]);

  const visibleCTAs = useMemo(() => {
    return ctas.filter((cta) => {
      if (!cta || cta.enabled === false) return false;
      if (cta.key === "replay_liked") return !data?.hide_stars && likedCount > 0 && paletteCount > 1;
      if (cta.key === "replay_filtered") {
        if (cta?.params?.filter === "liked") {
          return !data?.hide_stars && likedCount > 0 && paletteCount > 1;
        }
        return true;
      }
      if (cta.key === "to_thumbs") return paletteCount > 1;
      if (cta.key === "to_palette") return paletteCount === 1;
      return true;
    });
  }, [ctas, likedCount, paletteCount]);

  const { primaryCTAs, linkCTAs } = useMemo(() => {
    return {
      primaryCTAs: visibleCTAs.filter((cta) => cta?.variant !== "link"),
      linkCTAs: visibleCTAs.filter((cta) => cta?.variant === "link"),
    };
  }, [visibleCTAs]);

  return (
    <div className="admin-player-page">
      <div className="admin-player-header">
        <div className="admin-player-title">Player Preview</div>
        <div className="admin-player-subtitle">Playlist Instance {playlistId || "—"}</div>
      </div>
      <div className="admin-player-stage">
        <div className="admin-player-phone">
          {loading && <div className="admin-player-status">Loading…</div>}
          {error && <div className="admin-player-status error">{error}</div>}
          {!loading && !error && (
            <>
              <Player
                ref={playerRef}
                slides={data?.items || []}
                startIndex={data?.start_index ?? 0}
                hideStars={Boolean(data?.hide_stars)}
                onAbort={() => navigate(-1)}
                onLikeChange={({ likedCount: nextCount }) => setLikedCount(nextCount)}
                onPlaybackEnd={({ likedCount: nextCount }) => {
                  setLikedCount(nextCount);
                  setPlaybackEnded(true);
                }}
                embedded
              />
              {playbackEnded && (
                <PlayerEndScreen showBranding={false} onExit={() => navigate(-1)}>
                  {(primaryCTAs.length > 0 || linkCTAs.length > 0) && (
                    <>
                      {primaryCTAs.length > 0 && (
                        <CTALayout
                          layout="stacked"
                          ctas={primaryCTAs}
                          onCtaClick={(cta) => {
                            const key = getCtaKey(cta);
                            if (!key) return;
                            ctaHandlers[key]?.(cta);
                          }}
                        />
                      )}
                      {linkCTAs.length > 0 && (
                        <div className="player-end-links">
                          <CTALayout
                            layout="stacked"
                            ctas={linkCTAs}
                            onCtaClick={(cta) => {
                              const key = getCtaKey(cta);
                              if (!key) return;
                              ctaHandlers[key]?.(cta);
                            }}
                          />
                        </div>
                      )}
                    </>
                  )}
                </PlayerEndScreen>
              )}
              <div className="admin-player-likes" aria-hidden="true" />
            </>
          )}
        </div>
      </div>
    </div>
  );
}
