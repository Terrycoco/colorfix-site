// src/hooks/useCanGoBack.js
import { useEffect, useState } from "react";
import { useLocation, useNavigationType } from "react-router-dom";

export default function useCanGoBack({ resetOnReload = true } = {}) {
  const locationKey = useLocation().key;
  const navType = useNavigationType(); // "PUSH" | "POP" | "REPLACE"
  const [canGoBack, setCanGoBack] = useState(false);

  useEffect(() => {
    const navEntry =
      performance.getEntriesByType?.("navigation")?.[0] || null;
    const isReload = resetOnReload && navEntry?.type === "reload";

    // Router index if available
    const idx =
      window.history.state && typeof window.history.state.idx === "number"
        ? window.history.state.idx
        : null;

    // On first run or on reload, set a fresh baseline
    if (isReload || sessionStorage.getItem("app:baseIdx") == null) {
      if (idx !== null) sessionStorage.setItem("app:baseIdx", String(idx));
      sessionStorage.setItem("app:baseLen", String(window.history.length));
      sessionStorage.setItem("app:navCount", "0");
    }

    // Count in-app forward navigations
    if (navType === "PUSH") {
      const n = Number(sessionStorage.getItem("app:navCount") || "0") + 1;
      sessionStorage.setItem("app:navCount", String(n));
    }

    const baseIdx = Number(sessionStorage.getItem("app:baseIdx") || "0");
    const baseLen = Number(sessionStorage.getItem("app:baseLen") || "0");
    const navCount = Number(sessionStorage.getItem("app:navCount") || "0");

    // Prefer router idx; fall back to history length + our own count
    const can =
      (idx !== null ? idx > baseIdx : window.history.length > baseLen) ||
      navCount > 0;

    setCanGoBack(can);
  }, [locationKey, navType, resetOnReload]);

  return canGoBack;
}
