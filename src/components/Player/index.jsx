import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from "react";
import { getIntroLayout } from "../PlayerIntroLayouts/registry";
import "./player.css";

const Player = forwardRef(function Player({
  slides = [],
  startIndex = 0,
  playlistInstanceId,
  onAbort,
  onLikeChange,
  onPlaybackEnd,
  hideStars = false,
  embedded = false,
}, ref) {
  const allItems = Array.isArray(slides) ? slides : [];


  const safeStart = Math.min(
    Math.max(0, Number(startIndex) || 0),
    Math.max(0, allItems.length - 1)
  );


  const [activeIndex, setActiveIndex] = useState(safeStart);
  const [playbackState, setPlaybackState] = useState("playing"); // "playing" | "end"
  const [playbackMode, setPlaybackMode] = useState("all"); // "all" | "liked"
  const [titleIndex, setTitleIndex] = useState(safeStart);
  const [titleVisible, setTitleVisible] = useState(false);
  const [imageLoaded, setImageLoaded] = useState(false);
  const [fadeReady, setFadeReady] = useState(false);
  const [prevIndex, setPrevIndex] = useState(null);
  const [isFading, setIsFading] = useState(false);
  const [titleFull, setTitleFull] = useState(false);
  const [titlePos, setTitlePos] = useState({ left: 0, top: 0 });
  const [starPos, setStarPos] = useState({ left: 0, top: 0 });
  const [likedSet, setLikedSet] = useState(() => new Set());
  const [isPortraitMobile, setIsPortraitMobile] = useState(false);
  const [cacheBustEnabled, setCacheBustEnabled] = useState(true);
  const [showAdvanceHint, setShowAdvanceHint] = useState(true);
  const didInitRef = useRef(false);
  const endEmitRef = useRef(null);
  const currentImgRef = useRef(null);
  const stageRef = useRef(null);
  const titleRef = useRef(null);
  const didLikeInteractRef = useRef(false);
  const cacheBustRef = useRef(Date.now());

  function handleExit() {
    if (onAbort) {
      onAbort();
      return;
    }
    if (typeof window !== "undefined") {
      if (window.history.length > 1) {
        window.history.back();
      } else {
        window.location.href = "/";
      }
    }
  }

  useEffect(() => {
    if (!didInitRef.current) {
      didInitRef.current = true;
      return;
    }
    setPlaybackMode("all");
    setPlaybackState("playing");
    setActiveIndex(safeStart);
    setTitleIndex(safeStart);
    setTitleVisible(false);
    setImageLoaded(false);
    setFadeReady(false);
    setPrevIndex(null);
    setIsFading(false);
    setTitleFull(false);
    setLikedSet(readLikedSet(playlistInstanceId));
    setShowAdvanceHint(true);
    didLikeInteractRef.current = false;
  }, [safeStart, playlistInstanceId]);

  useEffect(() => {
    cacheBustRef.current = Date.now();
    setCacheBustEnabled(true);
    setLikedSet(readLikedSet(playlistInstanceId));
    setShowAdvanceHint(true);
    didLikeInteractRef.current = false;
  }, [slides, playlistInstanceId]);

  useEffect(() => {
    writeLikedSet(playlistInstanceId, likedSet);
  }, [likedSet, playlistInstanceId]);

  useEffect(() => {
    if (playbackState !== "playing") return;
    if (activeIndex !== 0) {
      if (showAdvanceHint) setShowAdvanceHint(false);
      return;
    }
    if (!showAdvanceHint) return;
    const timer = setTimeout(() => setShowAdvanceHint(false), 2200);
    return () => clearTimeout(timer);
  }, [activeIndex, playbackState, showAdvanceHint]);

  function isItemStarrable(item) {
    if (hideStars) return false;
    if (!item) return false;
    const itemType = (item.type || "normal").toLowerCase();
    if (itemType === "intro" || itemType === "text") return false;
    return item.star === true || item.star === 1 || item.star === "1";
  }

  function getItemKey(item) {
    if (!item) return null;
    if (!isItemStarrable(item)) return null;
    const apId = item.ap_id ?? null;
    if (apId === null || apId === undefined || apId === "") return null;
    return String(apId);
  }

  const playItems = useMemo(() => {
    if (playbackMode !== "liked") return allItems;
    return (allItems || []).filter((item) => {
      const key = getItemKey(item);
      return key && likedSet.has(key);
    });
  }, [allItems, playbackMode, likedSet]);

useEffect(() => {
  if (!onLikeChange) return;
  if (!didLikeInteractRef.current) return;
  onLikeChange({ likedCount: likedSet.size });
}, [likedSet.size, onLikeChange]);


  function handleAdvance() {
    if (showAdvanceHint) setShowAdvanceHint(false);
    if (playbackState !== "playing") return;
    if (!playItems.length) return;

    if (activeIndex >= playItems.length - 1) {
      setPlaybackState("end");
      return;
    }

    const nextIndex = activeIndex + 1;
    setPrevIndex(activeIndex);
    setActiveIndex(nextIndex);
    setTitleVisible(false);
    setImageLoaded(false);
    setFadeReady(false);
    setTitleIndex(nextIndex);
    setIsFading(true);
    setTitleFull(false);
  }

function startPlayback(nextMode, nextIndex = 0) {
  setPlaybackMode(nextMode);
  setPlaybackState("playing");
  setTitleVisible(false);
  setImageLoaded(false);
  setFadeReady(false);
  setTitleIndex(nextIndex);
  setPrevIndex(null);
  setIsFading(false);
  setTitleFull(false);
  setActiveIndex(nextIndex);
}


  useImperativeHandle(ref, () => ({
    replay: ({ likedOnly = false, startIndex: nextIndex = 0 } = {}) => {
      setCacheBustEnabled(false);
      const baseItems = likedOnly
        ? (allItems || []).filter((item) => {
            const key = getItemKey(item);
            return key && likedSet.has(key);
          })
        : allItems;
      const clampedIndex = Math.min(
        Math.max(0, Number(nextIndex) || 0),
        Math.max(0, baseItems.length - 1)
      );
      startPlayback(likedOnly ? "liked" : "all", clampedIndex);
    },
  }));

  const currentIndex = activeIndex;
  const currentItem = playItems[currentIndex] || null;
  const prevItem = prevIndex != null ? playItems[prevIndex] || null : null;
  const title = playItems[titleIndex]?.title || "";
  const subtitle = (playItems[titleIndex]?.subtitle || "").trim();
  const titleMode = playItems[titleIndex]?.title_mode || "animated";
  const subtitleOffset = subtitle ? 0 : 18;
  const currentType = (currentItem?.type || "normal").toLowerCase();
  const isIntro = currentType === "intro" || currentType === "text";
  const introNoImage = isIntro && !currentItem?.image_url;
  const introLayoutKey = isIntro
    ? ((currentItem?.layout || "").toString().toLowerCase().trim() || (currentType === "text" ? "text" : "default"))
    : null;
  const IntroRenderer = isIntro ? getIntroLayout(introLayoutKey) : null;
  const isStarrable = isItemStarrable(currentItem);
  const currentKey = getItemKey(currentItem);
  const isLiked = currentKey ? likedSet.has(currentKey) : false;

  function withCacheBust(url) {
    if (!url) return url;
    if (!cacheBustEnabled) return url;
    const sep = url.includes("?") ? "&" : "?";
    return `${url}${sep}v=${cacheBustRef.current}`;
  }

  function toggleLike(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    if (isIntro || hideStars) return;
    if (!currentKey) return;
    setLikedSet((prev) => {
      const next = new Set(prev);
      if (next.has(currentKey)) {
        next.delete(currentKey);
      } else {
        next.add(currentKey);
      }
      didLikeInteractRef.current = true;
      return next;
    });
  }

  useEffect(() => {
    if (!playItems.length) {
      setPlaybackState("end");
      return;
    }
    if (activeIndex >= playItems.length) {
      setActiveIndex(0);
      setTitleIndex(0);
      setPrevIndex(null);
      setIsFading(false);
    }
  }, [playItems, activeIndex]);

  useEffect(() => {
    if (playbackState !== "end") return;
    if (!onPlaybackEnd) return;
    const signature = `${playbackMode}:${likedSet.size}`;
    if (endEmitRef.current === signature) return;
    endEmitRef.current = signature;
    onPlaybackEnd({ likedCount: likedSet.size });
  }, [playbackState, onPlaybackEnd, likedSet.size]);

  useEffect(() => {
    if (playbackState === "end") return;
    endEmitRef.current = null;
  }, [playbackState]);

  function updateOverlayPositions(imgEl, stageEl) {
    if (!imgEl || !stageEl) return;
    const stageRect = stageEl.getBoundingClientRect();
    const naturalW = imgEl.naturalWidth || 0;
    const naturalH = imgEl.naturalHeight || 0;
    if (!naturalW || !naturalH) return;
    const scale = Math.min(stageRect.width / naturalW, stageRect.height / naturalH);
    const renderW = naturalW * scale;
    const renderH = naturalH * scale;
    const leftOffset = (stageRect.width - renderW) / 2;
    const bottomOffset = (stageRect.height - renderH) / 2;
    setTitleFull(renderW >= stageRect.width * 0.98);
    const baseLeft = Math.max(0, leftOffset + 12);
    const baseTop = Math.max(0, bottomOffset + renderH - 6);
    const titleEl = titleRef.current;
    if (titleEl) {
      const titleRect = titleEl.getBoundingClientRect();
      const titleWidth = titleRect.width;
      const titleHeight = titleRect.height;
      const maxLeft = Math.max(0, leftOffset + renderW - titleWidth - 8);
      const portraitTop = bottomOffset + renderH + 8;
      const maxTop = Math.max(0, stageRect.height - titleHeight - 8);
      setTitlePos({
        left: Math.min(baseLeft, maxLeft),
        top: isPortraitMobile ? Math.min(portraitTop, maxTop) : Math.max(0, baseTop - titleHeight),
      });
    } else {
      const portraitTop = bottomOffset + renderH + 8;
      const maxTop = Math.max(0, stageRect.height - 24);
      setTitlePos({
        left: baseLeft,
        top: isPortraitMobile ? Math.min(portraitTop, maxTop) : Math.max(0, baseTop - 24),
      });
    }
    const starSize = 40;
    const starLeft = Math.max(0, leftOffset + renderW - starSize - 12);
    const starTop = Math.max(0, bottomOffset + renderH - starSize - 12);
    setStarPos({ left: starLeft, top: starTop });
  }

  useEffect(() => {
    if (!imageLoaded) return;
    if (titleMode === "static") {
      setTitleVisible(true);
      return undefined;
    }
    const timer = setTimeout(() => {
      setTitleVisible(true);
    }, 180);
    return () => clearTimeout(timer);
  }, [imageLoaded, currentIndex, titleMode]);

  useEffect(() => {
    if (!isIntro) return;
    if (currentItem?.image_url) return;
    setImageLoaded(true);
    setFadeReady(true);
  }, [isIntro, currentIndex, currentItem?.image_url]);


  useEffect(() => {
    if (!isFading || !fadeReady) return;
    const timer = setTimeout(() => {
      setPrevIndex(null);
      setIsFading(false);
    }, 1700);
    return () => clearTimeout(timer);
  }, [isFading, fadeReady]);

  useEffect(() => {
    function handleResize() {
      if (typeof window !== "undefined") {
        const portrait = window.matchMedia("(max-width: 768px) and (orientation: portrait)").matches;
        setIsPortraitMobile(portrait);
      }
      const img = currentImgRef.current;
      const stage = stageRef.current;
      updateOverlayPositions(img, stage);
    }
    window.addEventListener("resize", handleResize);
    window.addEventListener("orientationchange", handleResize);
    handleResize();
    return () => {
      window.removeEventListener("resize", handleResize);
      window.removeEventListener("orientationchange", handleResize);
    };
  }, [currentIndex, imageLoaded, titleVisible, isPortraitMobile]);

  useEffect(() => {
    const img = currentImgRef.current;
    if (!img || imageLoaded) return;
    if (img.complete && img.naturalWidth) {
      setImageLoaded(true);
      requestAnimationFrame(() => setFadeReady(true));
      updateOverlayPositions(img, stageRef.current);
    }
  }, [currentIndex, playItems, imageLoaded]);

  return (
    <div className={`player-root${embedded ? " player-embedded" : ""}`}>
      <button
        className="player-exit"
        type="button"
        onClick={handleExit}
        aria-label="Exit player"
      >
        ×
      </button>

      <div
        className="player-stage"
        ref={stageRef}
        onClick={handleAdvance}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            handleAdvance();
          }
        }}
      >
        <div className="player-image-frame">
          {prevItem?.image_url && (
            <img
              key={`prev-${prevIndex}-${prevItem.image_url}`}
              src={withCacheBust(prevItem.image_url)}
              alt=""
              className={`player-image is-prev${fadeReady ? " fade-out is-ready" : ""}`}
            />
          )}
          {currentItem?.image_url && (
            <img
              key={`cur-${currentIndex}-${currentItem.image_url}`}
              src={withCacheBust(currentItem.image_url)}
              alt={currentItem.title || ""}
              className={`player-image is-current${isFading ? " fade-in" : ""}${fadeReady ? " is-ready" : ""}`}
              ref={currentImgRef}
              onLoad={(e) => {
                setImageLoaded(true);
                requestAnimationFrame(() => setFadeReady(true));
                updateOverlayPositions(e.currentTarget, stageRef.current);
              }}
            />
          )}
          {playbackState === "playing" && !isIntro && isStarrable && imageLoaded && (
            <div
              className={`player-like ${isLiked ? "is-liked" : ""}`}
              role="button"
              aria-pressed={isLiked}
              aria-label={isLiked ? "Unlike" : "Like"}
              onPointerDown={(e) => {
                e.preventDefault();
                e.stopPropagation();
              }}
              onPointerUp={(e) => {
                e.preventDefault();
                e.stopPropagation();
              }}
              onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                toggleLike(e);
              }}
              style={{ left: starPos.left, top: starPos.top }}
            >
              <svg
                className="player-like-icon"
                viewBox="0 0 24 24"
                aria-hidden="true"
                focusable="false"
              >
                <path
                  className="player-like-stroke-back"
                  d="M12 2.5l2.9 5.88 6.5.95-4.7 4.58 1.1 6.49L12 17.9l-5.8 3.05 1.1-6.49-4.7-4.58 6.5-.95L12 2.5z"
                />
                <path
                  className="player-like-stroke-front"
                  d="M12 2.5l2.9 5.88 6.5.95-4.7 4.58 1.1 6.49L12 17.9l-5.8 3.05 1.1-6.49-4.7-4.58 6.5-.95L12 2.5z"
                />
              </svg>
            </div>
          )}

          {titleVisible && isIntro && IntroRenderer && (
            <div
              className={`player-title is-static${introNoImage ? " is-intro-full" : ""}`}
              style={
                introNoImage
                  ? undefined
                  : {
                      left: titlePos.left,
                      top: Math.max(0, titlePos.top - subtitleOffset),
                    }
              }
              ref={titleRef}
            >
              <IntroRenderer item={currentItem} />
            </div>
          )}

          {title && titleVisible && !isIntro && (
            <div
              className={`player-title${titleFull ? " is-full" : ""}${titleMode === "static" ? " is-static" : ""}${subtitle ? "" : " no-subtitle"}`}
              style={{
                left: titlePos.left,
                top: Math.max(0, titlePos.top - subtitleOffset),
              }}
              ref={titleRef}
            >
              <span className="player-title-text">{title}</span>
              {subtitle && <span className="player-subtitle-text">{subtitle}</span>}
            </div>
          )}
        </div>
        {showAdvanceHint &&
          playbackState === "playing" &&
          activeIndex === 0 &&
          !isIntro &&
          (imageLoaded || introNoImage) && (
            <div className="player-advance-hint">Tap screen to advance</div>
          )}
      </div>
    </div>
  );
});

function getLikeStorageKey(playlistInstanceId) {
  const id = playlistInstanceId ?? "";
  if (!id) return "";
  return `playlist-liked:${id}`;
}

function readLikedSet(playlistInstanceId) {
  if (typeof window === "undefined") return new Set();
  const key = getLikeStorageKey(playlistInstanceId);
  if (!key) return new Set();
  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) return new Set();
    const data = JSON.parse(raw);
    if (!Array.isArray(data)) return new Set();
    return new Set(data.map((value) => String(value)));
  } catch {
    return new Set();
  }
}

function writeLikedSet(playlistInstanceId, likedSet) {
  if (typeof window === "undefined") return;
  const key = getLikeStorageKey(playlistInstanceId);
  if (!key) return;
  try {
    window.localStorage.setItem(key, JSON.stringify(Array.from(likedSet)));
  } catch {
    // ignore storage errors
  }
}

export default Player;
