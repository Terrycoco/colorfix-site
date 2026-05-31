import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AppStateProvider } from '@context/AppStateContext.jsx';
import StandAloneLayout from '@layout/StandAloneLayout';
import PlayerPage from '@pages/PlayerPage';

import '@styles/reset.css';
import '@styles/typography.css';
import '@styles/global.css';

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <AppStateProvider>
      <BrowserRouter basename="/">
        <Routes>
          <Route element={<StandAloneLayout />}>
            <Route path="p/:playlistId" element={<PlayerPage />} />
            <Route path="p/:playlistId/:start" element={<PlayerPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AppStateProvider>
  </StrictMode>
);
