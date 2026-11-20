import { buildImageUrl } from "@helpers/assetImage";
import ArticleIntroduction from "./ArticleIntroduction";
import "./ArticleInspiration.css";

const paragraphs = (value) => String(value ?? "")
  .split(/\n\s*\n/)
  .map((part) => part.trim())
  .filter(Boolean);

/** Presents two existing article sections as one editorial opening. */
export default function ArticleInspiration({ introduction, section }) {
  const image = section?.asset;
  // Match the hero inset's 960px URL to reuse one downloaded painting.
  const src = image?.optimized_urls?.["960"] ||
    (image?.rel_path ? buildImageUrl(image.rel_path, image.updated_at || null) : "");
  const body = paragraphs(section?.body);
  if (!introduction && !section) return null;

  return (
    <section className="cf-article-inspiration" aria-label="Introduction and inspiration">
      <div className="cf-article-inspiration__grid">
        <div className="cf-article-inspiration__prose">
          {introduction ? <ArticleIntroduction body={introduction} /> : null}
          {section ? (
            <div className="cf-article-inspiration__story">
              <h2 className="cf-article-inspiration__heading">{section.heading || "The Inspiration"}</h2>
              {body.map((text, index) => <p key={index}>{text}</p>)}
            </div>
          ) : null}
        </div>
        {src ? (
          <figure className="cf-article-inspiration__figure">
            <img src={src} alt={image?.alt_text || image?.title || section?.heading || "Inspiration artwork"} loading="eager" decoding="async" />
            {section?.caption ? <figcaption>{section.caption}</figcaption> : null}
          </figure>
        ) : null}
      </div>
    </section>
  );
}
