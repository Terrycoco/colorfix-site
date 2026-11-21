import { buildImageUrl } from "@helpers/assetImage";
import { editorialInline, editorialParagraphs } from "./ArticleText";
import "./ArticleGuide.css";

/** Default standalone image section: full composition, restrained caption. */
export default function ArticleImage({ section, align = "right" }) {
  if (!section) return null;
  const asset = section.asset;
  const src = asset?.optimized_urls?.["1200"] ||
    asset?.optimized_urls?.["1600"] ||
    (asset?.rel_path ? buildImageUrl(asset.rel_path, asset.updated_at || null) : "");
  return (
    <section className={`cf-article-standalone cf-article-standalone--${align === "left" ? "left" : "right"}`}>
      <div className="cf-article-standalone__inner">
        {section.heading ? <h2 className="cf-article-standalone__heading">{section.heading}</h2> : null}
        <figure className="cf-article-standalone__figure">
          {src ? <img src={src} alt={asset?.alt_text || asset?.title || section.caption || section.heading || "Article photograph"} loading="lazy" decoding="async" /> :
            <div className="cf-article-comparison__missing" role="img" aria-label="Photograph unavailable" />}
          {section.caption ? <figcaption className="cf-article-comparison__caption">{section.caption}</figcaption> : null}
        </figure>
        {editorialParagraphs(section.body).length > 0 ? (
          <div className="cf-article-standalone__copy">
            {editorialParagraphs(section.body).map((text, i) => <p key={i}>{editorialInline(text)}</p>)}
          </div>
        ) : null}
      </div>
    </section>
  );
}
