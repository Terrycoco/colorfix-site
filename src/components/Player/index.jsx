import { Suspense, useEffect, useMemo, useRef, useState } from "react";
import PlayerEndScreen from "./PlayerEndScreen";
import { getIntroLayout } from "../PlayerIntroLayouts/registry";
import "./player.css";

export default function Player({
  items = [],
  startIndex = 0,
  onFinished,
  onAbort,
  onLikesSnapshot,
  embedded = false,
  showReplay = true,
  endScreen = null,
}) {
  const safeStart = useMemo(() => {
    if (!Array.isArray(items) || !items.length) return 0;
    const parsed = Number(startIndex);
    if (!Number.isFinite(parsed) || parsed < 0 || parsed >= items.length) return 0;
    return parsed;
  }, [items, startIndex]);

  const [activeIndex, setActiveIndex] = useState(safeStart);
  const [mode, setMode] = useState("playing"); // "playing" | "end"
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
  const didInitRef = useRef(false);
  const currentImgRef = useRef(null);
  const stageRef = useRef(null);
  const titleRef = useRef(null);
  const cacheBustRef = useRef(Date.now());

  useEffect(() => {
    if (!didInitRef.current) {
      didInitRef.current = true;
      return;
    }
    setActiveIndex(safeStart);
    setMode("playing");
    setTitleIndex(safeStart);
    setTitleVisible(false);
    setImageLoaded(false);
    setFadeReady(false);
    setPrevIndex(null);
    setIsFading(false);
    setTitleFull(false);
  }, [safeStart]);

  function getItemKey(item) {
    if (!item) return null;
    const itemType = (item.type || "normal").toLowerCase();
    if (itemType === "intro" || itemType === "text") return null;
    if (item.star !== true) return null;
    const apId = item.ap_id ?? null;
    if (apId === null || apId === undefined || apId === "") return null;
    return String(apId);
  }

  function emitLikesSnapshot() {
    if (!onLikesSnapshot) return;
    onLikesSnapshot(Array.from(likedSet));
  }

  function handleAdvance() {
    if (!items.length) return;

    if (activeIndex >= items.length - 1) {
      setMode("end");
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

  function handleReplay() {
    emitLikesSnapshot();
    setActiveIndex(firstPlayableIndex);
    setMode("playing");
    setTitleVisible(false);
    setImageLoaded(false);
    setFadeReady(false);
    setTitleIndex(firstPlayableIndex);
    setPrevIndex(null);
    setIsFading(false);
    setTitleFull(false);
  }

  const currentIndex = activeIndex;
  const currentItem = items[currentIndex] || null;
  const prevItem = prevIndex != null ? items[prevIndex] || null : null;
  const title = items[titleIndex]?.title || "";
  const subtitle = (items[titleIndex]?.subtitle || "").trim();
  const titleMode = items[titleIndex]?.title_mode || "animated";
  const subtitleOffset = subtitle ? 0 : 18;
  const currentType = (currentItem?.type || "normal").toLowerCase();
  const isIntro = currentType === "intro" || currentType === "text";
  const introLayoutKey = isIntro
    ? ((currentItem?.layout || "").toString().toLowerCase().trim() || (currentType === "text" ? "text" : "default"))
    : null;
  const IntroRenderer = isIntro ? getIntroLayout(introLayoutKey) : null;
  const isStarrable = currentItem?.star === true;
  const currentKey = getItemKey(currentItem);
  const isLiked = currentKey ? likedSet.has(currentKey) : false;
  const firstPlayableIndex = useMemo(() => {
    if (!items.length) return 0;
    const idx = items.findIndex((item) => {
      const t = (item?.type || "normal").toLowerCase();
      return t !== "intro" && t !== "text";
    });
    return idx >= 0 ? idx : 0;
  }, [items]);

  function withCacheBust(url) {
    if (!url) return url;
    const sep = url.includes("?") ? "&" : "?";
    return `${url}${sep}v=${cacheBustRef.current}`;
  }

  function toggleLike(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    if (isIntro) return;
    if (!currentKey) return;
    setLikedSet((prev) => {
      const next = new Set(prev);
      if (next.has(currentKey)) {
        next.delete(currentKey);
      } else {
        next.add(currentKey);
      }
      return next;
    });
  }

  function handleEndActionCapture(e) {
    const target = e.target;
    if (!target || !(target instanceof Element)) return;
    const actionable = target.closest("button,[role=\"button\"],a,[data-player-cta=\"true\"]");
    if (!actionable) return;
    emitLikesSnapshot();
  }

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
    if (mode !== "end") return;
    emitLikesSnapshot();
  }, [mode]);

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
  }, [currentIndex, items, imageLoaded]);

  return (
    <div className={`player-root${embedded ? " player-embedded" : ""}`}>
      <button
        className="player-exit"
        type="button"
        onClick={() => onAbort && onAbort()}
      >
        ×
      </button>

      {mode === "playing" && (
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
      {mode === "playing" && !isIntro && isStarrable && (
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
          className="player-title is-static"
          style={{
            left: titlePos.left,
            top: Math.max(0, titlePos.top - subtitleOffset),
          }}
          ref={titleRef}
        >
          <Suspense fallback={null}>
            <IntroRenderer item={currentItem} />
          </Suspense>
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
  </div>
)}

      {mode === "end" && (
        <PlayerEndScreen showReplay={showReplay} onReplay={handleReplay}>
          {endScreen ? (
            <div onClickCapture={handleEndActionCapture}>
              {endScreen}
            </div>
          ) : null}
        </PlayerEndScreen>
      )}
    </div>
  );
}
