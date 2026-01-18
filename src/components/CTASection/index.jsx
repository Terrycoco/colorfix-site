import CTALayout from "@components/cta/CTALayout";
import "./cta-section.css";

export default function CTASection({
  ctas = [],
  onCtaClick,
  layout = "stacked",
  className = "",
  align,
  theme,
  children = null,
}) {
  if (!ctas.length && !children) return null;

  const resolvedAlign = normalizeAlign(align) || getCtaAlign(ctas) || "left";
  const resolvedTheme = normalizeTheme(theme) || getCtaTheme(ctas);
  const themeClass = resolvedTheme === "dark" ? "cta-section--on-dark" : "";

  return (
    <div className={`cta-section cta-section--${resolvedAlign} ${themeClass} ${className}`.trim()}>
      <div className="cta-section__inner">
        {ctas.length > 0 && (
          <CTALayout layout={layout} ctas={ctas} onCtaClick={onCtaClick} />
        )}
        {children}
      </div>
    </div>
  );
}

function normalizeAlign(value) {
  if (!value) return "";
  const normalized = String(value).toLowerCase();
  if (normalized === "center" || normalized === "left" || normalized === "right") return normalized;
  return "";
}

function normalizeTheme(value) {
  if (!value) return "";
  const normalized = String(value).toLowerCase();
  if (normalized === "dark" || normalized === "light") return normalized;
  return "";
}

function getCtaAlign(ctas) {
  for (const cta of ctas || []) {
    const align = cta?.params?.align || cta?.align;
    const normalized = normalizeAlign(align);
    if (normalized) return normalized;
  }
  return "";
}

function getCtaTheme(ctas) {
  for (const cta of ctas || []) {
    const theme = cta?.params?.theme || cta?.theme;
    const normalized = normalizeTheme(theme);
    if (normalized) return normalized;
  }
  return "";
}
