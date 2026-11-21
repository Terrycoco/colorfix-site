import { buildImageUrl } from "@helpers/assetImage";
import { editorialInline, editorialParagraphs } from "./ArticleText";
import "./ArticleGuide.css";

function ArticleGuidePhoto({ section, label }) {
  const asset = section?.asset;
  const src = asset?.optimized_urls?.["1200"] ||
    asset?.optimized_urls?.["1600"] ||
    (asset?.rel_path ? buildImageUrl(asset.rel_path, asset.updated_at || null) : "");
  return (
    <div className="cf-article-comparison__panel">
      <p className="cf-article-comparison__label">{label}</p>
      <figure className="cf-article-comparison__figure">
        {src ? <img src={src} alt={asset?.alt_text || asset?.title || section?.caption || label} loading="lazy" decoding="async" /> :
          <div className="cf-article-comparison__missing" role="img" aria-label="Photograph unavailable" />}
        {section?.caption ? <figcaption className="cf-article-comparison__caption">{section.caption}</figcaption> : null}
      </figure>
      <div className="cf-article-comparison__copy">
        {editorialParagraphs(section?.body).map((p, i) =>
          <p key={i}>{editorialInline(p)}</p>
        )}
      </div>
    </div>
  );
}

/** A guide's paired, sequential image_text sections. */
export default function ArticleComparison({ before, after }) {
  if (!before || !after) return null;
  return (
    <section className="cf-article-comparison" aria-label={before.heading || "Photo comparison"}>
      <div className="cf-article-comparison__inner">
        {before.heading ? <h2 className="cf-article-comparison__heading">{before.heading}</h2> : null}
        <div className="cf-article-comparison__grid">
          <ArticleGuidePhoto section={before} label="Before" />
          <ArticleGuidePhoto section={after} label="After" />
        </div>
      </div>
    </section>
  );
}

export function ArticleGuideImageText({ section }) {
  const asset = section?.asset;
  const src = asset?.optimized_urls?.["1200"] ||
    asset?.optimized_urls?.["1600"] ||
    (asset?.rel_path ? buildImageUrl(asset.rel_path, asset.updated_at || null) : "");
  return (
    <section className="cf-article-guide-single">
      <div className="cf-article-guide-single__inner">
        {section.heading ? <h2 className="cf-article-guide-single__heading">{section.heading}</h2> : null}
        <div className="cf-article-guide-single__grid">
          <figure className="cf-article-guide-single__figure">
            {src ? <img src={src} alt={asset?.alt_text || asset?.title || section.caption || "Article illustration"} loading="lazy" decoding="async" /> :
              <div className="cf-article-comparison__missing" role="img" aria-label="Photograph unavailable" />}
            {section.caption ? <figcaption className="cf-article-comparison__caption">{section.caption}</figcaption> : null}
          </figure>
          <div className="cf-article-guide-single__copy">
            {editorialParagraphs(section.body).map((p, i) => <p key={i}>{editorialInline(p)}</p>)}
          </div>
        </div>
      </div>
    </section>
  );
}
