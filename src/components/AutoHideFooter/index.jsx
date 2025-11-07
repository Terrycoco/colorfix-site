import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import "./autohidefooter.css";

/**
 * AutoHideFooter — reveal on:
 *  - wheel down (deltaY > 0) anywhere (works for container scrolls)
 *  - keydown for ArrowDown / PageDown / Space / End
 *  - window near-bottom check (for window-scrolling pages)
 * Then auto-hide after idleTimeout unless hovered/focused.
 */
export default function AutoHideFooter({
  children,
  className = "",
  zIndex = 10000,
  bottomScrollThreshold = 160,
  idleTimeout = 2400,
  showOnInitial = false,
}) {
  const [visible, setVisible] = useState(false);
  const hideTimerRef = useRef(null);
  const footerRef = useRef(null);

  const clearHideTimer = () => {
    if (hideTimerRef.current) {
      clearTimeout(hideTimerRef.current);
      hideTimerRef.current = null;
    }
  };

  const show = () => {
    setVisible(true);
    clearHideTimer();
    hideTimerRef.current = setTimeout(() => {
      if (!footerRef.current?.matches(":hover, :focus-within")) {
        setVisible(false);
      }
    }, idleTimeout);
  };

  // Window near-bottom (works for window-scrolling pages)
  useEffect(() => {
    const isNearBottom = () => {
      const doc = document.documentElement;
      const viewport = window.innerHeight || doc.clientHeight || 0;
      const full = Math.max(
        doc.scrollHeight,
        doc.offsetHeight,
        doc.clientHeight,
        document.body?.scrollHeight || 0,
        document.body?.offsetHeight || 0
      );
      const y =
        window.pageYOffset ||
        document.documentElement.scrollTop ||
        document.body.scrollTop ||
        0;
      return y + viewport >= full - bottomScrollThreshold;
    };

    const onScrollOrResize = () => {
      if (isNearBottom()) show();
    };

    // If we start near bottom, reveal
    if (isNearBottom()) setVisible(true);

    window.addEventListener("scroll", onScrollOrResize, { passive: true });
    window.addEventListener("resize", onScrollOrResize);
    return () => {
      window.removeEventListener("scroll", onScrollOrResize);
      window.removeEventListener("resize", onScrollOrResize);
    };
  }, [bottomScrollThreshold, idleTimeout]);

  // Reveal on any downward WHEEL scroll (works even when a child container scrolls)
  useEffect(() => {
    const onWheel = (e) => {
      if (e && typeof e.deltaY === "number" && e.deltaY > 0) {
        show();
      }
    };
    window.addEventListener("wheel", onWheel, { passive: true });
    return () => window.removeEventListener("wheel", onWheel);
  }, [idleTimeout]);

  // Reveal on common "scroll down" keys
  useEffect(() => {
    const onKeyDown = (e) => {
      const k = e.key;
      if (k === "ArrowDown" || k === "PageDown" || k === " " || k === "End") {
        show();
      }
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [idleTimeout]);

  // Keep visible while hovered/focused; re-arm timer on leave
  useEffect(() => {
    const node = footerRef.current;
    if (!node) return;

    const onEnter = () => {
      clearHideTimer();
      setVisible(true);
    };
    const onLeave = () => {
      clearHideTimer();
      hideTimerRef.current = setTimeout(() => setVisible(false), idleTimeout);
    };

    node.addEventListener("mouseenter", onEnter);
    node.addEventListener("mouseleave", onLeave);
    node.addEventListener("focusin", onEnter);
    node.addEventListener("focusout", onLeave);

    return () => {
      node.removeEventListener("mouseenter", onEnter);
      node.removeEventListener("mouseleave", onLeave);
      node.removeEventListener("focusin", onEnter);
      node.removeEventListener("focusout", onLeave);
    };
  }, [idleTimeout]);

  // Optional brief reveal on mount
  useEffect(() => {
    if (showOnInitial) {
      setVisible(true);
      hideTimerRef.current = setTimeout(() => setVisible(false), idleTimeout);
    }
    return () => clearHideTimer();
  }, [showOnInitial, idleTimeout]);

  return createPortal(
    <div
      ref={footerRef}
      className={`auto-footer ${visible ? "is-visible" : ""} ${className}`}
      style={{ zIndex }}
      role="region"
      aria-label="Auto-hiding footer"
    >
      <div className="auto-footer__inner">{children}</div>
    </div>,
    document.body
  );
}
