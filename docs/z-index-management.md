# ColorFix z-index management

Implemented October 19, 2026. The Phase 1 audit was reported before code changes; Phase 2 follows the approved global hierarchy.

## Ownership and hierarchy

`src/styles/ZIndex.css` is the only application layer definition. It is imported once by each independent entry: public (`main.jsx`), admin (`admin-main.jsx`), player (`player-main.jsx`), and SAS (`SAS/main.jsx`).

| Token | Value | Role |
|---|---:|---|
| `--z-back` | -1 | Decorative background |
| `--z-base` | 0 | Base content |
| `--z-above` | 1 | Local foreground |
| `--z-label` | 2 | Local labels |
| `--z-control` | 3 | Local controls |
| `--z-active` | 4 | Active local controls |
| `--z-drag` | 5 | Local drag elevation |
| `--z-sticky` | 100 | Sticky content controls |
| `--z-nav` | 200 | Navigation and persistent bars |
| `--z-dropdown` | 300 | Menus and dropdowns |
| `--z-shield` | 400 | Temporary click shield |
| `--z-fullscreen` | 500 | Full-screen workspace |
| `--z-drawer` | 600 | Desktop/mobile drawers |
| `--z-editor` | 700 | Editors and photo previews |
| `--z-dialog` | 800 | Dialogs above drawers/editors |
| `--z-picker` | 900 | Full-screen selectors |
| `--z-critical` | 1000 | Blocking alerts/confirmations |
| `--z-toast` | 1100 | Notifications |

Local values order children within their component's stacking context. They are not application overlay priorities. Player CSS retains its original ordering by composing local steps inside an isolated `.player-root`; its controls no longer compete with application overlays.

The competing AdminTokens/global.css/BrowsePalettes definitions were removed. Page-specific `!important` layering overrides in BrowsePalettes were removed. Existing page-owned surfaces reference global roles, with no new page-specific priority overrides.

## Existing shared components

- AdminLayout's AdminDialog is the single dialog implementation and owns its stylesheet. The legacy AdminDialog entry delegates to it; its compatibility CSS only imports the same stylesheet. Duplicate dialog rules/keyframes were removed from AdminFeedback. Dialog title/message IDs are unique.
- `useOverlayLifecycle` supports the existing components with one document keyboard owner, nested focus containment, cancellation of pending initial focus, focus restoration, lower-overlay inert state, and a shared body scroll lock. Callback changes do not restart focus effects. No new overlay renderer/provider or component family was added.
- Nested portals derive their priority from their shared role and owner plus `--z-above`, capped below `--z-toast`. Thus a dialog opened from a mobile drawer remains above it, and feedback opened from an editor/picker remains accessible.
- Escape is handled once by the top surface. Busy/non-dismissible surfaces block it from reaching their owners. Non-modal workspaces without a dismissal callback leave Escape to their content.
- Shared modal roots mount on `document.body` to escape ancestor transforms and clipping. AdminWorkbenchDrawer retains inline desktop behavior when `portal` is false. Desktop portal drawers remain non-modal; mobile drawers remain modal.
- AdminDialog, AdminEditor, mobile drawers/selectors, PUB MusicEditor, MarketingModal, generic modals, document/photo previews, PaletteInspector, and SavedPaletteEditorModal share the lifecycle support.
- Public Toast and MessagePopup now portal to body, matching AdminToast. Notifications stay above and reachable from overlays.
- Shared fuzzy results, TagMultiSelect, DropdownWithNote, BrandFilterDropdown, and EditableSwatch use body portals with shared anchor geometry, resize/scroll tracking, viewport limits, and top/bottom placement. Outside-click checks include owned portal children, including mobile selectors opened from EditableSwatch and MaskRoleGrid.
- AdminFullScreenOverlay supports its existing workspace role and a modal preview role. Project and palette photo previews reuse that shared component.

## Verified behavior

Commands executed:

```sh
node scripts/check-z-index.mjs
node scripts/test-overlay-layers.mjs
npm run build
npm run build:sas
```

The browser runner uses installed Chrome through the existing Remotion renderer dependency. Set `COLORFIX_CHROME` to an installed executable when the default macOS Chrome path does not apply. It starts a local Vite fixture. No additional browser package is required.

All 14 Chrome checks passed:

1. Actual ERR page: Edit drawer → Recovery plan → cancel; Edit → Delete confirmation → Escape; desktop at 1280px.
2. The same ERR workflow at 390px, with mobile drawer ordering and retained body lock.
3. Dialog → MobileSelect → Escape return, focus restoration and Tab containment.
4. DropdownWithNote escapes a clipping ancestor; options receive real pointer hits; Escape closes the dropdown before the dialog.
5. Fuzzy desktop results and TagMultiSelect portal visibility and selection.
6. Dialog → fuzzy mobile selector → Escape and focus return.
7. EditableSwatch → mobile selector: clicking/searching inside the selector does not dismiss its owning popover; Escape returns through owners.
8. MobileGridDrawer → feedback dialog → return, preserving and releasing scroll locks correctly.
9. PUB MusicEditor → feedback: ordering, busy Escape protection, public/admin toast visibility, and pointer access to dismiss a toast above overlays.
10. AdminEditor → feedback: Escape leaves the editor open and restores focus.
11. AdminEditor → fullscreen PhotoRenderer preview: portal priority and Escape return without closing the editor.
12. SavedPaletteEditorModal → painter note: nested body portal, priority and Escape return.
13. PaletteInspector body portal and Escape dismissal.
14. Production player CSS: original image/end-screen/control ordering remains monotonic within an isolated player root.

These are actual headless Chrome viewport tests with CDP mouse/key input, production components and deterministic mocked API responses. They do not execute recovery, deletion, saving, or live backend requests. They are not physical-device/Safari tests or an exhaustive visual regression suite.

The static guard passed: approved token values, one import per entry, no application z-index literals, no competing/obsolete definitions, and no unknown static token references. The main production build and SAS build passed. `git diff --check` passed.

Focused lint for the lifecycle/shared-overlay changes and fixture passed. Lint across all changed source JS/JSX still reports 16 existing errors in unrelated code (for example unused variables and pre-existing undefined references). Comparing each changed file with its HEAD version found no new diagnostics; the baseline had 26 errors.

## Retained exceptions

- `src/remotion/**` retains intentional data-driven `box.z`/`layer.style` composition and its existing artwork layers.
- The three animated logo stylesheets (`components/BrandBumperLogo`, `components/Logos/BlackAnimated`, `components/Logos/WhiteAnimated`) and `scripts/export-brand-bumper-logo.mjs` retain their artwork-local value of 2.
- `z-index: auto` remains where the original component intentionally returns to natural stacking.
- AutoHideFooter keeps its existing caller-supplied `zIndex` prop; its default now uses `--z-nav`. There are no hardcoded application call-site overrides.

The inactive old playlist admin stylesheet was also migrated to shared local tokens. The player's early HTML boot surface now uses `--z-above`; before application CSS arrives it follows natural stacking, with only boot content present.

## Complete changed-file inventory

| File | Shared tokens / change |
|---|---|
| [docs/z-index-management.md](../docs/z-index-management.md) | Hierarchy, validation, exceptions and inventory |
| [old-playlist-set-admin/admin-playlist-instance-sets.css](../old-playlist-set-admin/admin-playlist-instance-sets.css) | `--z-active`, `--z-label` |
| [player/index.html](../player/index.html) | `--z-above` |
| [scripts/check-z-index.mjs](../scripts/check-z-index.mjs) | Validation fixture / runner |
| [scripts/test-overlay-layers.mjs](../scripts/test-overlay-layers.mjs) | Validation fixture / runner |
| [src/REX/Relationships/index.jsx](../src/REX/Relationships/index.jsx) | `--z-label` |
| [src/REX/admin-rex.css](../src/REX/admin-rex.css) | `--z-above`, `--z-dialog` |
| [src/SAS/components/photo-id.css](../src/SAS/components/photo-id.css) | `--z-above` |
| [src/SAS/main.jsx](../src/SAS/main.jsx) | Single root import |
| [src/SAS/sas.css](../src/SAS/sas.css) | `--z-sticky` |
| [src/admin-main.jsx](../src/admin-main.jsx) | Single root import |
| [src/components/AddressModal/address-modal.css](../src/components/AddressModal/address-modal.css) | `--z-dialog` |
| [src/components/AddressModal/index.jsx](../src/components/AddressModal/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminDialog/AdminDialog.css](../src/components/AdminDialog/AdminDialog.css) | Removed competing definitions / duplicate styling |
| [src/components/AdminDialog/AdminDialog.jsx](../src/components/AdminDialog/AdminDialog.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/AdminDialog.jsx](../src/components/AdminLayout/AdminDialog.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/AdminEditor.jsx](../src/components/AdminLayout/AdminEditor.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/AdminFullScreenOverlay.jsx](../src/components/AdminLayout/AdminFullScreenOverlay.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/AdminToast.jsx](../src/components/AdminLayout/AdminToast.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/AdminWorkbenchDrawer.jsx](../src/components/AdminLayout/AdminWorkbenchDrawer.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/ErrStatusDialog.css](../src/components/AdminLayout/ErrStatusDialog.css) | `--z-critical` |
| [src/components/AdminLayout/mobile/MobileDataGrid.css](../src/components/AdminLayout/mobile/MobileDataGrid.css) | `--z-drawer`, `--z-label` |
| [src/components/AdminLayout/mobile/MobileGridDrawer.jsx](../src/components/AdminLayout/mobile/MobileGridDrawer.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/mobile/MobileSelect.css](../src/components/AdminLayout/mobile/MobileSelect.css) | `--z-picker` |
| [src/components/AdminLayout/mobile/MobileSelect.jsx](../src/components/AdminLayout/mobile/MobileSelect.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/mobile/MobileWorkbenchDrawer.css](../src/components/AdminLayout/mobile/MobileWorkbenchDrawer.css) | `--z-above`, `--z-drawer` |
| [src/components/AdminLayout/mobile/MobileWorkbenchDrawer.jsx](../src/components/AdminLayout/mobile/MobileWorkbenchDrawer.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/AdminLayout/styles/AdminDetailPane.css](../src/components/AdminLayout/styles/AdminDetailPane.css) | `--z-control` |
| [src/components/AdminLayout/styles/AdminDialog.css](../src/components/AdminLayout/styles/AdminDialog.css) | `--z-dialog` |
| [src/components/AdminLayout/styles/AdminDrawer.css](../src/components/AdminLayout/styles/AdminDrawer.css) | `--z-drag`, `--z-drawer`, `--z-label` |
| [src/components/AdminLayout/styles/AdminFeedback.css](../src/components/AdminLayout/styles/AdminFeedback.css) | `--z-editor`, `--z-fullscreen`, `--z-toast` |
| [src/components/AdminLayout/styles/AdminGrid.css](../src/components/AdminLayout/styles/AdminGrid.css) | `--z-above` |
| [src/components/AdminLayout/styles/AdminTokens.css](../src/components/AdminLayout/styles/AdminTokens.css) | Removed competing definitions / duplicate styling |
| [src/components/AdminLayout/styles/index.css](../src/components/AdminLayout/styles/index.css) | Removed competing definitions / duplicate styling |
| [src/components/AdminMenu/adminmenu.css](../src/components/AdminMenu/adminmenu.css) | `--z-above`, `--z-dropdown`, `--z-nav` |
| [src/components/AutoHideFooter/autohidefooter.css](../src/components/AutoHideFooter/autohidefooter.css) | `--z-nav` |
| [src/components/AutoHideFooter/index.jsx](../src/components/AutoHideFooter/index.jsx) | `--z-nav` |
| [src/components/BoardScroller/boardscroller.css](../src/components/BoardScroller/boardscroller.css) | `--z-above`, `--z-nav`, `--z-sticky` |
| [src/components/BoardScroller/index.jsx](../src/components/BoardScroller/index.jsx) | `--z-control` |
| [src/components/BoardScrollerTrail/boardscroller.css](../src/components/BoardScrollerTrail/boardscroller.css) | `--z-nav` |
| [src/components/BrandFilter/BrandFilterDropdown.jsx](../src/components/BrandFilter/BrandFilterDropdown.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/BrandFilter/brandfilter.css](../src/components/BrandFilter/brandfilter.css) | `--z-dialog`, `--z-dropdown` |
| [src/components/BrandFilter/index.jsx](../src/components/BrandFilter/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/CategoryDropdown/dropdown.css](../src/components/CategoryDropdown/dropdown.css) | `--z-dropdown` |
| [src/components/ClientPickerModal/client-picker-modal.css](../src/components/ClientPickerModal/client-picker-modal.css) | `--z-above`, `--z-dialog` |
| [src/components/ClientPickerModal/index.jsx](../src/components/ClientPickerModal/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/ColorSearchBox/colorsearch.css](../src/components/ColorSearchBox/colorsearch.css) | `--z-above`, `--z-label` |
| [src/components/Documents/Document.css](../src/components/Documents/Document.css) | `--z-editor` |
| [src/components/Documents/DocumentPreview.css](../src/components/Documents/DocumentPreview.css) | `--z-editor` |
| [src/components/Documents/DocumentPreview.jsx](../src/components/Documents/DocumentPreview.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/DropdownWithNote/dropdownnote.css](../src/components/DropdownWithNote/dropdownnote.css) | `--z-dropdown` |
| [src/components/DropdownWithNote/index.jsx](../src/components/DropdownWithNote/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/EditableSwatch/editableswatch.css](../src/components/EditableSwatch/editableswatch.css) | `--z-dropdown` |
| [src/components/EditableSwatch/index.jsx](../src/components/EditableSwatch/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/EmailShareModal/EmailShareModal.jsx](../src/components/EmailShareModal/EmailShareModal.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/EmailShareModal/email-share-modal.css](../src/components/EmailShareModal/email-share-modal.css) | `--z-dialog` |
| [src/components/FuzzySearchColorSelect/fuzzysearch.css](../src/components/FuzzySearchColorSelect/fuzzysearch.css) | `--z-dialog`, `--z-dropdown`, `--z-label`, `--z-picker` |
| [src/components/FuzzySearchColorSelect/index.jsx](../src/components/FuzzySearchColorSelect/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/Gallery/gallery.css](../src/components/Gallery/gallery.css) | `--z-nav`, `--z-sticky` |
| [src/components/GalleryItems/NameSearchItem.jsx](../src/components/GalleryItems/NameSearchItem.jsx) | `--z-above`, `--z-drawer` |
| [src/components/GalleryItems/items.css](../src/components/GalleryItems/items.css) | `--z-above`, `--z-control` |
| [src/components/GalleryItems/searchitem.css](../src/components/GalleryItems/searchitem.css) | `--z-drawer` |
| [src/components/InfoCard/index.jsx](../src/components/InfoCard/index.jsx) | `--z-drawer` |
| [src/components/InfoCard/infocard.css](../src/components/InfoCard/infocard.css) | `--z-above`, `--z-control`, `--z-drawer` |
| [src/components/InsertLinkModal/insert-link-modal.css](../src/components/InsertLinkModal/insert-link-modal.css) | `--z-label` |
| [src/components/Marketing/MarketingModal.jsx](../src/components/Marketing/MarketingModal.jsx) | `--z-editor` |
| [src/components/MaskBlendHistory/maskblendhistory.css](../src/components/MaskBlendHistory/maskblendhistory.css) | `--z-above` |
| [src/components/MaskOverlayModal/index.jsx](../src/components/MaskOverlayModal/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/MaskOverlayModal/maskoverlaymodal.css](../src/components/MaskOverlayModal/maskoverlaymodal.css) | `--z-dialog` |
| [src/components/MaskRoleGrid/index.jsx](../src/components/MaskRoleGrid/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/MaskRoleGrid/mask-role-grid.css](../src/components/MaskRoleGrid/mask-role-grid.css) | `--z-drag` |
| [src/components/MessagePopup/index.jsx](../src/components/MessagePopup/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/MessagePopup/message.css](../src/components/MessagePopup/message.css) | `--z-toast` |
| [src/components/ModalDialog/index.jsx](../src/components/ModalDialog/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/ModalDialog/modal-dialog.css](../src/components/ModalDialog/modal-dialog.css) | `--z-dialog` |
| [src/components/NavBar/index.jsx](../src/components/NavBar/index.jsx) | `--z-above`, `--z-dropdown`, `--z-nav` |
| [src/components/PageDashboard/index.jsx](../src/components/PageDashboard/index.jsx) | `--z-nav` |
| [src/components/PaletteInspector/index.jsx](../src/components/PaletteInspector/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/PaletteInspector/paletteinspector.css](../src/components/PaletteInspector/paletteinspector.css) | `--z-dialog` |
| [src/components/PaletteRack/paletterack.css](../src/components/PaletteRack/paletterack.css) | `--z-drawer`, `--z-fullscreen` |
| [src/components/PermissionStatus/permission-status.css](../src/components/PermissionStatus/permission-status.css) | `--z-dropdown` |
| [src/components/PhotoRenderer/index.jsx](../src/components/PhotoRenderer/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/PhotoRenderer/photorenderer.css](../src/components/PhotoRenderer/photorenderer.css) | `--z-editor` |
| [src/components/Player/player.css](../src/components/Player/player.css) | `--z-above`, `--z-active`, `--z-control`, `--z-drag`, `--z-label` |
| [src/components/Player/playerendscreen.css](../src/components/Player/playerendscreen.css) | `--z-above`, `--z-control`, `--z-drag`, `--z-label` |
| [src/components/SavedPaletteEditorModal/index.jsx](../src/components/SavedPaletteEditorModal/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/SearchColor/search.css](../src/components/SearchColor/search.css) | `--z-above` |
| [src/components/ShareActions/shareactions.css](../src/components/ShareActions/shareactions.css) | `--z-picker` |
| [src/components/StickyPaletteBar/stickypalette.css](../src/components/StickyPaletteBar/stickypalette.css) | `--z-label`, `--z-nav` |
| [src/components/SwatchCard/index.jsx](../src/components/SwatchCard/index.jsx) | `--z-above`, `--z-control`, `--z-label` |
| [src/components/SwatchCard/swatchcard.css](../src/components/SwatchCard/swatchcard.css) | `--z-above`, `--z-control`, `--z-label` |
| [src/components/Swatches/swatches.css](../src/components/Swatches/swatches.css) | `--z-above`, `--z-active`, `--z-base`, `--z-control`, `--z-label` |
| [src/components/TagMultiSelect/index.jsx](../src/components/TagMultiSelect/index.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/TagMultiSelect/tagmultiselect.css](../src/components/TagMultiSelect/tagmultiselect.css) | `--z-dropdown` |
| [src/components/Toast/Toast.jsx](../src/components/Toast/Toast.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/components/Toast/toast.css](../src/components/Toast/toast.css) | `--z-toast` |
| [src/components/Viewers/appliedpaletteviewer.css](../src/components/Viewers/appliedpaletteviewer.css) | `--z-control`, `--z-dialog`, `--z-editor` |
| [src/components/WorkingBar/workingbar.css](../src/components/WorkingBar/workingbar.css) | `--z-nav` |
| [src/components/YoutubePlayer/youtube-player.css](../src/components/YoutubePlayer/youtube-player.css) | `--z-control` |
| [src/helpers/armClickShield.js](../src/helpers/armClickShield.js) | `--z-shield` |
| [src/hooks/useAnchoredPopup.js](../src/hooks/useAnchoredPopup.js) | Shared anchored menu geometry |
| [src/hooks/useOverlayLifecycle.js](../src/hooks/useOverlayLifecycle.js) | Shared overlay lifecycle and portal ownership |
| [src/layout/AppLayout/applayout.css](../src/layout/AppLayout/applayout.css) | `--z-above` |
| [src/layout/NavBarLayout/index.jsx](../src/layout/NavBarLayout/index.jsx) | `--z-nav` |
| [src/layout/NavBarLayout/navbarlayout.css](../src/layout/NavBarLayout/navbarlayout.css) | `--z-nav` |
| [src/layout/StickyToolbar/index.jsx](../src/layout/StickyToolbar/index.jsx) | `--z-sticky` |
| [src/layout/StickyToolbar/stickytoolbar.css](../src/layout/StickyToolbar/stickytoolbar.css) | `--z-sticky` |
| [src/main.jsx](../src/main.jsx) | Single root import |
| [src/pages/AdminArticlesPage/admin-articles.css](../src/pages/AdminArticlesPage/admin-articles.css) | `--z-above`, `--z-dialog` |
| [src/pages/AdminAssetLibraryPage/admin-asset-library.css](../src/pages/AdminAssetLibraryPage/admin-asset-library.css) | `--z-above`, `--z-dialog` |
| [src/pages/AdminClientsPage/admin-clients.css](../src/pages/AdminClientsPage/admin-clients.css) | `--z-above`, `--z-drawer`, `--z-label` |
| [src/pages/AdminCtasPage/admin-ctas.css](../src/pages/AdminCtasPage/admin-ctas.css) | `--z-above`, `--z-dialog` |
| [src/pages/AdminLandingPagesPage/admin-landing-pages.css](../src/pages/AdminLandingPagesPage/admin-landing-pages.css) | `--z-above` |
| [src/pages/AdminPalettesPage/PalettePhotosDrawer.jsx](../src/pages/AdminPalettesPage/PalettePhotosDrawer.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/pages/AdminPalettesPage/admin-palettes.css](../src/pages/AdminPalettesPage/admin-palettes.css) | `--z-editor` |
| [src/pages/AdminPalettesPage/palette-photos-drawer.css](../src/pages/AdminPalettesPage/palette-photos-drawer.css) | `--z-editor` |
| [src/pages/AdminPhotoLibraryPage/admin-photo-library.css](../src/pages/AdminPhotoLibraryPage/admin-photo-library.css) | `--z-editor`, `--z-label` |
| [src/pages/AdminPhotoLibraryToolsPage/admin-photo-library-tools.css](../src/pages/AdminPhotoLibraryToolsPage/admin-photo-library-tools.css) | `--z-editor` |
| [src/pages/AdminPhotoPreviewPage/photopreview.css](../src/pages/AdminPhotoPreviewPage/photopreview.css) | `--z-drawer`, `--z-nav` |
| [src/pages/AdminPlayerExperiencesPage/admin-player-experiences.css](../src/pages/AdminPlayerExperiencesPage/admin-player-experiences.css) | `--z-dialog` |
| [src/pages/AdminPlayerPage/admin-player.css](../src/pages/AdminPlayerPage/admin-player.css) | `--z-control` |
| [src/pages/AdminPlaylistsPage/PlaylistSlideDragRow.jsx](../src/pages/AdminPlaylistsPage/PlaylistSlideDragRow.jsx) | `--z-drag` |
| [src/pages/AdminPlaylistsPage/admin-playlists.css](../src/pages/AdminPlaylistsPage/admin-playlists.css) | `--z-drag` |
| [src/pages/AdminProjectPage/ProjectPhotos.jsx](../src/pages/AdminProjectPage/ProjectPhotos.jsx) | Shared lifecycle / compatibility / portal integration |
| [src/pages/AdminProjectPage/admin-project.css](../src/pages/AdminProjectPage/admin-project.css) | `--z-label` |
| [src/pages/AdminPubPage/PubAssetsTable.jsx](../src/pages/AdminPubPage/PubAssetsTable.jsx) | `--z-dropdown` |
| [src/pages/AdminPubPage/PubMusicEditor.jsx](../src/pages/AdminPubPage/PubMusicEditor.jsx) | `--z-editor` |
| [src/pages/AdminRexConversionPage/AdminRexConversionPage.css](../src/pages/AdminRexConversionPage/AdminRexConversionPage.css) | `--z-above`, `--z-drag` |
| [src/pages/AdminSavedPalettesPage/admin-saved-palettes.css](../src/pages/AdminSavedPalettesPage/admin-saved-palettes.css) | `--z-critical`, `--z-dialog`, `--z-editor` |
| [src/pages/AdminUserEventsPage/admin-user-events.css](../src/pages/AdminUserEventsPage/admin-user-events.css) | `--z-above` |
| [src/pages/AnalysisPage/analysispage.css](../src/pages/AnalysisPage/analysispage.css) | `--z-label` |
| [src/pages/BrowsePalettesPage/browse-palettes.css](../src/pages/BrowsePalettesPage/browse-palettes.css) | `--z-sticky` |
| [src/pages/ColorDetailPage/detailpage.css](../src/pages/ColorDetailPage/detailpage.css) | `--z-above`, `--z-control`, `--z-label` |
| [src/pages/ColorDetailPage/index.jsx](../src/pages/ColorDetailPage/index.jsx) | `--z-control` |
| [src/pages/FriendsEnterPage/friends.css](../src/pages/FriendsEnterPage/friends.css) | `--z-nav`, `--z-sticky` |
| [src/pages/HireTerryPage/request-playlist.css](../src/pages/HireTerryPage/request-playlist.css) | `--z-dialog` |
| [src/pages/MyPalettePage/mypalette.css](../src/pages/MyPalettePage/mypalette.css) | `--z-back`, `--z-dialog`, `--z-drawer`, `--z-fullscreen`, `--z-nav`, `--z-sticky` |
| [src/pages/PaletteTranslationPage/palette-translation.css](../src/pages/PaletteTranslationPage/palette-translation.css) | `--z-drag` |
| [src/pages/PlayerPage/playerpage.css](../src/pages/PlayerPage/playerpage.css) | `--z-control`, `--z-fullscreen` |
| [src/player-main.jsx](../src/player-main.jsx) | Single root import |
| [src/styles/ZIndex.css](../src/styles/ZIndex.css) | Global layer definitions |
| [src/styles/global.css](../src/styles/global.css) | `--z-nav` |
| [tests/overlays/fixture.jsx](../tests/overlays/fixture.jsx) | Validation fixture / runner |
| [tests/overlays/index.html](../tests/overlays/index.html) | Validation fixture / runner |
