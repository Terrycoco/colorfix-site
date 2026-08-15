import { createContext, useMemo } from "react";

export const AnalyticsContext = createContext({
  rex: null,
  viewer_id: null,
});

export default function AnalyticsProvider({
  rex = null,
  viewerId = null,
  children,
}) {
  const value = useMemo(
    () => ({
      rex,
      viewer_id: viewerId,
    }),
    [rex, viewerId]
  );

  return (
    <AnalyticsContext.Provider value={value}>
      {children}
    </AnalyticsContext.Provider>
  );
}