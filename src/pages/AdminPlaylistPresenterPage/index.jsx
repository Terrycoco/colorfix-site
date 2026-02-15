import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Player from "@components/Player";
import CTALayout from "@components/cta/CTALayout";
import PlayerEndScreen from "@components/Player/PlayerEndScreen";
import { API_FOLDER, SHARE_FOLDER } from "@helpers/config";
import { buildCtaHandlers, getCtaKey } from "@helpers/ctaActions";
import "./admin-playlist-presenter.css";

const LIST_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const PLAYER_URL = "/api/v2/player-playlist.php";
const PLAYER_ROUTE_BASE = "/playlist";
const PRESENTER_MODE = "presenter";

export default function AdminPlaylistPresenterPage() {
  const [tagQuery, setTagQuery] = useState("");
  const [items, setItems] = useState([]);
  const [listLoading, setListLoading] = useState(false);
  const [listError, setListError] = useState("");
  const [activeItem, setActiveItem] = useState(null);
  const [data, setData] = useState(null);
  const [playerLoading, setPlayerLoading] = useState(false);
  const [playerError, setPlayerError] = useState("");
  const [likedCount, setLikedCount] = useState(0);
  const [playbackEnded, setPlaybackEnded] = useState(false);
  const [focusMode, setFocusMode] = useState(false);
  const [mobileRedirecting, setMobileRedirecting] = useState(false);
  const playerRef = useRef(null);
  const stageRef = useRef(null);
  const lastMobileOpenRef = useRef(null);

  const fetchInstances = useCallback(async () => {
    setListLoading(true);
    setListError("");
    try {
      const qs = new URLSearchParams();
      if (tagQuery.trim()) qs.set("tags", tagQuery.trim());
      qs.set("active", "1");
      qs.set("_", Date.now().toString());
      const res = await fetch(`${LIST_URL}?${qs.toString()}`, {
        credentials: "include",
      });
      const payload = await res.json();
      if (!res.ok || !payload?.ok) {
        throw new Error(payload?.error || "Failed to load playlist instances");
      }
      setItems(payload.items || []);
    } catch (err) {
      setListError(err?.message || "Failed to load playlist instances");
    } finally {
      setListLoading(false);
    }
  }, [tagQuery]);

  useEffect(() => {
    fetchInstances();
  }, [fetchInstances]);

  useEffect(() => {
    if (!activeItem?.playlist_instance_id) {
      setData(null);
      setPlayerError("");
      setPlayerLoading(false);
      return;
    }
    const params = new URLSearchParams();
    params.set("playlist_instance_id", String(activeItem.playlist_instance_id));
    params.set("mode", PRESENTER_MODE);
    params.set("_", String(Date.now()));
    setPlayerLoading(true);
    setPlayerError("");
    fetch(`${PLAYER_URL}?${params.toString()}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((payload) => {
        if (!payload?.ok || !payload?.data) {
          throw new Error(payload?.error || "Failed to load playlist");
        }
        setData(payload.data);
        setPlaybackEnded(false);
      })
      .catch((err) => {
        setPlayerError(err?.message || "Failed to load playlist");
      })
      .finally(() => setPlayerLoading(false));
  }, [activeItem?.playlist_instance_id]);

  useEffect(() => {
    setPlaybackEnded(false);
    setLikedCount(0);
  }, [data?.playlist_instance_id]);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (!activeItem?.playlist_instance_id) {
      setMobileRedirecting(false);
      lastMobileOpenRef.current = null;
      return;
    }
    if (window.innerWidth > 900) return;
    const id = activeItem.playlist_instance_id;
    if (lastMobileOpenRef.current === id) return;
    lastMobileOpenRef.current = id;
    setMobileRedirecting(true);
    window.location.href = `${PLAYER_ROUTE_BASE}/${id}?mode=${encodeURIComponent(PRESENTER_MODE)}`;
  }, [activeItem?.playlist_instance_id]);

  const firstNonIntroIndex = useMemo(() => {
    const list = data?.items || [];
    const index = list.findIndex((item) => {
      const type = (item?.type || "normal").toLowerCase();
      return type !== "intro" && type !== "text";
    });
    return index >= 0 ? index : 0;
  }, [data?.items]);

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
    const palettes = (data?.items || []).filter((item) => {
      const type = (item?.type || "normal").toLowerCase();
      if (type === "intro" || type === "before" || type === "text") return false;
      if (item?.exclude_from_thumbs) return false;
      return Boolean(item?.ap_id) || Boolean(item?.palette_hash);
    });
    const paletteCount = palettes.length;
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
  }, [ctas, likedCount, data?.items]);

  const { primaryCTAs, linkCTAs } = useMemo(() => {
    return {
      primaryCTAs: visibleCTAs.filter((cta) => cta?.variant !== "link"),
      linkCTAs: visibleCTAs.filter((cta) => cta?.variant === "link"),
    };
  }, [visibleCTAs]);

  const ctaHandlers = useMemo(() => (
    buildCtaHandlers({
      data,
      shareFolder: SHARE_FOLDER,
      playerRef,
      setPlaybackEnded,
      firstNonIntroIndex,
    })
  ), [data, firstNonIntroIndex]);

  const handleCta = useCallback((cta) => {
    const key = getCtaKey(cta);
    if (!key) return;
    ctaHandlers[key]?.(cta);
  }, [ctaHandlers]);

  function handlePlay(item) {
    setActiveItem(item);
  }

  function handleFullscreen() {
    if (!stageRef.current?.requestFullscreen) {
      if (activeItem?.playlist_instance_id) {
        window.open(
          `${PLAYER_ROUTE_BASE}/${activeItem.playlist_instance_id}?mode=${encodeURIComponent(PRESENTER_MODE)}`,
          "_blank",
          "noopener"
        );
      }
      return;
    }
    stageRef.current.requestFullscreen().catch(() => {});
  }

  function handleExitFullscreen() {
    if (document.fullscreenElement && document.exitFullscreen) {
      document.exitFullscreen().catch(() => {});
    }
  }

  const isFullscreen = typeof document !== "undefined" && !!document.fullscreenElement;

  function handleExitPlayer() {
    handleExitFullscreen();
    setActiveItem(null);
    setData(null);
    setPlaybackEnded(false);
    setLikedCount(0);
  }

  const listItems = useMemo(() => {
    if (!items.length) return [];
    if (!tagQuery.trim()) return items;
    const tags = tagQuery
      .split(/[|,]/)
      .map((t) => t.trim().toLowerCase())
      .filter(Boolean);
    return items.filter((item) => {
      const haystack = `${item.instance_name || ""} ${item.instance_notes || ""}`.toLowerCase();
      if (tags.length) {
        for (const tag of tags) {
          if (!haystack.includes(tag)) return false;
        }
      }
      return true;
    });
  }, [items, tagQuery]);

  return (
    <div className={`playlist-presenter${focusMode ? " is-focus" : ""}`}>
      <header className="presenter-header">
        <div>
          <div className="presenter-title">Customer Playlist Presenter</div>
          <div className="presenter-subtitle">Search by tag or name, then play full screen.</div>
        </div>
        <div className="presenter-actions">
          <button className="btn" type="button" onClick={() => setFocusMode((v) => !v)}>
            {focusMode ? "Show List" : "Focus Mode"}
          </button>
          <button className="btn primary" type="button" onClick={handleFullscreen} disabled={!activeItem}>
            Fullscreen
          </button>
          <button
            className="btn hide-on-mobile"
            type="button"
            onClick={handleExitFullscreen}
            disabled={!isFullscreen}
          >
            Exit Fullscreen
          </button>
        </div>
      </header>

      <div className="presenter-body">
        <aside className="presenter-sidebar">
          <div className="presenter-search">
            <label>
              Tags
              <input
                type="text"
                placeholder="e.g., adobe, cottage | navy"
                value={tagQuery}
                onChange={(e) => setTagQuery(e.target.value)}
              />
            </label>
            <div className="presenter-search-actions">
              <button className="btn" type="button" onClick={fetchInstances}>
                Refresh
              </button>
            </div>
          </div>

          {listLoading && <div className="presenter-status">Loading playlists…</div>}
          {listError && <div className="presenter-status error">{listError}</div>}

          <div className="presenter-list">
            {listItems.map((item) => {
              const isActive = activeItem?.playlist_instance_id === item.playlist_instance_id;
              return (
                <button
                  key={item.playlist_instance_id}
                  type="button"
                  className={`presenter-card${isActive ? " is-active" : ""}`}
                  onClick={() => handlePlay(item)}
                >
                  <div className="presenter-card-title">{item.instance_name || "Untitled"}</div>
                  <div className="presenter-card-meta">
                    #{item.playlist_instance_id} • Playlist {item.playlist_id}
                  </div>
                  {item.instance_notes && (
                    <div className="presenter-card-notes">{item.instance_notes}</div>
                  )}
                </button>
              );
            })}
          </div>
        </aside>

        <main className="presenter-stage">
          <div className="presenter-stage-header">
            <div className="presenter-stage-title">
              {activeItem?.instance_name || "Select a playlist to preview"}
            </div>
            {activeItem && (
              <button
                className="btn ghost"
                type="button"
                onClick={() => window.open(
                  `${PLAYER_ROUTE_BASE}/${activeItem.playlist_instance_id}?mode=${encodeURIComponent(PRESENTER_MODE)}`,
                  "_blank",
                  "noopener"
                )}
              >
                Open in New Tab
              </button>
            )}
          </div>
          <div className="presenter-player-frame" ref={stageRef}>
            {playerLoading && <div className="presenter-status">Loading playlist…</div>}
            {playerError && <div className="presenter-status error">{playerError}</div>}
            {!playerLoading && !playerError && data && (
              <>
                <Player
                  ref={playerRef}
                  slides={data?.items || []}
                  startIndex={data?.start_index ?? 0}
                  hideStars={Boolean(data?.hide_stars)}
                  onAbort={handleExitPlayer}
                  onLikeChange={({ likedCount: nextCount }) => setLikedCount(nextCount)}
                  onPlaybackEnd={({ likedCount: nextCount }) => {
                    setLikedCount(nextCount);
                    setPlaybackEnded(true);
                  }}
                />
                {playbackEnded && (
                  <PlayerEndScreen onExit={handleExitPlayer}>
                    {(primaryCTAs.length > 0 || linkCTAs.length > 0) && (
                      <>
                        {primaryCTAs.length > 0 && (
                          <CTALayout
                            layout="stacked"
                            ctas={primaryCTAs}
                            onCtaClick={handleCta}
                          />
                        )}
                        {linkCTAs.length > 0 && (
                          <div className="player-end-links">
                            <CTALayout
                              layout="stacked"
                              ctas={linkCTAs}
                              onCtaClick={handleCta}
                            />
                          </div>
                        )}
                      </>
                    )}
                  </PlayerEndScreen>
                )}
              </>
            )}
          </div>
        </main>
        {mobileRedirecting && (
          <div className="presenter-mobile-note">
            Opening full-screen player…
          </div>
        )}
      </div>
    </div>
  );
}
