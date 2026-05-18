import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AppStateProvider } from '@context/AppStateContext.jsx';
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
          <Route path="*" element={<Navigate to="/admin/" replace />} />
        </Routes>
      </BrowserRouter>
    </AppStateProvider>
  </StrictMode>
);
