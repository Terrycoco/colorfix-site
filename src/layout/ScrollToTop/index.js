// ScrollToTop.jsx
import { useEffect } from "react";
import { useLocation } from "react-router-dom";

export default function ScrollToTop({ smooth = false, ignoreWhenHash = true }) {
  const { pathname, hash } = useLocation();

  useEffect(() => {
    // if you want to respect in-page anchors like #section, skip scrolling
    if (ignoreWhenHash && hash) return;

    // optional: disable browser's automatic scroll restoration
    if ("scrollRestoration" in window.history) {
      window.history.scrollRestoration = "manual";
    }

    window.scrollTo({
      top: 0,
      left: 0,
      behavior: smooth ? "smooth" : "auto",
    });
  }, [pathname, hash, smooth, ignoreWhenHash]);

  return null;
}
