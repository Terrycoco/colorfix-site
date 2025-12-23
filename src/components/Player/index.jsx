import { useEffect, useMemo, useRef, useState } from "react";
import PlayerEndScreen from "./PlayerEndScreen";
import "./player.css";

export default function Player({
  items = [],
  startIndex = 0,
  onFinished,
  onAbort,
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
  const didInitRef = useRef(false);
  const currentImgRef = useRef(null);
  const stageRef = useRef(null);
  const titleRef = useRef(null);

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
    setActiveIndex(0);
    setMode("playing");
    setTitleVisible(false);
    setImageLoaded(false);
    setFadeReady(false);
    setTitleIndex(0);
    setPrevIndex(null);
    setIsFading(false);
    setTitleFull(false);
  }

  const currentIndex = activeIndex;
  const currentItem = items[currentIndex] || null;
  const prevItem = prevIndex != null ? items[prevIndex] || null : null;
  const title = items[titleIndex]?.title || "";
  const titleMode = items[titleIndex]?.title_mode || "animated";

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
    if (!isFading || !fadeReady) return;
    const timer = setTimeout(() => {
      setPrevIndex(null);
      setIsFading(false);
    }, 1700);
    return () => clearTimeout(timer);
  }, [isFading, fadeReady]);

  useEffect(() => {
    function handleResize() {
      const img = currentImgRef.current;
      const stage = stageRef.current;
      if (!img || !stage) return;
      const stageRect = stage.getBoundingClientRect();
      const naturalW = img.naturalWidth || 0;
      const naturalH = img.naturalHeight || 0;
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
        const maxLeft = Math.max(0, leftOffset + renderW - titleWidth - 8);
        setTitlePos({
          left: Math.min(baseLeft, maxLeft),
          top: Math.max(0, baseTop - titleRect.height),
        });
      } else {
        setTitlePos({ left: baseLeft, top: Math.max(0, baseTop - 24) });
      }
    }
    window.addEventListener("resize", handleResize);
    window.addEventListener("orientationchange", handleResize);
    handleResize();
    return () => {
      window.removeEventListener("resize", handleResize);
      window.removeEventListener("orientationchange", handleResize);
    };
  }, [currentIndex, imageLoaded, titleVisible]);

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
    onPointerUp={handleAdvance}
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
          src={prevItem.image_url}
          alt=""
          className={`player-image is-prev${fadeReady ? " fade-out is-ready" : ""}`}
        />
      )}
      {currentItem?.image_url && (
        <img
          key={`cur-${currentIndex}-${currentItem.image_url}`}
          src={currentItem.image_url}
          alt={currentItem.title || ""}
          className={`player-image is-current${isFading ? " fade-in" : ""}${fadeReady ? " is-ready" : ""}`}
          ref={currentImgRef}
          onLoad={(e) => {
            setImageLoaded(true);
            requestAnimationFrame(() => setFadeReady(true));
            const stage = stageRef.current;
            const naturalW = e.currentTarget.naturalWidth || 0;
            const naturalH = e.currentTarget.naturalHeight || 0;
            if (!stage || !naturalW || !naturalH) return;
            const stageRect = stage.getBoundingClientRect();
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
              const maxLeft = Math.max(0, leftOffset + renderW - titleWidth - 8);
              setTitlePos({
                left: Math.min(baseLeft, maxLeft),
                top: Math.max(0, baseTop - titleRect.height),
              });
            } else {
              setTitlePos({ left: baseLeft, top: Math.max(0, baseTop - 24) });
            }
          }}
        />
      )}

      {title && titleVisible && (
        <div
          className={`player-title${titleFull ? " is-full" : ""}${titleMode === "static" ? " is-static" : ""}`}
          style={{ left: titlePos.left, top: titlePos.top }}
          ref={titleRef}
        >
          <span className="player-title-text">{title}</span>
        </div>
      )}
    </div>
  </div>
)}

      {mode === "end" && (
        <PlayerEndScreen showReplay={showReplay} onReplay={handleReplay}>
          {endScreen}
        </PlayerEndScreen>
      )}
    </div>
  );
}
