import './styles/ZIndex.css';
import { StrictMode, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { AppStateProvider } from '@context/AppStateContext.jsx';
import ScrollToTop from '@layout/ScrollToTop';
import AdminApp from './AdminApp.jsx';

// Reuse the public app's route tree within this existing BrowserRouter.
const PublicAppRoutes = lazy(() => import('./Router.jsx').then(module => ({ default: module.PublicAppRoutes })));

import '@styles/global.css';
import '@styles/reset.css';
import '@styles/typography.css';
import '@styles/named.css';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <AppStateProvider>
      <BrowserRouter>
        <ScrollToTop smooth={true} ignoreWhenHash={true} />
        <Routes>
          <Route path="/admin/*" element={<AdminApp />} />
          <Route path="*" element={<Suspense fallback={<p role="status">Loading page…</p>}><PublicAppRoutes /></Suspense>} />
        </Routes>
      </BrowserRouter>
    </AppStateProvider>
  </StrictMode>
);
