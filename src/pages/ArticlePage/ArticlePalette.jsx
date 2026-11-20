import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import "./ArticlePalette.css";

const paragraphs = (value) => String(value ?? "")
  .split(/\n\s*\n/)
  .map((paragraph) => paragraph.trim())
  .filter(Boolean);

/** ARTICLE owns editorial placement; PALETTES/COLORS own the swatch data and UI. */
export default function ArticlePalette({ section }) {
  if (!section) return null;
  const swatches = Array.isArray(section.swatches) ? section.swatches : [];
  const brands = [...new Set(swatches.map((color) => String(color.brand_name || color.brand || "").trim()).filter(Boolean))];
  const brandNote = brands.length === 1
    ? `This study uses ${brands[0]} colors. Comparable colors can be selected from other paint brands.`
    : brands.length > 1
      ? "This study uses colors from multiple paint brands. Comparable colors can be selected from other manufacturers."
      : "";

  // ArtworkRecipe puts the method explanation first, then the color analysis.
  const [method, ...analysis] = paragraphs(section.body);

  return (
    <section className="cf-article-palette" aria-labelledby="cf-article-palette-title">
      <div className="cf-article-palette__inner">
        <div className="cf-article-palette__display">
          <div className="cf-article-palette__swatch-group">
          <div className="cf-article-palette__swatches" aria-label="Paint colors in this palette">
            {swatches.length ? swatches.map((color) => (
              <div className="cf-article-palette__swatch" key={color.id}>
                <PaletteSwatch color={color} />
              </div>
            )) : (
              <p className="cf-article-palette__empty">Palette swatches are not available.</p>
            )}
          </div>
          {brandNote ? <p className="cf-article-palette__brand-note">{brandNote}</p> : null}
          </div>
        </div>
        <div className="cf-article-palette__copy">
          <h2 className="cf-article-palette__heading" id="cf-article-palette-title">
            {section.heading || "The Palette"}
          </h2>
          {method ? <p className="cf-article-palette__paragraph">{method}</p> : null}
          {analysis.length > 0 ? (
            <div className="cf-article-palette__analysis">
              {analysis.map((paragraph, index) => (
                <p className="cf-article-palette__paragraph" key={index}>{paragraph}</p>
              ))}
            </div>
          ) : null}
        </div>
      </div>
    </section>
  );
}
