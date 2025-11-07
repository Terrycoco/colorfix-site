import { useEffect, useRef } from 'react';

export default function useBrandApplyRequery(refetch, { enabled = true, onlyIf } = {}) {
  const refetchRef = useRef(refetch);
  const onlyIfRef  = useRef(onlyIf);

  // keep latest closures without re-subscribing
  useEffect(() => { refetchRef.current = refetch; onlyIfRef.current = onlyIf; });

  useEffect(() => {
    if (!enabled) return;
    function onApplied() {
      if (typeof onlyIfRef.current === 'function' && !onlyIfRef.current()) return;
      refetchRef.current?.();
    }
    window.addEventListener('brand-filter-applied', onApplied);
    return () => window.removeEventListener('brand-filter-applied', onApplied);
  }, [enabled]);
}
