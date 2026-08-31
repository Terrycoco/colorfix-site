import { Suspense, lazy } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext.jsx';
import { isAdmin } from '@helpers/authHelper';
import '@layout/MainLayout/mainlayout.css';

const AdminLayout = lazy(() => import('@layout/AdminLayout'));
const LoginPage = lazy(() => import('@pages/login/LoginPage'));
const GalleryPage = lazy(() => import('@pages/GalleryPage'));
const ColorDetailPage = lazy(() => import('@pages/ColorDetailPage'));
const SearchPage = lazy(() => import('@pages/SearchPage'));
const QuickFindPage = lazy(() => import('@pages/QuickFindPage'));
const BrowsePalettesPage = lazy(() => import('@pages/BrowsePalettesPage'));
const MatchResultsPage = lazy(() => import('@pages/MatchResultsPage'));
const SideBySidePage = lazy(() => import('@pages/SideBySidePage'));
const AdvancedSearchPage = lazy(() => import('@pages/AdvancedSearchPage'));
const AdvancedResultsPage = lazy(() => import('@pages/AdvancedResultsPage'));
const MyPalettePage = lazy(() => import('@pages/MyPalettePage'));
const PaletteTranslationPage = lazy(() => import('@pages/PaletteTranslationPage'));
const ArticlePage = lazy(() => import('@pages/ArticlePage'));
const CategoryEditPage = lazy(() => import('@pages/CategoryEditPage'));
const AdminColorEditPage = lazy(() => import('@pages/AdminColorEditPage'));
const SearchPresetPage = lazy(() => import('@pages/SearchPresetPage'));
const SQLPage = lazy(() => import('@pages/SQLPage'));
const ItemEditPage = lazy(() => import('@pages/ItemEditPage'));
const FilterEditPage = lazy(() => import('@pages/FilterEditPage'));
const FriendsEnterPage = lazy(() => import('@pages/FriendsEnterPage'));
const MissingChipsPage = lazy(() => import('@pages/MissingChipsPage'));
const WhitesLrvEditorPage = lazy(() => import('@pages/whitesLrvEditorPage'));
const AdminUploadPhotoPage = lazy(() => import('@pages/AdminUploadPhotoPage'));
const AdminRolesMasksPage = lazy(() => import('@pages/AdminRolesMasksPage'));
const AdminSupercatsPage = lazy(() => import('@pages/AdminSupercatsPage'));
const AdminSavedPalettesPage = lazy(() => import('@pages/AdminSavedPalettesPage'));
const AdminPaletteViewersPage = lazy(() => import('@pages/AdminPaletteViewersPage'));
const AdminPalettePhotosPage = lazy(() => import('@pages/AdminPalettePhotosPage'));
const AdminKickersPage = lazy(() => import('@pages/AdminKickersPage'));
const AdminIdeasPage = lazy(() => import('@pages/AdminIdeasPage'));
const AdminArticlesPage = lazy(() => import('@pages/AdminArticlesPage'));
const AdminProjectsPage = lazy(() => import('@pages/AdminProjectsPage'));
const AdminPropertiesPage = lazy(() => import('@pages/AdminPropertiesPage'));
const AdminSharePage = lazy(() => import('@pages/AdminSharePage'));
const AdminAssetLibraryPage = lazy(() => import('@pages/AdminAssetLibraryPage'));
const AdminAssetCreatorsPage = lazy(() => import('@pages/AdminAssetCreatorsPage'));
const AdminPublishingDefaultsPage = lazy(() => import('@pages/AdminPublishingDefaultsPage'));
const AdminAssetAnalyticsPage = lazy(() => import('@pages/AdminAssetAnalyticsPage'));
const AdminPackagerPage = lazy(() => import('@pages/AdminPackagerPage'));
const AdminPublishingPage = lazy(() => import('@pages/AdminPublishingPage'));
const AdminPublicationSchedulerPage = lazy(() => import('@pages/AdminPublicationSchedulerPage'));
const AdminLandingPagesPage = lazy(() => import('@pages/AdminLandingPagesPage'));
const AdminPinterestPublisherPage = lazy(() => import('@pages/AdminPinterestPublisherPage'));
const AdminUserEventsPage = lazy(() => import('@pages/AdminUserEventsPage'));
const AdminQrSheetsPage = lazy(() => import('@pages/AdminQrSheetsPage'));
const AdminUrlReservationsPage = lazy(() => import('@pages/AdminUrlReservationsPage'));
const AdminRexConversionPage = lazy(() => import('@pages/AdminRexConversionPage'));
const AdminPhotoLibraryPage = lazy(() => import('@pages/AdminPhotoLibraryPage'));
const AdminPhotoLibraryToolsPage = lazy(() => import('@pages/AdminPhotoLibraryToolsPage'));
const AdminFileLockerPage = lazy(() => import('@pages/AdminFileLockerPage'));
const AdminClientsPage = lazy(() => import('@pages/AdminClientsPage'));
const AdminEmailTemplatesPage = lazy(() => import('@pages/AdminEmailTemplatesPage'));
const AdminPlayerPage = lazy(() => import('@pages/AdminPlayerPage'));
const AdminPlaylistPresenterPage = lazy(() => import('@pages/AdminPlaylistPresenterPage'));
const AdminPlaylistInstancesPage = lazy(() => import('@pages/AdminPlaylistInstancesPage'));
const AdminPlaylistInstanceSetsPage = lazy(() => import('@pages/AdminPlaylistInstanceSetsPage'));
const AdminPlaylistEditorPage = lazy(() => import('@pages/AdminPlaylistEditorPage'));
const AdminPlayerExperiencesPage = lazy(() => import('@pages/AdminPlayerExperiencesPage'));
const AdminCtasPage = lazy(() => import('@pages/AdminCtasPage'));
const AdminCtaPagesPage = lazy(() => import('@pages/AdminCtaPagesPage'));
const AdminPlaylistsPage = lazy(() => import('@pages/AdminPlaylistsPage'));
const PlayerPage = lazy(() => import('@pages/PlayerPage'));
const YoutubePlayerPage = lazy(() => import('@pages/YoutubePlayerPage'));
 const AdminAnalyticsPage = lazy(() => import('@pages/AdminAnalyticsPage'));

 const RexReservations = lazy(() => import('@REX/Reservations'));
 const RexRelationships = lazy(() => import("@REX/Relationships"));


const AdminPubPage = lazy(() => import("@pages/AdminPubPage"));
const Marketing = lazy(() => import("@components/Marketing/MarketingWorkspace"));



function renderWithSuspense(Component, label) {
  return (
    <Suspense fallback={<RouteFallback label={label} />}>
      <Component />
    </Suspense>
  );
}

export default function AdminRoutes() {
  const { user } = useAppState();
  const adminAllowed = Boolean(user?.is_admin) || isAdmin();

  if (!adminAllowed) {
    return (
      <Routes>
        <Route path="login" element={renderWithSuspense(LoginPage, 'Loading login...')} />
        <Route path="*" element={<Navigate to="/admin/login" replace />} />
      </Routes>
    );
  }

  return (
    <Routes>
      <Route path="login" element={<Navigate to="/admin/" replace />} />
      <Route index element={<AdminHomePage />} />
      <Route path="results/:queryId" element={<AdminHomePage />} />
      <Route path="youtube-player/:playlistId" element={renderWithSuspense(YoutubePlayerPage, 'Loading YouTube player...')} />
      <Route path="color/:id" element={<AdminPublicPage><ColorDetailPage /></AdminPublicPage>} />
      <Route path="search" element={<AdminPublicPage><SearchPage /></AdminPublicPage>} />
      <Route path="quick-find" element={<AdminPublicPage><QuickFindPage /></AdminPublicPage>} />
      <Route path="browse-palettes" element={<AdminPublicPage><BrowsePalettesPage /></AdminPublicPage>} />
      <Route path="matches" element={<AdminPublicPage><MatchResultsPage /></AdminPublicPage>} />
      <Route path="sbs" element={<AdminPublicPage><SideBySidePage /></AdminPublicPage>} />
      <Route path="adv-search" element={<AdminPublicPage><AdvancedSearchPage /></AdminPublicPage>} />
      <Route path="adv-results" element={<AdminPublicPage><AdvancedResultsPage /></AdminPublicPage>} />
      <Route path="my-palette" element={<AdminPublicPage><MyPalettePage /></AdminPublicPage>} />
      <Route path="palette/translate" element={<AdminPublicPage><PaletteTranslationPage /></AdminPublicPage>} />
      <Route path="palette/:id/brands" element={<AdminPublicPage><PaletteTranslationPage /></AdminPublicPage>} />
      <Route path="articles/:id" element={<AdminPublicPage><ArticlePage /></AdminPublicPage>} />
      <Route element={renderWithSuspense(AdminLayout, 'Loading admin shell...')}>
        <Route path="categories" element={renderWithSuspense(CategoryEditPage, 'Loading categories...')} />
        <Route path="colors" element={renderWithSuspense(AdminColorEditPage, 'Loading colors...')} />
        <Route path="search-presets" element={renderWithSuspense(SearchPresetPage, 'Loading presets...')} />
        <Route path="sql" element={renderWithSuspense(SQLPage, 'Loading SQL tools...')} />
        <Route path="items" element={renderWithSuspense(ItemEditPage, 'Loading items...')} />
        <Route path="filters" element={renderWithSuspense(FilterEditPage, 'Loading filters...')} />
        <Route path="friends" element={renderWithSuspense(FriendsEnterPage, 'Loading friends...')} />
        <Route path="missing-chips" element={renderWithSuspense(MissingChipsPage, 'Loading missing chips...')} />
        <Route path="lrv-editor" element={renderWithSuspense(WhitesLrvEditorPage, 'Loading LRV editor...')} />
        <Route path="upload-photo" element={renderWithSuspense(AdminUploadPhotoPage, 'Loading upload tool...')} />
        <Route path="roles-masks" element={renderWithSuspense(AdminRolesMasksPage, 'Loading admin roles/masks...')} />
        <Route path="supercats" element={renderWithSuspense(AdminSupercatsPage, 'Loading supercats...')} />
        <Route path="saved-palettes" element={renderWithSuspense(AdminSavedPalettesPage, 'Loading saved palettes...')} />
        <Route path="palette-viewers" element={renderWithSuspense(AdminPaletteViewersPage, 'Loading palette viewers...')} />
        <Route path="palette-photos" element={renderWithSuspense(AdminPalettePhotosPage, 'Loading palette photos...')} />
        <Route path="kickers" element={renderWithSuspense(AdminKickersPage, 'Loading kickers...')} />
        <Route path="ideas" element={renderWithSuspense(AdminIdeasPage, 'Loading ideas...')} />
        <Route path="milestones" element={<Navigate to="/admin/ideas?tab=milestones" replace />} />
        <Route path="articles" element={renderWithSuspense(AdminArticlesPage, 'Loading articles...')} />
        <Route path="projects" element={renderWithSuspense(AdminProjectsPage, 'Loading projects...')} />
        <Route path="properties" element={renderWithSuspense(AdminPropertiesPage, 'Loading properties...')} />
        <Route path="share" element={renderWithSuspense(AdminSharePage, 'Loading admin share...')} />
        <Route path="library" element={renderWithSuspense(AdminAssetLibraryPage, 'Loading library...')} />
        <Route path="asset-library" element={renderWithSuspense(AdminAssetLibraryPage, 'Loading library...')} />
        <Route path="asset-creators" element={renderWithSuspense(AdminAssetCreatorsPage, 'Loading asset creator...')} />
        <Route path="publishing-defaults" element={renderWithSuspense(AdminPublishingDefaultsPage, 'Loading publisher defaults...')} />
        <Route path="packager" element={renderWithSuspense(AdminPackagerPage, 'Loading packager...')} />
        <Route path="publishing" element={renderWithSuspense(AdminPublishingPage, 'Loading publishing...')} />
        <Route path="publisher" element={renderWithSuspense(AdminPublishingPage, 'Loading publisher...')} />
        <Route path="scheduler" element={renderWithSuspense(AdminPublicationSchedulerPage, 'Loading scheduler...')} />
        <Route path="landing-pages" element={renderWithSuspense(AdminLandingPagesPage, 'Loading landing pages...')} />
        <Route path="asset-analytics" element={renderWithSuspense(AdminAssetAnalyticsPage, 'Loading asset analytics...')} />
        <Route path="pinterest-publisher" element={renderWithSuspense(AdminPinterestPublisherPage, 'Loading Pinterest publisher...')} />
        <Route path="user-events" element={renderWithSuspense(AdminUserEventsPage, 'Loading view counts...')} />
        <Route path="url-reservations" element={renderWithSuspense(AdminUrlReservationsPage, 'Loading URL reservations...')} />
        <Route path="rex-conversion" element={renderWithSuspense(AdminRexConversionPage, 'Loading REX conversion...')} />
        <Route path="qr-sheets" element={renderWithSuspense(AdminQrSheetsPage, 'Loading QR sheets...')} />
        <Route path="photo-library" element={renderWithSuspense(AdminPhotoLibraryPage, 'Loading photo library...')} />
        <Route path="photo-library-tools" element={renderWithSuspense(AdminPhotoLibraryToolsPage, 'Loading photo library tools...')} />
        <Route path="file-locker" element={renderWithSuspense(AdminFileLockerPage, 'Loading file locker...')} />
        <Route path="clients" element={renderWithSuspense(AdminClientsPage, 'Loading clients...')} />
        <Route path="email-templates" element={renderWithSuspense(AdminEmailTemplatesPage, 'Loading email templates...')} />
        <Route path="send-note" element={<PublicPathRedirect stripPrefix="/admin" />} />
        <Route path="picker" element={<PublicPathRedirect stripPrefix="/admin" />} />
        <Route path="p/:playlistId" element={<PublicPathRedirect stripPrefix="/admin" />} />
        <Route path="p/:playlistId/:start" element={<PublicPathRedirect stripPrefix="/admin" />} />
        <Route path="playlist/:playlistId" element={<PublicPathRedirect stripPrefix="/admin" />} />
        <Route path="playlist/:playlistId/:start" element={<PublicPathRedirect stripPrefix="/admin" />} />
        <Route path="player/:playlistId" element={renderWithSuspense(PlayerPage, 'Loading player...')} />
        <Route path="player/:playlistId/:start" element={renderWithSuspense(PlayerPage, 'Loading player...')} />
        <Route path="player-preview/:playlistId" element={renderWithSuspense(AdminPlayerPage, 'Loading player preview...')} />
        <Route path="player-preview/:playlistId/:start" element={renderWithSuspense(AdminPlayerPage, 'Loading player preview...')} />
        <Route path="player-presenter" element={renderWithSuspense(AdminPlaylistPresenterPage, 'Loading presenter...')} />
        <Route path="playlist-instances" element={renderWithSuspense(AdminPlaylistInstancesPage, 'Loading playlist instances...')} />
        <Route path="player-experiences" element={renderWithSuspense(AdminPlayerExperiencesPage, 'Loading player experiences...')} />
        <Route path="playlist-instance-sets" element={renderWithSuspense(AdminPlaylistInstanceSetsPage, 'Loading playlist instance sets...')} />
        <Route path="ctas" element={renderWithSuspense(AdminCtasPage, 'Loading CTAs...')} />
        <Route path="cta-pages" element={renderWithSuspense(AdminCtaPagesPage, 'Loading CTA pages...')} />
        <Route path="playlists/:playlistId" element={renderWithSuspense(AdminPlaylistEditorPage, 'Loading playlist editor...')} />
        <Route path="playlists/new" element={renderWithSuspense(AdminPlaylistEditorPage, 'Loading playlist editor...')} />
        <Route path="playlists" element={renderWithSuspense(AdminPlaylistsPage, 'Loading playlists...')} />
       <Route path="analytics" element={renderWithSuspense(AdminAnalyticsPage, 'Loading analytics...')}/>
  
        <Route path="pub" element={renderWithSuspense(AdminPubPage, "Loading PUB...")}/>
          <Route path="marketing" element={renderWithSuspense(Marketing, "Loading Mark...")}/>
        <Route path="rex" element={renderWithSuspense(RexReservations, 'Loading REX...')}/>
          <Route path="rexrelationships" element={renderWithSuspense(RexRelationships, "Loading REX...")}/>  
      </Route>
    </Routes>
  );
}

function AdminHomePage() {
  return (
    <main className="main-layout">
      <Suspense fallback={<RouteFallback label="Loading home..." />}>
        <GalleryPage defaultQueryId={4} />
      </Suspense>
    </main>
  );
}

function AdminPublicPage({ children }) {
  return (
    <main className="main-layout">
      <Suspense fallback={<RouteFallback label="Loading page..." />}>
        {children}
      </Suspense>
    </main>
  );
}

function RouteFallback({ label }) {
  return (
    <div className="route-loader" role="status" aria-live="polite">
      {label}
    </div>
  );
}

function PublicPathRedirect({ stripPrefix }) {
  const location = useLocation();
  const from = `${location.pathname}${location.search}${location.hash}`;
  const target = from.startsWith(stripPrefix)
    ? from.slice(stripPrefix.length) || "/"
    : from;
  window.location.replace(target);
  return null;
}
