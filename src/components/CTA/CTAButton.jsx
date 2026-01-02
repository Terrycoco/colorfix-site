import CTAIcon from '@components/Icons/CTAIcons';
import "./cta.css";

export default function CTAButton({ cta, onClick, disabled = false }) {
  if (!cta) return null;
  if (cta.enabled === false) return null;

  const variant = cta.variant || "secondary";
  const displayMode = cta.display_mode || "text"; // text | icon | both
  const hasIcon = Boolean(cta.icon);

  return (
    <button
      type="button"
      className={`cta-button cta-button--${variant} cta-button--${displayMode}`}
      onClick={() => !disabled && onClick && onClick(cta)}
      disabled={disabled}
    >
      {displayMode !== "text" && hasIcon && (
        <CTAIcon name={cta.icon} className="cta-button__icon" />
      )}

      {displayMode !== "icon" && (
        <span className="cta-button__label">{cta.label}</span>
      )}
    </button>
  );
}