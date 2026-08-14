import { withSourceParam } from "@helpers/sourceParam";

export default function ViewerCtaButton({ label, url }) {
  const href = withSourceParam(String(url || "").trim());
  const text = String(label || "").trim();

  if (!href || !text) return null;

  const isHomeCta = href === "/";

  return (
    <a
      className={`apv-btn apv-btn--viewer-cta${isHomeCta ? " apv-btn--viewer-cta-home" : ""}`}
      href={href}
    >
      {isHomeCta ? (
        <>
          <span>See More on</span>
          <span className="apv-colorfix-wordmark" aria-label="ColorFix">
            <span>Color</span>
            <span className="apv-colorfix-wordmark__fix">Fix</span>
          </span>
        </>
      ) : (
        text
      )}
    </a>
  );
}
