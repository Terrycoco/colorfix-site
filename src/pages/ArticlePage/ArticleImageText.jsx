import { buildImageUrl } from "@helpers/assetImage";
import { editorialInline, editorialParagraphs } from "./ArticleText";
import "./ArticleImageText.css";

/** Default image_text section: a genuine editorial float, not two columns. */
export default function ArticleImageText({ section, floatSide = "right" }) {
  if (!section) return null;
  const asset = section.asset;
  const src = asset?.optimized_urls?.["1200"] ||
    asset?.optimized_urls?.["1600"] ||
    (asset?.rel_path ? buildImageUrl(asset.rel_path, asset.updated_at || null) : "");
  const body = editorialParagraphs(section.body);
  const side = floatSide === "left" ? "left" : "right";

  return (
    <section className="cf-article-flow">
      <div className="cf-article-flow__inner">
        {section.heading ? <h2 className="cf-article-flow__heading">{section.heading}</h2> : null}
        <div className="cf-article-flow__content">
          <figure className={`cf-article-flow__figure cf-article-flow__figure--${side}`}>
            {src ? (
              <img
                src={src}
                alt={asset?.alt_text || asset?.title || section.caption || section.heading || "Article photograph"}
                loading="lazy"
                decoding="async"
              />
            ) : (
              <div className="cf-article-flow__missing" role="img" aria-label="Photograph unavailable" />
            )}
            {section.caption ? <figcaption className="cf-article-flow__caption">{section.caption}</figcaption> : null}
          </figure>
          {body.map((paragraph, i) => (
            <p className="cf-article-flow__paragraph" key={i}>{editorialInline(paragraph)}</p>
          ))}
        </div>
      </div>
    </section>
  );
}
