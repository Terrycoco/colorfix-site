import ClientPaletteViewer from "@components/Viewers/PainterPaletteViewer";

const testMeta = {
  source: "saved",
  palette_viewer_key: "client",
  template_key: "client",
  id: 9001,
  hash: "client-viewer-test",
  title: "Foothill Salmon Fix -- Final Palette",
  display_title: "Foothill Salmon Fix -- Final Palette",
  kicker_text: "Warm exterior refresh",
  notes:
    "A softer field color calms the front elevation, while muted green trim and a grounded door color keep the house connected to the foothills.",
  cta_label: "Replay Final Transformation",
  playlist_url: "/p/canyon-colorfix?demo=1",
  photo_url: svgPhoto({
    title: "Final rendering",
    subtitle: "Warm body, green trim, soft charcoal accents",
    background: "#d8cbb8",
    foreground: "#4a5750",
    accent: "#6b5f4f",
  }),
  photo_alt: "Mock final exterior rendering",
  inset_photos: [
    {
      url: svgPhoto({
        title: "Before",
        subtitle: "Existing salmon body color",
        background: "#c78573",
        foreground: "#f0e9df",
        accent: "#3f4743",
      }),
      alt_text: "Mock before exterior",
      caption: "Before",
    },
    {
      url: svgPhoto({
        title: "Detail",
        subtitle: "Entry color relationship",
        background: "#a79c8c",
        foreground: "#434b45",
        accent: "#71604f",
      }),
      alt_text: "Mock entry detail",
      caption: "Entry detail",
    },
  ],
  palette_type: "exterior",
  og_image_url: "",
  set_id: 12,
};

const testClientView = {
  propertyName: "Biane Winery",
  projectName: "Speakeasy Exterior",
  address: "10013 8th Street, Unit T, Rancho Cucamonga, CA 91740",
  schemeTitle: "Foothill Salmon Fix -- Final Palette",
  conceptTitle: "Muted, grounded, and easier on the architecture",
  preparedFor: "Terry Marr",
  issuedLabel: "Test issue - August 6, 2026",
  designDirection:
    "This version keeps the warmth of the existing building but removes the harsh salmon read. The palette should feel quieter from the street, clearer at the entry, and more intentional around the trim transitions.",
};

const testSwatches = [
  {
    id: 661,
    name: "Equestrian",
    code: "DET661",
    brand: "Dunn-Edwards",
    brand_name: "Dunn-Edwards",
    hex6: "786f5f",
    role: "front door",
    sheen: "Satin/Lo-Sheen",
    note: "Use on the main entry door and side service door. Confirm existing hardware clearance before painting.",
  },
  {
    id: 6265,
    name: "Moss Covered",
    code: "DE6265",
    brand: "Dunn-Edwards",
    brand_name: "Dunn-Edwards",
    hex6: "454f49",
    role: "body",
    sheen: "Flat",
    note: "Primary stucco body. Painter should brush test near the stone returns before full rollout.",
  },
  {
    id: 6265,
    name: "Moss Covered",
    code: "DE6265",
    brand: "Dunn-Edwards",
    brand_name: "Dunn-Edwards",
    hex6: "454f49",
    role: "garage-facing side wall",
    sheen: "Flat",
    note: "Continue body color around side wall for a cleaner mass.",
  },
  {
    id: 641,
    name: "Distillery",
    code: "DEBN41",
    brand: "Dunn-Edwards",
    brand_name: "Dunn-Edwards",
    hex6: "5f554c",
    role: "fascia",
    sheen: "Eggshell",
    note: "Keep fascia crisp at gutter line. Do not wrap onto underside unless confirmed on site.",
  },
  {
    id: 651,
    name: "Chateau Gray",
    code: "DEGR51",
    brand: "Dunn-Edwards",
    brand_name: "Dunn-Edwards",
    hex6: "6d7372",
    role: "posts, chimney",
    sheen: "Flat",
    note: "Use on posts and chimney only. This is the cooler balancing note in the palette.",
  },
  {
    id: 772,
    name: "Whisper White",
    code: "DEW340",
    brand: "Dunn-Edwards",
    brand_name: "Dunn-Edwards",
    hex6: "f1eee6",
    role: "window trim",
    sheen: "Semi-Gloss",
    note: "Use sparingly on window trim where a cleaner edge is needed.",
  },
];

export default function ClientViewerTestPage() {
  return (
    <ClientPaletteViewer
      meta={testMeta}
      clientView={testClientView}
      swatches={testSwatches}
      showBackButton={false}
      showShare={true}
      playlistUrl={testMeta.playlist_url}
      shareUrl="/test/client-viewer"
      onSendToPainter={() => {
        window.alert("Painter handoff action placeholder");
      }}
    />
  );
}

function svgPhoto({ title, subtitle, background, foreground, accent }) {
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800">
      <rect width="1200" height="800" fill="${background}" />
      <rect x="0" y="560" width="1200" height="240" fill="#d6d0c2" />
      <path d="M155 480 L600 170 L1045 480 Z" fill="${foreground}" />
      <rect x="235" y="440" width="730" height="240" rx="8" fill="#eee9dc" />
      <rect x="520" y="510" width="150" height="170" rx="4" fill="${accent}" />
      <rect x="310" y="500" width="140" height="95" rx="4" fill="#c8d4d0" />
      <rect x="750" y="500" width="140" height="95" rx="4" fill="#c8d4d0" />
      <rect x="235" y="420" width="730" height="34" fill="${foreground}" opacity="0.9" />
      <text x="72" y="96" fill="#1f2422" font-family="Arial, Helvetica, sans-serif" font-size="52" font-weight="700">${escapeXml(title)}</text>
      <text x="76" y="150" fill="#3f4643" font-family="Arial, Helvetica, sans-serif" font-size="28">${escapeXml(subtitle)}</text>
    </svg>`;
  return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
}

function escapeXml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}
