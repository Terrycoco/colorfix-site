import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import "./autohidefooter.css";

/**
 * AutoHideFooter — reveal on downward scrolling, wheel, or keyboard navigation.
 * Never read full document dimensions: doing so after a large Gallery render
 * can force an expensive synchronous layout.
 */
export default function AutoHideFooter({
  children,
  className = "",
  zIndex = 10000,
  bottomScrollThreshold = 160, // retained for backwards compatibility
  idleTimeout = 2400,
  showOnInitial = false,
}) {
  const [visible, setVisible] = useState(false);
  const hideTimerRef = useRef(null);
  const footerRef = useRef(null);
  const lastScrollYRef = useRef(null);

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

  // Respond to downward page scroll without querying document dimensions.
  // Covers touch scrolling on phones as well as conventional scroll input.
  useEffect(() => {
    lastScrollYRef.current = window.scrollY;
    const onScroll = () => {
      const y = window.scrollY;
      const previousY = lastScrollYRef.current;
      lastScrollYRef.current = y;
      if (previousY !== null && y > previousY + 1) show();
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, [idleTimeout]);

  // Reveal on downward wheel gestures, including independently scrolling containers.
  useEffect(() => {
    const onWheel = (event) => {
      if (typeof event?.deltaY === "number" && event.deltaY > 0) show();
    };
    window.addEventListener("wheel", onWheel, { passive: true });
    return () => window.removeEventListener("wheel", onWheel);
  }, [idleTimeout]);

  useEffect(() => {
    const onKeyDown = (event) => {
      const target = event?.target;
      const tag = target?.tagName?.toLowerCase?.() || "";
      if (tag === "input" || tag === "textarea" || target?.isContentEditable) return;
      if (["ArrowDown", "PageDown", " ", "End"].includes(event.key)) show();
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [idleTimeout]);

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
