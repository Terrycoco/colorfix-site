import { useState } from "react";
import { buildImageUrl } from "@helpers/assetImage";
import "./ArticleHero.css";

const displayText = (value) => String(value ?? "").replace(/--/g, "—");
// The API supplies PHOTOS-authorized derivatives. Fall back to the existing
// URL only when the signing key has not been configured on the server.
const originalUrl = (photo) => photo?.rel_path ? buildImageUrl(photo.rel_path, photo.updated_at || null) : "";
const photoUrl = (photo, width) => photo?.optimized_urls?.[String(width)] || originalUrl(photo);
const heroSrcSet = (photo) => {
  const entries = [640, 960, 1600, 1920]
    .filter((width) => photo?.optimized_urls?.[String(width)])
    .map((width) => `${photo.optimized_urls[String(width)]} ${width}w`);
  return entries.length ? entries.join(", ") : undefined;
};
const CORNERS = ["top-left", "top-right", "bottom-left", "bottom-right"];

function parsePositions(value) {
  let supplied = value;
  if (typeof value === "string") {
    try { supplied = JSON.parse(value); } catch { supplied = {}; }
  }
  if (!supplied || typeof supplied !== "object" || Array.isArray(supplied)) supplied = {};
  const get = (key) => CORNERS.includes(supplied[key]) ? supplied[key] : "bottom-right";
  return { desktop: get("desktop"), tablet: get("tablet"), phone: get("phone") };
}

/** Article-level editorial opening; all later content is separate. */
export default function ArticleHero({ title, kicker, dek, room, painting, insetPositions, children = null }) {
  const [mainShape, setMainShape] = useState("landscape");
  const roomUrl = photoUrl(room, 1600);
  const paintingUrl = photoUrl(painting, 960);
  const positions = parsePositions(insetPositions);

  return (
    <header className="cf-article-hero">
      <div className="cf-article-hero__copy">
        {kicker ? <p className="cf-article-hero__eyebrow">{displayText(kicker)}</p> : null}
        <h1 className="cf-article-hero__title">{displayText(title)}</h1>
        {dek ? <p className="cf-article-hero__dek">{displayText(dek)}</p> : null}
        {children}
      </div>

      {roomUrl ? (
        <figure className={`cf-article-hero__visual cf-article-hero__visual--${mainShape}`}>
          <img
            className="cf-article-hero__room"
            src={roomUrl}
            srcSet={heroSrcSet(room)}
            sizes="100vw"
            alt={room?.alt_text || room?.title || "Featured interior design"}
            fetchPriority="high"
            onLoad={(event) => {
              const img = event.currentTarget;
              setMainShape(img.naturalHeight > img.naturalWidth ? "portrait" : "landscape");
            }}
          />
          {paintingUrl ? (
            <div
              className="cf-article-hero__inset"
              data-desktop={positions.desktop}
              data-tablet={positions.tablet}
              data-phone={positions.phone}
            >
              <span className="cf-article-hero__inset-label">Inspired by</span>
              <img
                className="cf-article-hero__painting"
                src={paintingUrl}
                alt={painting?.alt_text || painting?.title || "Inspirational painting"}
              />
            </div>
          ) : null}
        </figure>
      ) : null}
    </header>
  );
}
