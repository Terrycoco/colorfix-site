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
      {ctas.map((cta) => {
        if (!cta) return null;
        const isSpacer = cta.variant === "spacer" || cta.key === "spacer";
        if (isSpacer) {
          return <div key={cta.cta_id} className="cta-spacer" aria-hidden="true" />;
        }

        return (
          <CTAButton
            key={cta.cta_id}
            cta={cta}
            onClick={onCtaClick}
          />
        );
      })}
    </div>
  );
}
