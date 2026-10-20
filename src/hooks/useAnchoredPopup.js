import { useLayoutEffect } from "react";
import useOverlayLifecycle from "./useOverlayLifecycle";

// Body-portaled menus keep their anchor geometry while escaping scroll clipping.
export default function useAnchoredPopup({ open, anchorRef, onClose, minWidth = 0, placement = "bottom" }) {
  const popupRef = useOverlayLifecycle({
    open, layer: "dropdown", modal: false, autoFocus: false, onEscape: onClose, anchorRef,
  });
  useLayoutEffect(() => {
    const anchor = anchorRef.current;
    const popup = popupRef.current;
    if (!open || !anchor || !popup) return undefined;
    function place() {
      const rect = anchor.getBoundingClientRect();
      const viewport = window.visualViewport;
      const height = viewport?.height || window.innerHeight;
      const width = viewport?.width || window.innerWidth;
      const gap = 4;
      const below = height - rect.bottom - gap;
      const above = rect.top - gap;
      const useAbove = (placement === "top" || below < Math.min(popup.scrollHeight, 180)) && above > below;
      const popupWidth = Math.min(Math.max(rect.width, minWidth), width - gap * 2);
      Object.assign(popup.style, {
        position: "fixed", right: "auto", bottom: "auto",
        width: `${popupWidth}px`, minWidth: `${Math.min(minWidth, width - gap * 2)}px`,
        left: `${Math.max(gap, Math.min(rect.left, width - popupWidth - gap))}px`,
        transform: "none", margin: "0",
        maxHeight: `${Math.max(0, Math.min(400, useAbove ? above : below))}px`,
        overflowY: "auto",
      });
      popup.style.top = `${useAbove ? rect.top - Math.min(popup.scrollHeight, above, 400) - gap : rect.bottom + gap}px`;
    }
    place();
    window.addEventListener("scroll", place, true);
    window.addEventListener("resize", place);
    window.visualViewport?.addEventListener("resize", place);
    const observer = new ResizeObserver(place);
    observer.observe(anchor);
    return () => {
      observer.disconnect();
      window.removeEventListener("scroll", place, true);
      window.removeEventListener("resize", place);
      window.visualViewport?.removeEventListener("resize", place);
    };
  }, [open, anchorRef, popupRef, minWidth, placement]);
  return popupRef;
}
