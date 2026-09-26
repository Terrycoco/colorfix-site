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
    label: "REX / PUB / ANA",
    items: [
        { label: "REX Reservations", href: "/admin/rex" },
        { label: "REX Admin", href: "/admin/rexrelationships"},
        { label: "PUB Admin", href: "/admin/pub" },
        { label: "ANA Admin", href: "/admin/analytics" },
   
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
      { label: "Project", href: "/admin/project" },
      { label: "Clients", href: "/admin/clients" },
      { label: "Properties", href: "/admin/properties" },
      { label: "Doc Templates", href: "/admin/templates" },
      { label: "Share", href: "/admin/share" },
    ],
  },
  {
    label: "Palettes",
    items: [
      { label: "Saved Palettes", href: "/admin/palettes" },
      { label: "Palette Viewers", href: "/admin/palette-viewers" },
      { label: "Palette Viewer Setup", href: "/admin/palette-photos" },
    ],
  },
  {
    label: "Player",
    items: [
      { label: "All Playlists", href: "/picker?set=1", adminExitPath: "/admin/" },
      { label: "Playlists", href: "/admin/playlists" },
      { label: "Sets", href: "/admin/playlist-sets" },
      { label: "Player Experiences", href: "/admin/player-experiences" },
      { label: "Kickers", href: "/admin/kickers" },
      { label: "CTAs", href: "/admin/ctas" },
      { label: "CTA Pages", href: "/admin/cta-pages" },
    ],
  },
 
  {
    label: "Site",
    items: [

      { label: "Contact Terry", href: "/hire-terry" },
      { label: "QR Sheets", href: "/admin/qr-sheets" },
      { label: "PUB Info", href: "/pub-info" },
      { label: "Privacy Policy", href: "/privacy" },
      { label: "Terms", href:"/terms"},
    ],
  },
  {
    label: "Tools",
    items: [
        { label: "Article Editor", href: "/admin/articles" },
      { label: "Gallery Builder", href: "/admin/sql" },
      { label: "Gallery Items", href: "/admin/items" },
      { label: "Ideas / To-Dos", href: "/admin/ideas" },
      { label: "Photo Audit", href: "/admin/photo-library-tools" },
      { label: "Marketing", href: "/admin/marketing" },
    ],
  },
];
