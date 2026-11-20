import { useState } from "react";
import { buildImageUrl } from "@helpers/assetImage";
import "./ArticleRoomStudy.css";

const paragraphs = (value) => String(value ?? "")
  .split(/\n\s*\n/)
  .map((paragraph) => paragraph.trim())
  .filter(Boolean);

/** Editorial rendering of one existing image_text section. */
export default function ArticleRoomStudy({ section, reverse = false }) {
  const [loadedOrientation, setLoadedOrientation] = useState(null);
  if (!section) return null;
  const photo = section.asset;
  const src = photo?.optimized_urls?.["1200"] ||
    photo?.optimized_urls?.["1600"] ||
    (photo?.rel_path ? buildImageUrl(photo.rel_path, photo.updated_at || null) : "");
  const body = paragraphs(section.body);
  const photoWidth = Number(photo?.width ?? photo?.image_width ?? photo?.pixel_width ?? photo?.w ?? 0);
  const photoHeight = Number(photo?.height ?? photo?.image_height ?? photo?.pixel_height ?? photo?.h ?? 0);
  const isPortrait = loadedOrientation ?? (photoWidth > 0 && photoHeight > 0 && photoHeight > photoWidth);

  return (
    <section className={`cf-article-room${reverse ? " cf-article-room--reversed" : ""}`} aria-label={section.heading || "Room study"}>
      <div className="cf-article-room__inner">
        <figure className={`cf-article-room__figure${isPortrait ? " cf-article-room__figure--portrait" : ""}`}>
          {src ? (
            <img
              src={src}
              alt={photo?.alt_text || photo?.title || section.heading || "Room study"}
              loading="lazy"
              decoding="async"
              onLoad={(event) => {
                const image = event.currentTarget;
                if (image.naturalWidth > 0 && image.naturalHeight > 0) {
                  setLoadedOrientation(image.naturalHeight > image.naturalWidth);
                }
              }}
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
