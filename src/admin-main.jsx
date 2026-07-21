import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { AppStateProvider } from '@context/AppStateContext.jsx';
import { useAppState } from '@context/AppStateContext.jsx';
import { isAdmin } from '@helpers/authHelper';
import ScrollToTop from '@layout/ScrollToTop';
import AdminApp from './AdminApp.jsx';

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
          <Route path="*" element={<AdminRouteNormalizer />} />
        </Routes>
      </BrowserRouter>
    </AppStateProvider>
  </StrictMode>
);

function AdminRouteNormalizer() {
  const location = useLocation();
  const { user } = useAppState();
  const adminAllowed = Boolean(user?.is_admin) || isAdmin();
  const target = `${location.pathname}${location.search}${location.hash}`;

  if (isPublicPlaylistRoute(location.pathname)) {
    window.location.replace(target || "/");
    return null;
  }

  if (!adminAllowed) {
    window.location.replace(target || "/");
    return null;
  }

  return <Navigate to={`/admin${target}`} replace />;
}

function isPublicPlaylistRoute(pathname) {
  return pathname === "/picker"
    || pathname === "/playlist-color-search"
    || pathname.startsWith("/p/")
    || pathname.startsWith("/playlist/")
    || pathname.startsWith("/playlist-thumbs/");
}
