import CTAButton from "./CTAButton";
import "./cta.css";

export default function CTALayout({
  ctas = [],
  layout = "stacked",
  onCtaClick,
}) {
  if (!ctas.length) return null;

  return (
    <div className={`cta-layout cta-layout--${layout}`}>
      {ctas.map((cta) => (
        <CTAButton
          key={cta.cta_id}
          cta={cta}
          onClick={onCtaClick}
        />
      ))}
    </div>
  );
}
