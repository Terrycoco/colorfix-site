import { buildImageUrl } from "@helpers/assetImage";
import "./ArticleRoomStudy.css";

const paragraphs = (value) => String(value ?? "")
  .split(/\n\s*\n/)
  .map((paragraph) => paragraph.trim())
  .filter(Boolean);

/** Editorial rendering of one existing image_text section. */
export default function ArticleRoomStudy({ section, reverse = false }) {
  if (!section) return null;
  const photo = section.asset;
  const src = photo?.optimized_urls?.["1200"] ||
    photo?.optimized_urls?.["1600"] ||
    (photo?.rel_path ? buildImageUrl(photo.rel_path, photo.updated_at || null) : "");
  const body = paragraphs(section.body);

  return (
    <section className={`cf-article-room${reverse ? " cf-article-room--reversed" : ""}`} aria-label={section.heading || "Room study"}>
      <div className="cf-article-room__inner">
        <figure className="cf-article-room__figure">
          {src ? (
            <img
              src={src}
              alt={photo?.alt_text || photo?.title || section.heading || "Room study"}
              loading="lazy"
              decoding="async"
            />
          ) : (
            <div className="cf-article-room__image-missing" role="img" aria-label="Room photograph unavailable" />
          )}
          {section.caption ? (
            <figcaption className="cf-article-room__caption">{section.caption}</figcaption>
          ) : null}
        </figure>
        <div className="cf-article-room__copy">
          {section.heading ? <h2 className="cf-article-room__heading">{section.heading}</h2> : null}
          {body.map((paragraph, index) => (
            <p className="cf-article-room__paragraph" key={index}>{paragraph}</p>
          ))}
        </div>
      </div>
    </section>
  );
}
