import { useEffect } from "react";
import { useAppState } from "@context/AppStateContext";

/**
 * Shows sticky palette only after a sentinel placed right after the hero
 * crosses the top bar. No forced hiding; no flicker; no reliance on hero
 * height during early layout.
 */
export default function usePaletteStickyOnScroll(heroRef, { offset = 0 } = {}) {
  const { setPaletteCollapsed } = useAppState();

  useEffect(() => {
    const hero = heroRef?.current;
    if (!hero) return;

    // Create a 1px sentinel right after the hero block
    const sentinel = document.createElement("div");
    sentinel.setAttribute("aria-hidden", "true");
    sentinel.style.cssText = "position:relative;height:1px;pointer-events:none;";
    hero.after(sentinel);

    // Read the nav height CSS var (fallback 40)
    const v = getComputedStyle(document.documentElement).getPropertyValue("--bar-h").trim();
    const barH = Number.isFinite(parseInt(v, 10)) ? parseInt(v, 10) : 40;

    // IO: when sentinel is intersecting the viewport *past* the top bar,
    // we consider the hero "gone" and show the sticky.
    const io = new IntersectionObserver(
      ([entry]) => {
        const show = entry.isIntersecting; // because of negative top rootMargin below
        setPaletteCollapsed(!show);
      },
      {
        root: null,
        rootMargin: `-${barH + offset}px 0px 0px 0px`,
        threshold: 0,
      }
    );

    io.observe(sentinel);

    // Immediate check handles reload mid-page
    // If sentinel is already above the bar, show sticky
    const rect = sentinel.getBoundingClientRect();
    setPaletteCollapsed(!(rect.top <= barH + offset));

    return () => {
      io.disconnect();
      sentinel.remove();
    };
  }, [heroRef, setPaletteCollapsed, offset]);
}
