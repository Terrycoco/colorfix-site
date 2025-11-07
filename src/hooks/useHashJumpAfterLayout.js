import { useEffect } from "react";
import { useLocation } from "react-router-dom";

/** Jumps to #hash AFTER data/layout are ready (masonry, lazy images, etc.) */
export default function useHashJumpAfterLayout({
  ready = true,           // set true only when your swatch list is rendered
  offsetPx = 0,           // extra offset if you don't use scroll-margin-top
  getScroller = () => window,
  maxWaitMs = 6000,       // wait up to 6s for slow queries/images
} = {}) {
  const location = useLocation();

  useEffect(() => {
    if (!ready) return;
    const id = location.hash?.slice(1);
    if (!id) return;

    let done = false;
    const start = performance.now();
    const scroller = getScroller();

    const jump = (el) => {
      if (!el || done) return;
      // Use scroll-margin-top via scrollIntoView if possible
      if (scroller === window) {
        el.scrollIntoView({ block: "start", inline: "nearest" });
        if (offsetPx) window.scrollBy(0, -offsetPx); // optional nudge
      } else {
        // container scroll
        const er = el.getBoundingClientRect();
        const sr = scroller.getBoundingClientRect();
        scroller.scrollTo({ top: scroller.scrollTop + (er.top - sr.top) - offsetPx, left: 0 });
      }
      el.focus?.({ preventScroll: true });
      done = true;
    };

    const tryJump = () => {
      if (done) return;
      const el = document.getElementById(id);
      if (el) {
        const r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) return jump(el); // only when laid out
      }
      if (performance.now() - start < maxWaitMs) {
        requestAnimationFrame(tryJump);
      }
    };

    // Also watch DOM changes so we jump as soon as the item appears
    const mo = new MutationObserver(() => {
      if (done) return;
      const el = document.getElementById(id);
      if (el) jump(el);
    });
    mo.observe(document.body, { childList: true, subtree: true });

    requestAnimationFrame(tryJump);
    const t = setTimeout(tryJump, 300); // late image/layout nudge

    return () => { done = true; clearTimeout(t); mo.disconnect(); };
  }, [location.key, location.hash, ready, offsetPx, getScroller, maxWaitMs]);
}
