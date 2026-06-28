import CTAIcon from '@components/Icons/CTAIcons';
import colorfixLightBgUrl from "../../assets/brand/colorfix_lightbg.png";
import "./cta.css";

export default function CTAButton({ cta, onClick, disabled = false }) {
  if (!cta) return null;
  if (cta.enabled === false) return null;

  const variant = cta.variant || "secondary";
  const displayMode = cta.display_mode || "text"; // text | icon | both
  const hasIcon = Boolean(cta.icon);
  const isLink = variant === "link";
  const href = cta?.params?.url || cta?.href || cta?.url || "#";
  const isColorFixBrandCta = cta?.params?.brand === "colorfix";
  const usesLogoText = variant === "button-logo";
  const className = [
    "cta-button",
    `cta-button--${variant}`,
    `cta-button--${displayMode}`,
    isColorFixBrandCta ? "cta-button--brand-colorfix" : "",
    usesLogoText ? "cta-button--logo-text" : "",
  ].filter(Boolean).join(" ");

  const content = (
    <>
      {displayMode !== "text" && hasIcon && (
        <CTAIcon name={cta.icon} className="cta-button__icon" />
      )}

      {displayMode !== "icon" && (
        <span className="cta-button__label">
          {usesLogoText
            ? renderColorFixTextLabel(cta.label)
            : isColorFixBrandCta
              ? renderColorFixImageLabel(cta.label)
              : cta.label}
        </span>
      )}
    </>
  );

  if (isLink) {
    return (
      <a
        href={href}
        className={className}
        onClick={(event) => {
          if (onClick) {
            event.preventDefault();
            if (!disabled) onClick(cta);
          }
        }}
        aria-disabled={disabled ? "true" : undefined}
      >
        {content}
      </a>
    );
  }

  return (
    <button
      type="button"
      className={className}
      onClick={() => !disabled && onClick && onClick(cta)}
      disabled={disabled}
    >
      {content}
    </button>
  );
}

function renderColorFixTextLabel(label) {
  const text = String(label || "");
  const parts = text.split(/(ColorFix)/g);
  return parts.map((part, index) => {
    if (part !== "ColorFix") return part;
    return (
      <span key={`brand-${index}`} className="cta-button__logo-word" aria-label="ColorFix">
        <span className="cta-button__logo-word-color">Color</span>
        <span className="cta-button__logo-word-fix">Fix</span>
      </span>
    );
  });
}

function renderColorFixImageLabel(label) {
  const text = String(label || "");
  const parts = text.split(/(ColorFix)/g);
  return parts.map((part, index) => {
    if (part !== "ColorFix") return part;
    return (
      <img
        key={`brand-${index}`}
        src={colorfixLightBgUrl}
        alt="ColorFix"
        className="cta-button__brand-logo"
      />
    );
  });
}
