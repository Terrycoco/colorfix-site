// src/Router.jsx
import { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import App from './App.jsx';
import MainLayout from '@layout/MainLayout';
import ScrollToTop from '@layout/ScrollToTop';

import AboutPage from '@pages/AboutPage';
import LoginPage from '@pages/login/LoginPage';
import SearchPage from '@pages/SearchPage';
import MobileDetailPage from '@pages/MobileDetailPage';
import GalleryPage from '@pages/GalleryPage';
import SideBySidePage from '@pages/SideBySidePage';
import MyPalettePage from '@pages/MyPalettePage';
import AdvancedSearchPage from '@pages/AdvancedSearchPage';
import AdvancedResultsPage from '@pages/AdvancedResultsPage';
import MatchResultsPage from '@pages/MatchResultsPage';
import QuickFindPage from '@pages/QuickFindPage';
import BrowsePalettesPage from '@pages/BrowsePalettesPage';
import PaletteTranslationPage from '@pages/PaletteTranslationPage';

const AdminLayout = lazy(() => import('@layout/AdminLayout'));
const CategoryEditPage = lazy(() => import('@pages/CategoryEditPage'));
const ColorEditPage = lazy(() => import('@pages/ColorEditPage'));
const SearchPresetPage = lazy(() => import('@pages/SearchPresetPage'));
const SQLPage = lazy(() => import('@pages/SQLPage'));
const ItemEditPage = lazy(() => import('@pages/ItemEditPage'));
const FilterEditPage = lazy(() => import('@pages/FilterEditPage'));
const FriendsEnterPage = lazy(() => import('@pages/FriendsEnterPage'));

const MissingChipsPage = lazy(() => import('@pages/MissingChipsPage'));
const WhitesLrvEditorPage = lazy(() => import('@pages/whitesLrvEditorPage'));
const AdminUploadPhotoPage = lazy(() => import('@pages/AdminUploadPhotoPage'));

const AnalysisPage = lazy(() => import('@pages/AnalysisPage'));
const AdminRolesMasksPage = lazy(() => import ('@pages/AdminRolesMasksPage'));
const AdminSupercatsPage = lazy(() => import ('@pages/AdminSupercatsPage'));
const AdminSavedPalettesPage = lazy(() => import('@pages/AdminSavedPalettesPage'));
const AdminMaskTesterPage = lazy(() => import('@pages/AdminMaskTesterPage'));
const AdminAppliedPalettesPage = lazy(() => import('@pages/AdminAppliedPalettesPage'));
const AdminAppliedPaletteEditorPage = lazy(() => import('@pages/AdminAppliedPaletteEditorPage'));
const AdminPlayerPage = lazy(() => import('@pages/AdminPlayerPage'));
const AdminPlaylistInstancesPage = lazy(() => import('@pages/AdminPlaylistInstancesPage'));
const AdminPlaylistEditorPage = lazy(() => import('@pages/AdminPlaylistEditorPage'));
const AdminHOAPage = lazy(() => import('@pages/AdminHOAPage'));
const AppliedPaletteViewPage = lazy(() => import('@pages/AppliedPaletteViewPage'));
const PrintAppliedPalettePage = lazy(() => import('@pages/PrintAppliedPalettePage'));
const PrintMyPalettePage = lazy(() => import('@pages/PrintMyPalettePage'));
const PlayerPage = lazy(() => import('@pages/PlayerPage'));
const StandAloneLayout = lazy(() => import('@layout/StandAloneLayout'));

function AppRouter() {
  const renderWithSuspense = (Component, label) => (
    <Suspense fallback={<RouteFallback label={label} />}>
      <Component />
    </Suspense>
  );

  return (
    <BrowserRouter basename="/">
      <ScrollToTop smooth={true} ignoreWhenHash={true} />

      <Routes>
        <Route
          path="print/applied/:paletteId"
          element={renderWithSuspense(PrintAppliedPalettePage, 'Loading printable applied palette…')}
        />
        <Route
          path="print/my-palette"
          element={renderWithSuspense(PrintMyPalettePage, 'Loading printable palette…')}
        />
        <Route
          path="view/:paletteId"
          element={renderWithSuspense(AppliedPaletteViewPage, 'Loading palette…')}
        />
        <Route element={renderWithSuspense(StandAloneLayout, 'Loading player…')}>
          <Route
            path="playlist/:playlistId"
            element={renderWithSuspense(PlayerPage, 'Loading player…')}
          />
          <Route
            path="playlist/:playlistId/:start"
            element={renderWithSuspense(PlayerPage, 'Loading player…')}
          />
        </Route>

        {/* App shell (nav, etc.) */}
        <Route element={<App />}>
          
          {/* USER-FACING PAGES ⤵ wrapped by MainLayout (capped, centered) */}
          <Route element={<MainLayout />}>
            <Route index element={<Navigate to="/results/4" replace />} />
            <Route path="search" element={<SearchPage />} />
            <Route path="results/:queryId" element={<GalleryPage />} />
            <Route path="color/:id" element={<MobileDetailPage />} />
            <Route path="sbs" element={<SideBySidePage />} />
            <Route path="my-palette" element={<MyPalettePage />} />
            <Route path="adv-search" element={<AdvancedSearchPage />} />
            <Route path="adv-results" element={<AdvancedResultsPage />} />
            <Route path="login" element={<LoginPage />} />
            <Route path="about" element={<AboutPage />} />
           <Route path="matches" element={<MatchResultsPage />} />
           <Route path="quick-find" element={<QuickFindPage />} />
          <Route path="browse-palettes" element={<BrowsePalettesPage />} />
           <Route path="palette/:id/brands" element={<PaletteTranslationPage  />} />
           <Route path="/palette/translate" element={<PaletteTranslationPage />} />   
           
          </Route>

          {/* ADMIN PAGES ⤵ wrapped by AdminLayout (edge-to-edge) */}
          <Route
            path="admin"
            element={renderWithSuspense(AdminLayout, 'Loading admin shell…')}
          >
            <Route index element={<Navigate to="analysis" replace />} />
            <Route
              path="analysis"
              element={renderWithSuspense(AnalysisPage, 'Loading analysis…')}
            />
            <Route
              path="categories"
              element={renderWithSuspense(CategoryEditPage, 'Loading categories…')}
            />
            <Route
              path="colors"
              element={renderWithSuspense(ColorEditPage, 'Loading colors…')}
            />
            <Route
              path="search-presets"
              element={renderWithSuspense(SearchPresetPage, 'Loading presets…')}
            />
            <Route
              path="sql"
              element={renderWithSuspense(SQLPage, 'Loading SQL tools…')}
            />
            <Route
              path="items"
              element={renderWithSuspense(ItemEditPage, 'Loading items…')}
            />
            <Route
              path="filters"
              element={renderWithSuspense(FilterEditPage, 'Loading filters…')}
            />
            <Route
              path="friends"
              element={renderWithSuspense(FriendsEnterPage, 'Loading friends…')}
            />
       
            <Route
              path="missing-chips"
              element={renderWithSuspense(MissingChipsPage, 'Loading missing chips…')}
            />
            <Route
              path="lrv-editor"
              element={renderWithSuspense(WhitesLrvEditorPage, 'Loading LRV editor…')}
            />
            <Route
              path="upload-photo"
              element={renderWithSuspense(AdminUploadPhotoPage, 'Loading upload tool…')}
            />
     
            <Route
              path="mask-tester"
              element={renderWithSuspense(AdminMaskTesterPage, 'Loading mask tester…')}
            />
            <Route
              path="roles-masks"
              element={renderWithSuspense(AdminRolesMasksPage, 'Loading admin roles/masks…')}
            />
            <Route
              path="supercats"
              element={renderWithSuspense(AdminSupercatsPage, 'Loading supercats…')}
            />
            <Route
              path="saved-palettes"
              element={renderWithSuspense(AdminSavedPalettesPage, 'Loading saved palettes…')}
            />
            <Route
              path="applied-palettes"
              element={renderWithSuspense(AdminAppliedPalettesPage, 'Loading applied palettes…')}
            />
            <Route
              path="applied-palettes/:paletteId/edit"
              element={renderWithSuspense(AdminAppliedPaletteEditorPage, 'Loading palette editor…')}
            />
            <Route
              path="player/:playlistId"
              element={renderWithSuspense(PlayerPage, 'Loading player…')}
            />
            <Route
              path="player/:playlistId/:start"
              element={renderWithSuspense(PlayerPage, 'Loading player…')}
            />
            <Route
              path="player-preview/:playlistId"
              element={renderWithSuspense(AdminPlayerPage, 'Loading player preview…')}
            />
            <Route
              path="player-preview/:playlistId/:start"
              element={renderWithSuspense(AdminPlayerPage, 'Loading player preview…')}
            />
            <Route
              path="playlist-instances"
              element={renderWithSuspense(AdminPlaylistInstancesPage, 'Loading playlist instances…')}
            />
            <Route
              path="hoas"
              element={renderWithSuspense(AdminHOAPage, 'Loading HOAs…')}
            />
            <Route
              path="playlists/:playlistId"
              element={renderWithSuspense(AdminPlaylistEditorPage, 'Loading playlist editor…')}
            />
            <Route
              path="playlists/new"
              element={renderWithSuspense(AdminPlaylistEditorPage, 'Loading playlist editor…')}
            />
          </Route>

        </Route>
      </Routes>
    </BrowserRouter>
  );
}

function RouteFallback({ label }) {
  return (
    <div className="route-loader" role="status" aria-live="polite">
      {label}
    </div>
  );
}

export default AppRouter;
