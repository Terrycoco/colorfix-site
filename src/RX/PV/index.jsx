import PaletteViewer from "@components/Viewers/PaletteViewer";
import ConceptPaletteViewer from "@components/Viewers/ConceptPaletteViewer";
import ClientPaletteViewer from "@components/Viewers/ClientPaletteViewer";
import PainterPaletteViewer from "@components/Viewers/PainterPaletteViewer";

export default function PV({
  viewer,
  experienceKey = "",
  showBackButton = false,
  onBack,
}) {
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

  const navigationProps = {
    showBackButton,
    onBack,
  };

  switch (experience) {
    case "concept":
      return <ConceptPaletteViewer {...viewer} {...navigationProps} />;

    case "client":
      return <ClientPaletteViewer {...viewer} {...navigationProps} />;

    case "painter":
      return <PainterPaletteViewer {...viewer} {...navigationProps} />;

    case "public":
    case "full_palette":
    default:
      return <PaletteViewer {...viewer} {...navigationProps} />;
  }
}
