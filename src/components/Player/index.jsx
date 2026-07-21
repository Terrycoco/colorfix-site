import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from "react";
import { getIntroLayout } from "../PlayerIntroLayouts/registry";
import AnimatedHueWheel from "@components/AnimatedHueWheel";
import BrandBumperLogo from "@components/BrandBumperLogo";
import {
  extractAssetId,
  fetchAssetUrl,
  getImageRefreshEnabled,
  isAssetRef,
  parsePhotoRef,
  withImageRefresh,
} from "@helpers/assetImage";
import { isPaletteEligibleItem } from "@helpers/playerPaletteItems";
import "./player.css";

const DEFAULT_TITLE_DELAY_MS = 120;
const TEXT_AFTER_IMAGE_TITLE_DELAY_MS = 650;

const Player = forwardRef(function Player({
  slides = [],
  startIndex = 0,
  playlistInstanceId,
  onAbort,
  onLikeChange,
  onPlaybackEnd,
  hideStars = false,
  embedded = false,
  galleryName = "",
  galleryDescription = "",
  onPalettePromptClick,
}, ref) {
  const allItems = useMemo(() => (Array.isArray(slides) ? slides : []), [slides]);


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
  const [cacheBustEnabled, setCacheBustEnabled] = useState(() => getImageRefreshEnabled());
  const [showAdvanceHint, setShowAdvanceHint] = useState(true);
  const [palettePromptReady, setPalettePromptReady] = useState(false);
  const [currentImageUrl, setCurrentImageUrl] = useState("");
  const [prevImageUrl, setPrevImageUrl] = useState("");
  const didInitRef = useRef(false);
  const endEmitRef = useRef(null);
  const currentImgRef = useRef(null);
  const stageRef = useRef(null);
  const titleRef = useRef(null);
  const didLikeInteractRef = useRef(false);

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
    if (playbackState === "end") {
      const lastIndex = findLastReplayableIndex(playItems);
      setPlaybackState("playing");
      setPrevIndex(null);
      setActiveIndex(lastIndex);
      setTitleIndex(lastIndex);
      setTitleVisible(false);
      setTitleReady(false);
      setImageLoaded(false);
      setFadeReady(false);
      setIsFading(false);
      setTitleFull(false);
      return;
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
    setCacheBustEnabled(getImageRefreshEnabled());
    setLikedSet(readLikedSet(playlistInstanceId));
    setShowAdvanceHint(true);
    didLikeInteractRef.current = false;
  }, [slides, playlistInstanceId]);

  const isPaletteItem = (item) => {
    if (!item) return false;
    return Boolean(item.ap_id || item.palette_hash);
  };

  useEffect(() => {
    if (!imageLoaded) return () => {};
    let cancelled = false;
    const seen = new Set();

    function preload(url) {
      if (!url || seen.has(url)) return;
      seen.add(url);
      const img = new Image();
      img.decoding = "async";
      img.src = withCacheBust(url);
    }

    (slides || []).forEach((item, index) => {
      if (index === activeIndex) return;
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
  }, [slides, cacheBustEnabled, activeIndex, imageLoaded]);


  useEffect(() => {
    writeLikedSet(playlistInstanceId, likedSet);
  }, [likedSet, playlistInstanceId]);

  function isItemStarrable(item) {
    if (hideStars) return false;
    if (!item) return false;
    const itemType = (item.type || "normal").toLowerCase();
    if (itemType === "intro" || itemType === "text" || itemType === "hue-wheel") return false;
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
    getCurrentItem: () => playItems[activeIndex] || null,
  }));

  const currentIndex = activeIndex;
  const currentItem = playItems[currentIndex] || null;
  const prevItem = prevIndex != null ? playItems[prevIndex] || null : null;
  const title = playItems[titleIndex]?.title || "";
  const subtitle = (playItems[titleIndex]?.subtitle || "").trim();
  const hasOverlayText = Boolean(title || subtitle);
  const titleMode = playItems[titleIndex]?.title_mode || "animated";
  const transitionMode = (currentItem?.transition || "animation").toLowerCase();
  const subtitleOffset = subtitle ? 0 : 18;
  const currentType = (currentItem?.type || "normal").toLowerCase().trim();
  const isIntro = currentType === "intro" || currentType === "text";
  const isHueWheel = currentType === "hue-wheel";
  const isBrandBumper = currentType === "brand-bumper";
  const brandBumperConfig = useMemo(
    () => parseBrandBumperConfig(currentItem?.body),
    [currentItem?.body]
  );
  const hueWheelConfig = useMemo(
    () => parseHueWheelConfig(currentItem?.body),
    [currentItem?.body]
  );
  const introNoImage = isIntro && !currentImageUrl;
  const textAfterImageTransition = introNoImage && Boolean(prevImageUrl) && isFading;
  const hasCurrentImageRef = Boolean(currentItem?.image_url);
  const showImageLoading = playbackState === "playing" && !imageLoaded && !introNoImage && (hasCurrentImageRef || currentImageUrl);
  const shouldShowAdvanceHint =
    showAdvanceHint &&
    playbackState === "playing" &&
    activeIndex === 0 &&
    currentIndex === 0 &&
    (imageLoaded || introNoImage);
  const introLayoutKey = isIntro
    ? ((currentItem?.layout || "").toString().toLowerCase().trim() || (currentType === "text" ? "text" : "default"))
    : null;
  const IntroRenderer = isIntro ? getIntroLayout(introLayoutKey) : null;
  const isStarrable = isItemStarrable(currentItem);
  const showPalettePrompt = playbackState === "playing" && isSavedPaletteEligibleItem(currentItem) && imageLoaded && fadeReady;
  const currentKey = getItemKey(currentItem);
  const isLiked = currentKey ? likedSet.has(currentKey) : false;
  const galleryJsonLd = useMemo(
    () => buildImageGallerySchema(allItems, galleryName, galleryDescription),
    [allItems, galleryName, galleryDescription]
  );

  useEffect(() => {
    let cancelled = false;
    if (isHueWheel || isBrandBumper) {
      setCurrentImageUrl("");
      return () => { cancelled = true; };
    }
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
  }, [currentItem?.image_url, isHueWheel, isBrandBumper]);

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
    return withImageRefresh(url, true);
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

  useEffect(() => {
    setPalettePromptReady(false);
    if (!showPalettePrompt) return undefined;
    const timer = setTimeout(() => {
      setPalettePromptReady(true);
    }, 2800);
    return () => clearTimeout(timer);
  }, [showPalettePrompt, currentIndex]);

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
    const titleDelay = textAfterImageTransition
      ? TEXT_AFTER_IMAGE_TITLE_DELAY_MS
      : DEFAULT_TITLE_DELAY_MS;
    const timer = setTimeout(() => {
      setTitleVisible(true);
      requestAnimationFrame(() => {
        updateOverlayPositions(currentImgRef.current, stageRef.current);
        setTitleReady(true);
      });
    }, titleDelay);
    return () => clearTimeout(timer);
  }, [imageLoaded, fadeReady, currentIndex, titleMode, textAfterImageTransition]);

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
    if (!isIntro && !isHueWheel && !isBrandBumper) return;
    if (currentImageUrl) return;
    setImageLoaded(true);
    let cancelled = false;
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        if (!cancelled) setFadeReady(true);
      });
    });
    return () => {
      cancelled = true;
    };
  }, [isIntro, isHueWheel, isBrandBumper, currentIndex, currentImageUrl]);

  useEffect(() => {
    if (!isBrandBumper) return () => {};
    if (playbackState !== "playing") return () => {};
    if (!imageLoaded || !fadeReady || !titleVisible || !titleReady) return () => {};
    if (brandBumperConfig.requires_tap === true) return () => {};
    const durationMs = Math.max(
      4200,
      Number(currentItem?.duration_ms || brandBumperConfig.duration_ms || 4200),
    );
    const timer = setTimeout(() => {
      handleAdvance();
    }, Math.max(1200, durationMs));
    return () => clearTimeout(timer);
  }, [
    isBrandBumper,
    playbackState,
    imageLoaded,
    fadeReady,
    titleVisible,
    titleReady,
    currentIndex,
    currentItem?.duration_ms,
    brandBumperConfig.duration_ms,
    brandBumperConfig.requires_tap,
  ]);


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
      queueFadeReady(img, stageRef.current);
    }
  }, [currentIndex, currentImageUrl, playItems, imageLoaded]);

  return (
    <div className={`player-root${embedded ? " player-embedded" : ""}`}>
      {galleryJsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonForScript(galleryJsonLd) }}
        />
      )}
      <button
        className="player-exit"
        type="button"
        onClick={handleExit}
        aria-label="Exit player"
      >
        ×
      </button>
      {((playbackState === "playing" && activeIndex > 0 && currentType !== "intro") || playbackState === "end") && (
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
        <div className={`player-image-frame${introNoImage ? " is-text-only" : ""}`}>
          {prevImageUrl && (
            <img
              key={`prev-${prevIndex}-${prevImageUrl}`}
              src={withCacheBust(prevImageUrl)}
              alt=""
              className={`player-image is-prev${textAfterImageTransition ? " is-exiting-to-text" : ""}${transitionMode === "cut" ? " fade-cut" : ""}${fadeReady ? " fade-out is-ready" : ""}`}
            />
          )}
          {introNoImage && (
            <div
              className={`player-text-slide-backdrop${fadeReady ? " is-ready" : ""}${transitionMode === "cut" ? " fade-cut" : ""}`}
              aria-hidden="true"
            />
          )}
          {currentImageUrl && (
            <img
              key={`cur-${currentIndex}-${currentImageUrl}`}
              src={withCacheBust(currentImageUrl)}
              alt={currentItem.alt_tag || currentItem.title || currentItem.subtitle || ""}
              loading="eager"
              decoding="async"
              fetchPriority="high"
              className={`player-image is-current${isFading ? " fade-in" : ""}${transitionMode === "cut" ? " fade-cut" : ""}${fadeReady ? " is-ready" : " is-loading"}`}
              ref={currentImgRef}
              onLoad={(e) => {
                const img = e.currentTarget;
                setImageLoaded(true);
                queueFadeReady(img, stageRef.current);
              }}
              onError={() => {
                setImageLoaded(true);
                setFadeReady(true);
              }}
            />
          )}
          {false && playbackState === "playing" && !isIntro && isStarrable && imageLoaded && (
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

          {imageLoaded && fadeReady && titleVisible && titleReady && isHueWheel && (
            <div className="player-hue-wheel-slide">
              <div className="player-hue-wheel-copy">
                {title ? <h1>{title}</h1> : null}
                {subtitle ? <p>{subtitle}</p> : null}
              </div>
              <AnimatedHueWheel
                key={`hue-wheel-${currentIndex}-${currentItem?.playlist_item_id || ""}`}
                items={hueWheelConfig.items}
                animated={hueWheelConfig.animated}
                showLabels={hueWheelConfig.showLabels}
                showDots={hueWheelConfig.showDots}
                pulseOnComplete={hueWheelConfig.pulseOnComplete}
                caption={hueWheelConfig.caption}
                size={hueWheelConfig.size}
                wheelFadeMs={hueWheelConfig.wheelFadeMs}
                spokeStartRadius={hueWheelConfig.spokeStartRadius}
                spokeEndRadius={hueWheelConfig.spokeEndRadius}
                spokeDelayMs={hueWheelConfig.spokeDelayMs}
                spokeStaggerMs={hueWheelConfig.spokeStaggerMs}
                spokeDurationMs={hueWheelConfig.spokeDurationMs}
                className="player-hue-wheel"
              />
            </div>
          )}

          {imageLoaded && fadeReady && titleVisible && titleReady && isBrandBumper && (
            <div className="player-brand-bumper">
              <BrandBumperLogo />
            </div>
          )}

          {imageLoaded && fadeReady && titleVisible && titleReady && isIntro && IntroRenderer && (
            <div
              className={`player-title${introNoImage ? " is-intro-full is-text-intro" : " is-static is-intro-image"}`}
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
              {shouldShowAdvanceHint ? (
                <div className="player-advance-hint player-advance-hint--inline">Tap to Advance</div>
              ) : null}
            </div>
          )}

          {imageLoaded && fadeReady && hasOverlayText && titleVisible && titleReady && !isIntro && !isHueWheel && !isBrandBumper && (
            <div
              className={`player-title${titleFull ? " is-full" : ""}${titleMode === "static" ? " is-static" : ""}${subtitle ? "" : " no-subtitle"}${title ? "" : " no-title"}`}
              style={{
                left: titlePos.left,
                top: Math.max(0, titlePos.top - subtitleOffset),
              }}
              ref={titleRef}
            >
              {title ? <span className="player-title-text">{title}</span> : null}
              {subtitle && <span className="player-subtitle-text">{subtitle}</span>}
            </div>
          )}
          {showImageLoading && (
            <div className="player-loading" aria-label="Loading image">
              <div className="player-loading-spinner" />
            </div>
          )}
          {showPalettePrompt && (
            <button
              type="button"
              className={`player-palette-prompt${palettePromptReady ? " is-visible" : ""}`}
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
                onPalettePromptClick?.(currentItem);
              }}
            >
              See colors →
            </button>
          )}
        </div>
        {shouldShowAdvanceHint && !isIntro && (
          <div className="player-advance-hint">Tap to Advance</div>
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

function parseBrandBumperConfig(rawBody) {
  const fallback = {
    duration_ms: 4200,
    auto_advance: true,
    requires_tap: false,
  };
  const raw = String(rawBody || "").trim();
  if (!raw) return fallback;
  try {
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== "object") return fallback;
    return {
      ...fallback,
      ...parsed,
      auto_advance: parsed.auto_advance !== false,
      requires_tap: parsed.requires_tap === true,
    };
  } catch {
    return fallback;
  }
}

function findLastReplayableIndex(items) {
  const list = Array.isArray(items) ? items : [];
  for (let index = list.length - 1; index >= 0; index -= 1) {
    const type = String(list[index]?.type || list[index]?.item_type || "normal").toLowerCase().trim();
    if (type !== "brand-bumper") return index;
  }
  return Math.max(0, list.length - 1);
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

function isSavedPaletteEligibleItem(item) {
  return isPaletteEligibleItem(item) && Boolean(item?.palette_hash);
}

export default Player;

function parseHueWheelConfig(rawBody) {
  const fallback = {
    items: [],
    animated: true,
    showLabels: false,
    showDots: false,
    pulseOnComplete: true,
    caption: "",
    size: 360,
    wheelFadeMs: 420,
    spokeStartRadius: 0,
    spokeEndRadius: 136,
    spokeDelayMs: 420,
    spokeStaggerMs: 260,
    spokeDurationMs: 800,
  };
  const raw = String(rawBody || "").trim();
  if (!raw) return fallback;
  try {
    const parsed = JSON.parse(raw);
    const config = Array.isArray(parsed) ? { items: parsed } : parsed;
    if (!config || typeof config !== "object") return fallback;
    const items = Array.isArray(config.items) ? config.items : [];
    return {
      ...fallback,
      ...config,
      items,
      animated: config.animated !== false,
      showLabels: config.showLabels === true,
      showDots: config.showDots === true,
      pulseOnComplete: config.pulseOnComplete !== false,
      caption: typeof config.caption === "string" ? config.caption : fallback.caption,
    };
  } catch {
    return fallback;
  }
}

function buildImageGallerySchema(items, galleryName, galleryDescription) {
  const projectName = cleanText(galleryName) || "ColorFix Gallery";
  const hasPart = (items || [])
    .map((item, index) => buildImageObject(item, index, projectName))
    .filter(Boolean);

  if (!hasPart.length) return null;

  const schema = {
    "@context": "https://schema.org",
    "@type": "ImageGallery",
    name: projectName,
    hasPart,
  };

  const description = cleanText(galleryDescription);
  if (description) {
    schema.description = description;
  }

  return schema;
}

function buildImageObject(item, index, projectName) {
  const contentUrl = resolveSchemaImageUrl(item?.image_url);
  if (!contentUrl) return null;

  const title = cleanText(item?.title);
  const subtitle = cleanText(item?.subtitle);
  const altTag = cleanText(item?.alt_tag || item?.ai_alt_text || item?.alt_text);

  const imageObject = {
    "@type": "ImageObject",
    position: index + 1,
    name: title || subtitle || `${projectName} - Gallery Image ${index + 1}`,
    contentUrl,
    width: 1600,
    height: 1200,
  };

  if (subtitle) {
    imageObject.caption = subtitle;
  }
  if (altTag) {
    imageObject.description = altTag;
  }

  return imageObject;
}

function resolveSchemaImageUrl(value) {
  const raw = cleanText(value);
  if (!raw) return "";

  const parsed = parsePhotoRef(raw);
  const resolved = parsed.url || raw;
  if (isAssetRef(resolved)) return "";
  if (/^https?:\/\//i.test(resolved)) return resolved;
  if (typeof window === "undefined") return resolved;

  try {
    return new URL(resolved, window.location.origin).toString();
  } catch {
    return resolved;
  }
}

function cleanText(value) {
  return String(value || "").trim();
}

function safeJsonForScript(value) {
  return JSON.stringify(value).replace(/</g, "\\u003c");
}
