import "./ArticleFinalThoughts.css";

/** Human-authored conclusion stored as a standard article text section. */
export default function ArticleFinalThoughts({ section, adminPreview = false }) {
  const body = String(section?.body ?? "").trim();
  if (!body && !adminPreview) return null;

  const paragraphs = body.split(/\n\s*\n/).map((text) => text.trim()).filter(Boolean);
  return (
    <section className="cf-article-final" aria-labelledby="cf-article-final-heading">
      <div className="cf-article-final__inner">
        <h2 id="cf-article-final-heading" className="cf-article-final__heading">
          {section?.heading || "Final Thoughts"}
        </h2>
        {paragraphs.length ? (
          paragraphs.map((paragraph, index) => (
            <p className="cf-article-final__paragraph" key={index}>{paragraph}</p>
          ))
        ) : (
          <p className="cf-article-final__placeholder">Final Thoughts needs your commentary.</p>
        )}
      </div>
    </section>
  );
}
