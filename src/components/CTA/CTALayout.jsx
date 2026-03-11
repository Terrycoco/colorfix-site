import CTAButton from "./CTAButton";
import ArticleLinkCta from "./ArticleLinkCta";
import WatchNextCta from "./WatchNextCta";
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

        const key = (cta?.key || cta?.action_key || cta?.action || "").toString().toLowerCase();
        if (key === "article_link") {
          return (
            <ArticleLinkCta
              key={cta.cta_id}
              cta={cta}
              onClick={onCtaClick}
            />
          );
        }
        if (key === "watch_next") {
          return (
            <WatchNextCta
              key={cta.cta_id}
              cta={cta}
              onClick={onCtaClick}
            />
          );
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
