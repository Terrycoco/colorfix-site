// src/Router.jsx
import { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import App from './App.jsx';
import MainLayout from '@layout/MainLayout';
import ScrollToTop from '@layout/ScrollToTop';
import PlayerPage from '@pages/PlayerPage';
import StandAloneLayout from '@layout/StandAloneLayout';

const AboutPage = lazy(() => import('@pages/AboutPage'));
const HireTerryPage = lazy(() => import('@pages/HireTerryPage'));
const RequestPlaylistPage = lazy(() => import('@pages/HireTerryPage/RequestPlaylistPage'));
const SendNotePage = lazy(() => import('@pages/SendNotePage'));
const PrivacyPage = lazy(() => import('@pages/PrivacyPage'));
const LandingPage = lazy(() => import('@pages/LandingPage'));
const LoginPage = lazy(() => import('@pages/login/LoginPage'));
const SearchPage = lazy(() => import('@pages/SearchPage'));
const ColorDetailPage = lazy(() => import('@pages/ColorDetailPage'));
const GalleryPage = lazy(() => import('@pages/GalleryPage'));
const SideBySidePage = lazy(() => import('@pages/SideBySidePage'));
const MyPalettePage = lazy(() => import('@pages/MyPalettePage'));
const AdvancedSearchPage = lazy(() => import('@pages/AdvancedSearchPage'));
const AdvancedResultsPage = lazy(() => import('@pages/AdvancedResultsPage'));
const MatchResultsPage = lazy(() => import('@pages/MatchResultsPage'));
const QuickFindPage = lazy(() => import('@pages/QuickFindPage'));
const BrowsePalettesPage = lazy(() => import('@pages/BrowsePalettesPage'));
const PaletteTranslationPage = lazy(() => import('@pages/PaletteTranslationPage'));
const PrintMyPalettePage = lazy(() => import('@pages/PrintMyPalettePage'));
const PlaylistThumbsPage = lazy(() => import('@pages/PlaylistThumbsPage'));
const PlaylistPickerPage = lazy(() => import('@pages/PlaylistPickerPage'));
const PlaylistColorSearchPage = lazy(() => import('@pages/PlaylistColorSearchPage'));
const SavedPaletteSharePage = lazy(() => import('@pages/SavedPaletteSharePage'));
const ArticlePage = lazy(() => import('@pages/ArticlePage'));
const WatchRedirectPage = lazy(() => import('@pages/WatchRedirectPage'));


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
          path="print/my-palette"
          element={renderWithSuspense(PrintMyPalettePage, 'Loading printable palette…')}
        />
        <Route
          path="palette/:hash/share"
          element={renderWithSuspense(SavedPaletteSharePage, 'Loading saved palette…')}
        />
        <Route
          path="watch"
          element={renderWithSuspense(WatchRedirectPage, 'Loading watch link…')}
        />
        <Route element={renderWithSuspense(StandAloneLayout, 'Loading player…')}>
          <Route
            path="p/:playlistId"
            element={renderWithSuspense(PlayerPage, 'Loading player…')}
          />
          <Route
            path="p/:playlistId/:start"
            element={renderWithSuspense(PlayerPage, 'Loading player…')}
          />
          <Route
            path="playlist/:playlistId"
            element={renderWithSuspense(PlayerPage, 'Loading player…')}
          />
          <Route
            path="playlist/:playlistId/:start"
            element={renderWithSuspense(PlayerPage, 'Loading player…')}
          />
          <Route
            path="playlist-thumbs/:playlistId"
            element={renderWithSuspense(PlaylistThumbsPage, 'Loading palettes…')}
          />
          <Route
            path="picker"
            element={renderWithSuspense(PlaylistPickerPage, 'Loading picker…')}
          />
          <Route
            path="playlist-color-search"
            element={renderWithSuspense(PlaylistColorSearchPage, 'Loading color search...')}
          />
        </Route>

        {/* App shell (nav, etc.) */}
        <Route element={<App />}>
          
          {/* USER-FACING PAGES ⤵ wrapped by MainLayout (capped, centered) */}
          <Route element={<MainLayout />}>
            <Route index element={<Navigate to="/results/4" replace />} />
            <Route path="search" element={renderWithSuspense(SearchPage, 'Loading search…')} />
            <Route path="results/:queryId" element={renderWithSuspense(GalleryPage, 'Loading results…')} />
            <Route path="color/:id" element={renderWithSuspense(ColorDetailPage, 'Loading color…')} />
            <Route path="sbs" element={renderWithSuspense(SideBySidePage, 'Loading comparison…')} />
            <Route path="my-palette" element={renderWithSuspense(MyPalettePage, 'Loading palette…')} />
            <Route path="adv-search" element={renderWithSuspense(AdvancedSearchPage, 'Loading search…')} />
            <Route path="adv-results" element={renderWithSuspense(AdvancedResultsPage, 'Loading results…')} />
            <Route path="login" element={renderWithSuspense(LoginPage, 'Loading login…')} />
            <Route path="about" element={renderWithSuspense(AboutPage, 'Loading about…')} />
            <Route path="privacy" element={renderWithSuspense(PrivacyPage, 'Loading privacy policy…')} />
            <Route path="s/:slug" element={renderWithSuspense(LandingPage, 'Loading landing page…')} />
            <Route path="send-note" element={renderWithSuspense(SendNotePage, 'Loading note form…')} />
            <Route path="hire-terry" element={renderWithSuspense(HireTerryPage, 'Loading service page…')} />
            <Route path="hire-terry/request-playlist" element={renderWithSuspense(RequestPlaylistPage, 'Loading request page…')} />
            <Route path="matches" element={renderWithSuspense(MatchResultsPage, 'Loading matches…')} />
           <Route path="quick-find" element={renderWithSuspense(QuickFindPage, 'Loading quick find…')} />
           <Route path="browse-palettes" element={renderWithSuspense(BrowsePalettesPage, 'Loading palettes…')} />
           <Route path="palette/:id/brands" element={renderWithSuspense(PaletteTranslationPage, 'Loading palette translation…')} />
           <Route path="/palette/translate" element={renderWithSuspense(PaletteTranslationPage, 'Loading palette translation…')} />   
            <Route path="articles/:id" element={renderWithSuspense(ArticlePage, 'Loading article…')} />

           
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
