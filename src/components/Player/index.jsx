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
  const didInitRef = useRef(false);

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
  }

  const currentIndex = activeIndex;
  const currentItem = items[currentIndex] || null;
  const prevItem = prevIndex != null ? items[prevIndex] || null : null;
  const title = items[titleIndex]?.title || "";

  useEffect(() => {
    if (!imageLoaded) return;
    const timer = setTimeout(() => {
      setTitleVisible(true);
    }, 180);
    return () => clearTimeout(timer);
  }, [imageLoaded, currentIndex]);


  useEffect(() => {
    if (!isFading || !fadeReady) return;
    const timer = setTimeout(() => {
      setPrevIndex(null);
      setIsFading(false);
    }, 1700);
    return () => clearTimeout(timer);
  }, [isFading, fadeReady]);

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
    onClick={handleAdvance}
    onTouchEnd={handleAdvance}
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
          onLoad={() => {
            setImageLoaded(true);
            requestAnimationFrame(() => setFadeReady(true));
          }}
        />
      )}

      {title && titleVisible && (
        <div className="player-title">
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
