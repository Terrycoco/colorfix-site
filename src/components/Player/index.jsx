import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from "react";
import { getIntroLayout } from "../PlayerIntroLayouts/registry";
import { extractAssetId, fetchAssetUrl, isAssetRef, parsePhotoRef } from "@helpers/assetImage";
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
  const [titleReady, setTitleReady] = useState(false);
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
  const [currentImageUrl, setCurrentImageUrl] = useState("");
  const [prevImageUrl, setPrevImageUrl] = useState("");
  const didInitRef = useRef(false);
  const endEmitRef = useRef(null);
  const currentImgRef = useRef(null);
  const stageRef = useRef(null);
  const titleRef = useRef(null);
  const didLikeInteractRef = useRef(false);
  const cacheBustRef = useRef(Date.now());

function queueFadeReady(img, stageEl) {
    // Ensure at least one paint happens at opacity 0 before we flip to ready.
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        setFadeReady(true);
        updateOverlayPositions(img, stageEl);
      });
    });
  }

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

  function handleBack(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    if (playbackState !== "playing") return;
    if (activeIndex <= 0) return;
    const prev = activeIndex - 1;
    setPrevIndex(activeIndex);
    if (currentImageUrl) {
      setPrevImageUrl(currentImageUrl);
    }
    setActiveIndex(prev);
    setTitleVisible(false);
    setTitleReady(false);
    setImageLoaded(false);
    setFadeReady(false);
    setTitleIndex(prev);
    setIsFading(true);
    setTitleFull(false);
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
    setTitleReady(false);
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

  const isPaletteItem = (item) => {
    if (!item) return false;
    return Boolean(item.ap_id || item.palette_hash);
  };

  useEffect(() => {
    let cancelled = false;
    const seen = new Set();

    function preload(url) {
      if (!url || seen.has(url)) return;
      seen.add(url);
      const img = new Image();
      img.decoding = "async";
      img.src = withCacheBust(url);
    }

    (slides || []).forEach((item) => {
      const value = item?.image_url || "";
      if (isPaletteItem(item)) return;
      if (!value) return;
      const parsed = parsePhotoRef(value);
      if (parsed.url) {
        preload(parsed.url);
        return;
      }
      if (!isAssetRef(value)) {
        preload(value);
        return;
      }
      const assetId = extractAssetId(value);
      fetchAssetUrl(assetId).then((url) => {
        if (!cancelled) preload(url);
      });
    });

    return () => {
      cancelled = true;
    };
  }, [slides, cacheBustEnabled]);


  useEffect(() => {
    writeLikedSet(playlistInstanceId, likedSet);
  }, [likedSet, playlistInstanceId]);

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
    if (currentImageUrl) {
      setPrevImageUrl(currentImageUrl);
    }
    setActiveIndex(nextIndex);
    setTitleVisible(false);
    setTitleReady(false);
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
  setTitleReady(false);
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
  const transitionMode = (currentItem?.transition || "animation").toLowerCase();
  const subtitleOffset = subtitle ? 0 : 18;
  const currentType = (currentItem?.type || "normal").toLowerCase();
  const isIntro = currentType === "intro" || currentType === "text";
  const introNoImage = isIntro && !currentImageUrl;
  const introLayoutKey = isIntro
    ? ((currentItem?.layout || "").toString().toLowerCase().trim() || (currentType === "text" ? "text" : "default"))
    : null;
  const IntroRenderer = isIntro ? getIntroLayout(introLayoutKey) : null;
  const isStarrable = isItemStarrable(currentItem);
  const currentKey = getItemKey(currentItem);
  const isLiked = currentKey ? likedSet.has(currentKey) : false;

  useEffect(() => {
    let cancelled = false;
    const value = currentItem?.image_url || "";
    const parsed = parsePhotoRef(value);
    if (parsed.url) {
      setCurrentImageUrl(parsed.url);
      return () => { cancelled = true; };
    }
    if (!value) {
      setCurrentImageUrl("");
      return () => {};
    }
    if (!isAssetRef(value)) {
      setCurrentImageUrl(value);
      return () => {};
    }
    const assetId = extractAssetId(value);
    fetchAssetUrl(assetId).then((url) => {
      if (!cancelled) setCurrentImageUrl(url || "");
    });
    return () => { cancelled = true; };
  }, [currentItem?.image_url]);

  useEffect(() => {
    let cancelled = false;
    const value = prevItem?.image_url || "";
    const parsed = parsePhotoRef(value);
    if (parsed.url) {
      setPrevImageUrl(parsed.url);
      return () => { cancelled = true; };
    }
    if (!value) {
      setPrevImageUrl("");
      return () => {};
    }
    if (!isAssetRef(value)) {
      setPrevImageUrl(value);
      return () => {};
    }
    const assetId = extractAssetId(value);
    fetchAssetUrl(assetId).then((url) => {
      if (!cancelled) setPrevImageUrl(url || "");
    });
    return () => { cancelled = true; };
  }, [prevItem?.image_url]);

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
      setTitleReady(false);
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
    const isTallImage = renderH >= stageRect.height * 0.9;
    const titleEl = titleRef.current;
    if (titleEl) {
      const titleRect = titleEl.getBoundingClientRect();
      const titleWidth = titleRect.width;
      const titleHeight = titleRect.height;
      const maxLeft = Math.max(0, leftOffset + renderW - titleWidth - 8);
      const portraitTop = isTallImage
        ? Math.max(0, stageRect.height - titleHeight - 12)
        : bottomOffset + renderH + 8;
      const maxTop = Math.max(0, stageRect.height - titleHeight - 8);
      setTitlePos({
        left: Math.min(baseLeft, maxLeft),
        top: isPortraitMobile ? Math.min(portraitTop, maxTop) : Math.max(0, baseTop - titleHeight),
      });
    } else {
      const portraitTop = isTallImage
        ? Math.max(0, stageRect.height - 24 - 12)
        : bottomOffset + renderH + 8;
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
    if (!imageLoaded || !fadeReady) return;
    if (titleMode === "static") {
      setTitleVisible(true);
      setTitleReady(true);
      return undefined;
    }
    const timer = setTimeout(() => {
      setTitleVisible(true);
      requestAnimationFrame(() => {
        updateOverlayPositions(currentImgRef.current, stageRef.current);
        setTitleReady(true);
      });
    }, 120);
    return () => clearTimeout(timer);
  }, [imageLoaded, fadeReady, currentIndex, titleMode]);

  useEffect(() => {
    if (!titleVisible || !titleReady) return;
    const titleEl = titleRef.current;
    const stageEl = stageRef.current;
    const imgEl = currentImgRef.current;
    if (!titleEl || !stageEl) return;
    if (typeof ResizeObserver === "undefined") return;
    const ro = new ResizeObserver(() => {
      updateOverlayPositions(imgEl, stageEl);
    });
    ro.observe(titleEl);
    return () => ro.disconnect();
  }, [titleVisible, titleReady, currentIndex]);

  useEffect(() => {
    if (imageLoaded && fadeReady) return;
    setTitleVisible(false);
    setTitleReady(false);
  }, [imageLoaded, fadeReady, currentIndex]);

  useEffect(() => {
    if (!isIntro) return;
    if (currentImageUrl) return;
    setImageLoaded(true);
    setFadeReady(true);
  }, [isIntro, currentIndex, currentImageUrl]);


  useEffect(() => {
    if (!isFading || !fadeReady || !imageLoaded) return;
    // Match the CSS dissolve timings (fade-in 1400ms + fade-out 600ms).
    const timer = setTimeout(() => {
      setPrevIndex(null);
      setIsFading(false);
    }, 2000);
    return () => clearTimeout(timer);
  }, [isFading, fadeReady, imageLoaded]);

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
      const maybeDecode = typeof img.decode === "function" ? img.decode() : Promise.resolve();
      Promise.resolve(maybeDecode)
        .catch(() => {})
        .finally(() => {
          queueFadeReady(img, stageRef.current);
        });
    }
  }, [currentIndex, currentImageUrl, playItems, imageLoaded]);

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
      {playbackState === "playing" && activeIndex > 0 && !isIntro && (
        <button
          className="player-back"
          type="button"
          onClick={handleBack}
          aria-label="Go back one slide"
        >
          ←
        </button>
      )}

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
          {prevImageUrl && (
            <img
              key={`prev-${prevIndex}-${prevImageUrl}`}
              src={withCacheBust(prevImageUrl)}
              alt=""
              className={`player-image is-prev${transitionMode === "cut" ? " fade-cut" : ""}${fadeReady ? " fade-out is-ready" : ""}`}
            />
          )}
          {currentImageUrl && (
            <img
              key={`cur-${currentIndex}-${currentImageUrl}`}
              src={withCacheBust(currentImageUrl)}
              alt={currentItem.title || ""}
              className={`player-image is-current${isFading ? " fade-in" : ""}${transitionMode === "cut" ? " fade-cut" : ""}${fadeReady ? " is-ready" : " is-loading"}`}
              ref={currentImgRef}
              onLoad={(e) => {
                const img = e.currentTarget;
                setImageLoaded(true);
                const maybeDecode = typeof img.decode === "function" ? img.decode() : Promise.resolve();
                Promise.resolve(maybeDecode)
                  .catch(() => {})
                  .finally(() => {
                    queueFadeReady(img, stageRef.current);
                  });
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

          {imageLoaded && fadeReady && titleVisible && titleReady && isIntro && IntroRenderer && (
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

          {imageLoaded && fadeReady && title && titleVisible && titleReady && !isIntro && (
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
          {!imageLoaded && currentImageUrl && (
            <div className="player-loading" aria-label="Loading image">
              <div className="player-loading-spinner" />
            </div>
          )}
        </div>
        {showAdvanceHint &&
          playbackState === "playing" &&
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
