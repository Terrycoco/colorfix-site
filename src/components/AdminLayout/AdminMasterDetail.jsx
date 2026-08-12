import { useEffect, useMemo, useRef, useState } from "react";

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max);
}

export default function AdminMasterDetail({
  list,
  detail,
  storageKey = "admin-master-detail-width",
  defaultListWidth = 320,
  minListWidth = 240,
  maxListWidth = 520,
  className = "",
}) {
  const shellRef = useRef(null);

  const initialWidth = useMemo(() => {
    if (typeof window === "undefined") return defaultListWidth;
    const stored = Number(window.localStorage.getItem(storageKey));
    return Number.isFinite(stored) && stored > 0 ? stored : defaultListWidth;
  }, [defaultListWidth, storageKey]);

  const [listWidth, setListWidth] = useState(initialWidth);
  const [dragging, setDragging] = useState(false);

  useEffect(() => {
    if (typeof window !== "undefined") {
      window.localStorage.setItem(storageKey, String(listWidth));
    }
  }, [listWidth, storageKey]);

  useEffect(() => {
    if (!dragging) return;

    function onPointerMove(event) {
      const shell = shellRef.current;
      if (!shell) return;
      const rect = shell.getBoundingClientRect();
      const nextWidth = clamp(event.clientX - rect.left, minListWidth, maxListWidth);
      setListWidth(nextWidth);
    }

    function onPointerUp() {
      setDragging(false);
    }

    window.addEventListener("pointermove", onPointerMove);
    window.addEventListener("pointerup", onPointerUp, { once: true });

    return () => {
      window.removeEventListener("pointermove", onPointerMove);
      window.removeEventListener("pointerup", onPointerUp);
    };
  }, [dragging, minListWidth, maxListWidth]);

  function onSplitterKeyDown(event) {
    const step = event.shiftKey ? 40 : 12;

    if (event.key === "ArrowLeft") {
      event.preventDefault();
      setListWidth((value) => clamp(value - step, minListWidth, maxListWidth));
    }

    if (event.key === "ArrowRight") {
      event.preventDefault();
      setListWidth((value) => clamp(value + step, minListWidth, maxListWidth));
    }

    if (event.key === "Home") {
      event.preventDefault();
      setListWidth(minListWidth);
    }

    if (event.key === "End") {
      event.preventDefault();
      setListWidth(maxListWidth);
    }
  }

  return (
    <div
      ref={shellRef}
      className={`admin-master-detail ${dragging ? "is-resizing" : ""} ${className}`.trim()}
      style={{ "--admin-list-width": `${listWidth}px` }}
    >
      <div className="admin-master-detail__list">{list}</div>

      <div
        className="admin-master-detail__splitter"
        role="separator"
        aria-orientation="vertical"
        aria-label="Resize list pane"
        aria-valuemin={minListWidth}
        aria-valuemax={maxListWidth}
        aria-valuenow={Math.round(listWidth)}
        tabIndex={0}
        onPointerDown={(event) => {
          event.preventDefault();
          event.currentTarget.setPointerCapture?.(event.pointerId);
          setDragging(true);
        }}
        onKeyDown={onSplitterKeyDown}
      />

      <div className="admin-master-detail__detail">{detail}</div>
    </div>
  );
}
