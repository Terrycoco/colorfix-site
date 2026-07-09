import "./brand-bumper-logo.css";

export default function BrandBumperLogo({
  className = "",
  signatureProgress = null,
  style = {},
}) {
  const controlled = signatureProgress !== null
    && signatureProgress !== undefined
    && Number.isFinite(Number(signatureProgress));
  const progress = controlled ? clamp01(Number(signatureProgress)) : null;
  const signatureStyle = controlled
    ? {
        opacity: progress <= 0 ? 0 : 1,
        "--brand-bumper-signature-cover-x": `${Math.round(progress * 105)}%`,
      }
    : undefined;
  const rootClass = [
    "brand-bumper-logo",
    controlled ? "is-controlled" : "is-css-animated",
    className,
  ].filter(Boolean).join(" ");

  return (
    <div className={rootClass} style={style} aria-label="ColorFix by Terry">
      <span className="brand-bumper-logo__main" aria-hidden="true">
        <span className="brand-bumper-logo__color">Color</span>
        <span className="brand-bumper-logo__fix">Fix</span>
      </span>
      <span className="brand-bumper-logo__by" aria-hidden="true" style={signatureStyle}>
        <span>by</span>
        <span>Terry</span>
      </span>
    </div>
  );
}

function clamp01(value) {
  if (!Number.isFinite(value)) return 0;
  return Math.max(0, Math.min(1, value));
}
