// src/Router.jsx
import { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import App from './App.jsx';
import MainLayout from '@layout/MainLayout';
import ScrollToTop from '@layout/ScrollToTop';

const AboutPage = lazy(() => import('@pages/AboutPage'));
const HireTerryPage = lazy(() => import('@pages/HireTerryPage'));
const RequestPlaylistPage = lazy(() => import('@pages/HireTerryPage/RequestPlaylistPage'));
const LoginPage = lazy(() => import('@pages/login/LoginPage'));
const SearchPage = lazy(() => import('@pages/SearchPage'));
const MobileDetailPage = lazy(() => import('@pages/MobileDetailPage'));
const GalleryPage = lazy(() => import('@pages/GalleryPage'));
const SideBySidePage = lazy(() => import('@pages/SideBySidePage'));
const MyPalettePage = lazy(() => import('@pages/MyPalettePage'));
const AdvancedSearchPage = lazy(() => import('@pages/AdvancedSearchPage'));
const AdvancedResultsPage = lazy(() => import('@pages/AdvancedResultsPage'));
const MatchResultsPage = lazy(() => import('@pages/MatchResultsPage'));
const QuickFindPage = lazy(() => import('@pages/QuickFindPage'));
const BrowsePalettesPage = lazy(() => import('@pages/BrowsePalettesPage'));
const PaletteTranslationPage = lazy(() => import('@pages/PaletteTranslationPage'));
const HOALandingPage = lazy(() => import("@pages/HOAPage").then((mod) => ({ default: mod.HOALandingPage })));
const HOAExplainerPage = lazy(() => import("@pages/HOAPage").then((mod) => ({ default: mod.HOAExplainerPage })));
const HOAContactPage = lazy(() => import("@pages/HOAPage").then((mod) => ({ default: mod.HOAContactPage })));

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
const AdminPalettePhotosPage = lazy(() => import('@pages/AdminPalettePhotosPage'));
const AdminKickersPage = lazy(() => import('@pages/AdminKickersPage'));
const AdminIdeasPage = lazy(() => import('@pages/AdminIdeasPage'));
const AdminArticlesPage = lazy(() => import('@pages/AdminArticlesPage'));
const AdminProjectsPage = lazy(() => import('@pages/AdminProjectsPage'));
const AdminQrSheetsPage = lazy(() => import('@pages/AdminQrSheetsPage'));
const AdminPhotoLibraryPage = lazy(() => import('@pages/AdminPhotoLibraryPage'));
const AdminMaskTesterPage = lazy(() => import('@pages/AdminMaskTesterPage'));
const AdminAppliedPalettesPage = lazy(() => import('@pages/AdminAppliedPalettesPage'));
const AdminAppliedPaletteEditorPage = lazy(() => import('@pages/AdminAppliedPaletteEditorPage'));
const AdminPlayerPage = lazy(() => import('@pages/AdminPlayerPage'));
const AdminPlaylistPresenterPage = lazy(() => import('@pages/AdminPlaylistPresenterPage'));
const AdminPlaylistInstancesPage = lazy(() => import('@pages/AdminPlaylistInstancesPage'));
const AdminPlaylistInstanceSetsPage = lazy(() => import('@pages/AdminPlaylistInstanceSetsPage'));
const AdminPlaylistEditorPage = lazy(() => import('@pages/AdminPlaylistEditorPage'));
const AdminHOAPage = lazy(() => import('@pages/AdminHOAPage'));
const AdminHoaSchemeTesterPage = lazy(() => import('@pages/AdminHoaSchemeTesterPage'));
const AdminHoaMaskTesterPage = lazy(() => import('@pages/AdminHoaMaskTesterPage'));
const AdminCtasPage = lazy(() => import('@pages/AdminCtasPage'));
const AdminPlaylistsPage = lazy(() => import('@pages/AdminPlaylistsPage'));
const AppliedPaletteViewPage = lazy(() => import('@pages/AppliedPaletteViewPage'));
const PrintAppliedPalettePage = lazy(() => import('@pages/PrintAppliedPalettePage'));
const PrintMyPalettePage = lazy(() => import('@pages/PrintMyPalettePage'));
const PlayerPage = lazy(() => import('@pages/PlayerPage'));
const StandAloneLayout = lazy(() => import('@layout/StandAloneLayout'));
const PlaylistThumbsPage = lazy(() => import('@pages/PlaylistThumbsPage'));
const PlaylistPickerPage = lazy(() => import('@pages/PlaylistPickerPage'));
const SavedPaletteSharePage = lazy(() => import('@pages/SavedPaletteSharePage'));
const ArticlePage = lazy(() => import('@pages/ArticlePage'));


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
        <Route
          path="palette/:hash/share"
          element={renderWithSuspense(SavedPaletteSharePage, 'Loading saved palette…')}
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
          <Route
            path="playlist-thumbs/:playlistId"
            element={renderWithSuspense(PlaylistThumbsPage, 'Loading palettes…')}
          />
          <Route
            path="picker"
            element={renderWithSuspense(PlaylistPickerPage, 'Loading picker…')}
          />
        </Route>

        {/* App shell (nav, etc.) */}
        <Route element={<App />}>
          
          {/* USER-FACING PAGES ⤵ wrapped by MainLayout (capped, centered) */}
          <Route element={<MainLayout />}>
            <Route index element={<Navigate to="/results/4" replace />} />
            <Route path="search" element={renderWithSuspense(SearchPage, 'Loading search…')} />
            <Route path="results/:queryId" element={renderWithSuspense(GalleryPage, 'Loading results…')} />
            <Route path="color/:id" element={renderWithSuspense(MobileDetailPage, 'Loading color…')} />
            <Route path="sbs" element={renderWithSuspense(SideBySidePage, 'Loading comparison…')} />
            <Route path="my-palette" element={renderWithSuspense(MyPalettePage, 'Loading palette…')} />
            <Route path="adv-search" element={renderWithSuspense(AdvancedSearchPage, 'Loading search…')} />
            <Route path="adv-results" element={renderWithSuspense(AdvancedResultsPage, 'Loading results…')} />
            <Route path="login" element={renderWithSuspense(LoginPage, 'Loading login…')} />
            <Route path="about" element={renderWithSuspense(AboutPage, 'Loading about…')} />
            <Route path="hire-terry" element={renderWithSuspense(HireTerryPage, 'Loading service page…')} />
            <Route path="hire-terry/request-playlist" element={renderWithSuspense(RequestPlaylistPage, 'Loading request page…')} />
            <Route path="matches" element={renderWithSuspense(MatchResultsPage, 'Loading matches…')} />
           <Route path="quick-find" element={renderWithSuspense(QuickFindPage, 'Loading quick find…')} />
           <Route path="browse-palettes" element={renderWithSuspense(BrowsePalettesPage, 'Loading palettes…')} />
           <Route path="palette/:id/brands" element={renderWithSuspense(PaletteTranslationPage, 'Loading palette translation…')} />
           <Route path="/palette/translate" element={renderWithSuspense(PaletteTranslationPage, 'Loading palette translation…')} />   
           <Route path="/hoa" element={renderWithSuspense(HOALandingPage, 'Loading HOA…')} />
            <Route path="/hoa/explain" element={renderWithSuspense(HOAExplainerPage, 'Loading HOA info…')} />
            <Route path="/hoa/contact" element={renderWithSuspense(HOAContactPage, 'Loading contact…')} />
            <Route path="articles/:id" element={renderWithSuspense(ArticlePage, 'Loading article…')} />

           
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
              path="palette-photos"
              element={renderWithSuspense(AdminPalettePhotosPage, 'Loading palette photos…')}
            />
            <Route
              path="kickers"
              element={renderWithSuspense(AdminKickersPage, 'Loading kickers…')}
            />
            <Route
              path="ideas"
              element={renderWithSuspense(AdminIdeasPage, 'Loading ideas…')}
            />
            <Route
              path="articles"
              element={renderWithSuspense(AdminArticlesPage, 'Loading articles…')}
            />
            <Route
              path="projects"
              element={renderWithSuspense(AdminProjectsPage, 'Loading projects…')}
            />
            <Route
              path="qr-sheets"
              element={renderWithSuspense(AdminQrSheetsPage, 'Loading QR sheets…')}
            />
            <Route
              path="photo-library"
              element={renderWithSuspense(AdminPhotoLibraryPage, 'Loading photo library…')}
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
              path="player-presenter"
              element={renderWithSuspense(AdminPlaylistPresenterPage, 'Loading presenter…')}
            />
            <Route
              path="playlist-instances"
              element={renderWithSuspense(AdminPlaylistInstancesPage, 'Loading playlist instances…')}
            />
            <Route
              path="playlist-instance-sets"
              element={renderWithSuspense(AdminPlaylistInstanceSetsPage, 'Loading playlist instance sets…')}
            />
            <Route
              path="ctas"
              element={renderWithSuspense(AdminCtasPage, 'Loading CTAs…')}
            />
            <Route
              path="hoas"
              element={renderWithSuspense(AdminHOAPage, 'Loading HOAs…')}
            />




            <Route
              path="hoa-scheme-tester"
              element={renderWithSuspense(AdminHoaSchemeTesterPage, 'Loading HOA scheme tester…')}
            />
            <Route
              path="hoa-mask-tester"
              element={renderWithSuspense(AdminHoaMaskTesterPage, 'Loading HOA mask tester…')}
            />
            <Route
              path="playlists/:playlistId"
              element={renderWithSuspense(AdminPlaylistEditorPage, 'Loading playlist editor…')}
            />
            <Route
              path="playlists/new"
              element={renderWithSuspense(AdminPlaylistEditorPage, 'Loading playlist editor…')}
            />
            <Route
              path="playlists"
              element={renderWithSuspense(AdminPlaylistsPage, 'Loading playlists…')}
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
