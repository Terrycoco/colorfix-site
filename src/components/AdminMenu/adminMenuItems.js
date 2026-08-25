export const adminMenuItems = [
  {
    label: "Color DB",
    items: [
      { label: "Colors", href: "/admin/colors" },
      { label: "Brands" },
      { label: "Categories", href: "/admin/categories" },
      { label: "Supercats", href: "/admin/supercats" },
      { label: "Friends", href: "/admin/friends" },
      { label: "Color Filters", href: "/admin/filters" },
      { label: "Missing Chips", href: "/admin/missing-chips" },
      { label: "LRV Editor", href: "/admin/lrv-editor" },
    ],
  },
  {
    label: "Library",
    items: [
      { label: "Photo Library", href: "/admin/photo-library" },
      { label: "Asset Library", href: "/admin/library" },
      { label: "Photo Collections" },
    ],
  },
  {
    label: "Clients & Projects",
    items: [
      { label: "Projects", href: "/admin/projects" },
      { label: "Clients", href: "/admin/clients" },
      { label: "Properties", href: "/admin/properties" },
      { label: "Email Templates", href: "/admin/email-templates" },
      { label: "Share", href: "/admin/share" },
    ],
  },
  {
    label: "Palettes",
    items: [
      { label: "Saved Palettes", href: "/admin/saved-palettes" },
      { label: "Palette Viewers", href: "/admin/palette-viewers" },
      { label: "Palette Viewer Setup", href: "/admin/palette-photos" },
    ],
  },
  {
    label: "Player",
    items: [
      { label: "All Playlists", href: "/picker?psi=1", adminExitPath: "/admin/" },
      { label: "Playlists", href: "/admin/playlists" },
      { label: "Playlist Instances", href: "/admin/playlist-instances" },
      { label: "Sets", href: "/admin/playlist-instance-sets" },
      { label: "Player Experiences", href: "/admin/player-experiences" },
      { label: "Kickers", href: "/admin/kickers" },
      { label: "CTAs", href: "/admin/ctas" },
      { label: "CTA Pages", href: "/admin/cta-pages" },
    ],
  },
  {
    label: "Publishing",
    items: [
      { label: "PUB Admin", href: "/admin/pub" },
      { label: "PUB Info", href: "/pub-info" },
      { label: "Privacy Policy", href: "/privacy" },
    ],
  },
  {
    label: "Site",
    items: [
      { label: "Articles", href: "/admin/articles" },
      { label: "Gallery Builder", href: "/admin/sql" },
      { label: "Gallery Items", href: "/admin/items" },
      { label: "Search Presets", href: "/admin/search-presets" },
      { label: "Hire Terry", href: "/hire-terry" },
      { label: "QR Sheets", href: "/admin/qr-sheets" },
    ],
  },
  {
    label: "Tools",
    items: [
      { label: "Analytics", href: "/admin/analytics" },
      { label: "Ideas / To-Dos", href: "/admin/ideas" },
      { label: "REX Conversion", href: "/admin/rex-conversion" },
      { label: "Photo Audit", href: "/admin/photo-library-tools" },
      { label: "Marketing", href: "/admin/marketing" },
    ],
  },
];
