import PaletteViewer from "@components/Viewers/PaletteViewer";
import ConceptPaletteViewer from "@components/Viewers/ConceptPaletteViewer";
import ClientPaletteViewer from "@components/Viewers/ClientPaletteViewer";
import PainterPaletteViewer from "@components/Viewers/PainterPaletteViewer";

export default function PV({ viewer, experienceKey = "" }) {
  if (!viewer) return null;

  const experience = String(
    experienceKey ||
      viewer?.experience_key ||
      viewer?.experienceKey ||
      viewer?.meta?.format ||
      "public"
  )
    .trim()
    .toLowerCase();

  switch (experience) {
    case "concept":
      return <ConceptPaletteViewer {...viewer} />;

    case "client":
      return <ClientPaletteViewer {...viewer} />;

    case "painter":
      return <PainterPaletteViewer {...viewer} />;

    case "public":
    case "full_palette":
    default:
      return <PaletteViewer {...viewer} />;
  }
}