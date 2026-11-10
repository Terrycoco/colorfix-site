import { StrictMode, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { AppStateProvider, useAppState } from '../../src/context/AppStateContext.jsx';
import AnalyticsProvider from '../../src/Analytics/AnalyticsProvider.jsx';
import AppRouter from '../../src/Router.jsx';
import '../../src/styles/ZIndex.css';
import '../../src/styles/global.css';
import '../../src/styles/reset.css';
import '../../src/styles/typography.css';
import '../../src/styles/named.css';

// Observe the real provider and expose its existing operations, not a test cache.
export function Probe() {
  const state = useAppState();
  const providerId = useRef(crypto.randomUUID());
  window.paletteBarTest = { ...state, providerId: providerId.current, documentId: performance.timeOrigin };
  return null;
}

createRoot(document.getElementById('root')).render(
  <StrictMode><AppStateProvider><AnalyticsProvider><AppRouter /><Probe /></AnalyticsProvider></AppStateProvider></StrictMode>
);
