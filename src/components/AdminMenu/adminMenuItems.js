export const adminMenuItems = [
  {
    label: "Color DB",
    items: [
      { label: "Colors", href: "/admin/colors" },
      { label: "Categories", href: "/admin/categories" },
      { label: "Supercats", href: "/admin/supercats" },
     { label: "Friends", href: "/admin/friends" },
      { label: "Missing Chips", href: "/admin/missing-chips" },
      { label: "LRV Editor", href: "/admin/lrv-editor" },
      { label: "Color Filters", href: "/admin/filters" },
      
    ],
  },
  {
    label: "Site",
    items: [
          { label: "Photo Library", href: "/admin/photo-library" },
      { label: "Articles", href: "/admin/articles" },
     { label: "Kickers", href: "/admin/kickers" },
      { label: "CTAs", href: "/admin/ctas" },
      { label: "Projects", href: "/admin/projects" },
      { label: "QR Sheets", href: "/admin/qr-sheets" },
      { label: "Search Presets", href: "/admin/search-presets" },
      { label: "Gallery Builder", href: "/admin/sql" },
      { label: "Gallery Items", href: "/admin/items" },
      { label: "Hire Terry", href: "/hire-terry"},

      
    ],
  },
  {
    label: "Outreach",
    items: [
      { label: "Clients", href: "/admin/clients" },
      { label: "Email Templates", href: "/admin/email-templates" },
      { label: "Share", href: "/admin/share" },
    ],
  },
  {
    label: "Assets",
    items: [
      { label: "Asset Library", href: "/admin/library" },
      { label: "Creator", href: "/admin/asset-creators" },
      { label: "Publisher", href: "/admin/publisher" },
      { label: "Analytics", href: "/admin/asset-analytics" },
      { label: "Pinterest Publisher", href: "/admin/pinterest-publisher" },
    ],
  },
  {
    label: "Palettes",
    items: [
      { label: "Saved Palettes", href: "/admin/saved-palettes" },
      { label: "Viewer Setup", href: "/admin/palette-photos" },
    ],
  },
  
 

  {
    label: "Player",
    items: [
     { label: "All Playlists", href: "/picker?psi=1", adminExitPath: "/admin/" },
      { label: "Upload Photos", href: "/admin/upload-photo" },
      { label: "Playlist Instances", href: "/admin/playlist-instances" },
      { label: "PI Sets", href: "/admin/playlist-instance-sets" },
      { label: "Playlists", href: "/admin/playlists" },
      { label: "Presenter", href: "/admin/player-presenter" },
      { label: "Player Preview", href: "/admin/player-preview/1" },
    ],
  },
  {
    label: "HOA",
    items: [
      { label: "HOAs", href: "/admin/hoas" },
      {label: "HOA Landing", href: "/hoa"},
      { label: "HOA Scheme Mapper", href: "/admin/hoa-scheme-tester" },
      { label: "HOA Mask Tester", href: "/admin/hoa-mask-tester" },
    ],
  },
  {
    label: "Tools",
    items: [

      { label: "View Counts", href: "/admin/user-events" },
      { label: "Ideas/ToDos", href: "/admin/ideas" },
      { label: "File Locker", href: "/admin/file-locker" },
  
      { label: "Photo Library Tools", href: "/admin/photo-library-tools" },



    ],
  },
];
