// src/Router.jsx
import { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useLocation, useParams } from 'react-router-dom';
import { useEffect } from 'react';
import App from './App.jsx';
import ANAProvider from "@ANA/ANAProvider";
import ANATrack from "@ANA/ANATrack";
import MainLayout from '@layout/MainLayout';
import ScrollToTop from '@layout/ScrollToTop';
import PlayerPage from '@pages/PlayerPage';
import StandAloneLayout from '@layout/StandAloneLayout';



const HomePage = lazy(() => import('@pages/HomePage'));
const AboutPage = lazy(() => import('@pages/AboutPage'));
const HireTerryPage = lazy(() => import('@pages/HireTerryPage'));
const RequestPlaylistPage = lazy(() => import('@pages/HireTerryPage/RequestPlaylistPage'));
const SendNotePage = lazy(() => import('@pages/SendNotePage'));

const PrivacyPage = lazy(() => import('@pages/PrivacyPage'));
const TermsPage = lazy(() => import('@pages/PrivacyPage/terms.jsx'));
const PubInfoPage = lazy(() => import('@pages/PubInfoPage'));

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
const RexPublicPage = lazy(() => import('@pages/REX/RexPublicPage'));
const ProjectPainterSpecsPage = lazy(() => import('@pages/ProjectPainterSpecsPage'));
const ClientViewerTestPage = lazy(() => import('@pages/Viewers/ClientViewerTestPage'));
const RexManagementDialogTestPage = lazy(() => import('@pages/REX/RexManagementDialogTestPage'));
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
        <ANAProvider>
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
          path="pv/:token"
          element={renderWithSuspense(SavedPaletteSharePage, 'Loading saved palette…')}
        />
        <Route
          path="t/:token"
          element={renderWithSuspense(RexPublicPage, 'Loading ColorFix link…')}
        />
        <Route
          path="project-painter-specs"
          element={renderWithSuspense(ProjectPainterSpecsPage, 'Loading painter specs…')}
        />
        <Route
          path="test/client-viewer"
          element={renderWithSuspense(ClientViewerTestPage, 'Loading client viewer test…')}
        />
        <Route
          path="test/rex-management"
          element={renderWithSuspense(RexManagementDialogTestPage, 'Loading REX dialog test…')}
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
            <Route
              index
              element={renderWithSuspense(HomePage, 'Loading home…')}
            />
            <Route path="search" element={renderWithSuspense(SearchPage, 'Loading search…')} />
           <Route path="results/:queryId" element={<GalleryRoute />} />
            <Route path="color/:id" element={renderWithSuspense(ColorDetailPage, 'Loading color…')} />
            <Route path="sbs" element={renderWithSuspense(SideBySidePage, 'Loading comparison…')} />
            <Route path="my-palette" element={renderWithSuspense(MyPalettePage, 'Loading palette…')} />
            <Route path="adv-search" element={renderWithSuspense(AdvancedSearchPage, 'Loading search…')} />
            <Route path="adv-results" element={renderWithSuspense(AdvancedResultsPage, 'Loading results…')} />
            <Route path="login" element={renderWithSuspense(LoginPage, 'Loading login…')} />
            <Route path="about" element={renderWithSuspense(AboutPage, 'Loading about…')} />
            <Route path="privacy" element={renderWithSuspense(PrivacyPage, 'Loading privacy policy…')} />
            <Route path="terms" element={renderWithSuspense(TermsPage, 'Loading terms of service…')} />
            <Route path="pub-info" element={renderWithSuspense(PubInfoPage, "Loading info page...")} />

            <Route path="s/:slug" element={renderWithSuspense(LandingPage, 'Loading landing page…')} />
            <Route path="send-note" element={renderWithSuspense(SendNotePage, 'Loading note form…')} />
            <Route path="hire-terry" element={renderWithSuspense(HireTerryPage, 'Loading service page…')} />
            <Route path="hire-terry/request-playlist" element={renderWithSuspense(RequestPlaylistPage, 'Loading request page…')} />
            <Route path="matches" element={renderWithSuspense(MatchResultsPage, 'Loading matches…')} />
           <Route path="quick-find" element={renderWithSuspense(QuickFindPage, 'Loading quick find…')} />
           <Route path="browse-palettes" element={renderWithSuspense(BrowsePalettesPage, 'Loading palettes…')} />
           <Route path="palette/:id/brands" element={renderWithSuspense(PaletteTranslationPage, 'Loading palette translation…')} />
           <Route path="/palette/translate" element={renderWithSuspense(PaletteTranslationPage, 'Loading palette translation…')} />   
          <Route
              path="articles/:id"
              element={<ArticleRoute />}
            />
           
          </Route>
        </Route>
      </Routes>
      </ANAProvider>
    </BrowserRouter>
  );
}



function GalleryRoute() {
  const { queryId } = useParams();
  const isHomePage = Number(queryId) === 4;

  const page = (
    <Suspense fallback={<RouteFallback label={isHomePage ? "Loading home…" : "Loading results…"} />}>
      {isHomePage ? <HomePage /> : <GalleryPage />}
    </Suspense>
  );

  if (!isHomePage) {
    return page;
  }

  return (
    <ANATrack
      oncePerSession
      resource={{
        resource_type: "page",
        resource_id: 1,
      }}
    >
      {page}
    </ANATrack>
  );
}

function ArticleRoute() {
  const { id } = useParams();

  const article = (
    <Suspense fallback={<RouteFallback label="Loading article…" />}>
      <ArticlePage />
    </Suspense>
  );

  const articleId = Number(id);

  if (!Number.isInteger(articleId) || articleId <= 0) {
    return article;
  }

  return (
    <ANATrack
      resource={{
        resource_type: "article",
        resource_id: articleId,
      }}
    >
      {article}
    </ANATrack>
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
